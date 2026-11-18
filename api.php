<?php
declare(strict_types=1);

// Wochenplaner backend API.
//   GET  api.php?week=YYYY-MM-DD          -> { days, notes, closed, revision }
//   GET  api.php?week=YYYY-MM-DD&revision_only=1 -> { revision }
//   POST api.php  { week, days, notes, closed, base_revision } -> { ok: true, revision }
//   GET  api.php?shitlist=1               -> { items, revision }  (global "Shit-Liste" backlog)
//   POST api.php  { type: "shitlist", items, base_revision } -> { ok: true, revision }  (saves the shit-list)
//
// One week is always read/written as a whole, mirroring how the frontend
// keeps the whole week in memory and saves it after every change. Writes use
// optimistic locking: the client must send the revision it last read, and the
// server rejects writes against a stale revision with HTTP 409.

header('Content-Type: application/json; charset=utf-8');

require __DIR__ . '/db.php';

const DAYS = ["Montag","Dienstag","Mittwoch","Donnerstag","Freitag","Samstag","Sonntag"];
const NOTE_SECTIONS = ["Diverses","Kaufen","Ausblick"];
const SLOTS_PER_DAY = 8;

class ConflictException extends Exception {
    public int $serverRevision;
    public array $serverState;
    public function __construct(int $revision, array $state, string $entity = 'week') {
        $this->serverRevision = $revision;
        $this->serverState = $state;
        parent::__construct("Conflict: {$entity} was modified elsewhere");
    }
}

function bad_request(string $msg): void {
    http_response_code(400);
    echo json_encode(['error' => $msg]);
    exit;
}

function server_error(string $msg): void {
    http_response_code(500);
    echo json_encode(['error' => $msg]);
    exit;
}

function valid_week(?string $week): bool {
    if ($week === null || $week === '') return false;
    $d = DateTime::createFromFormat('Y-m-d', $week);
    return $d !== false && $d->format('Y-m-d') === $week;
}

function sanitize_item($item): array {
    $item = is_array($item) ? $item : [];
    $text = (isset($item['text']) && is_string($item['text'])) ? $item['text'] : '';
    $date = (isset($item['date']) && is_string($item['date']) && $item['date'] !== '') ? $item['date'] : null;
    $time = (isset($item['time']) && is_string($item['time']) && $item['time'] !== '') ? $item['time'] : null;
    $done = !empty($item['done']);

    if ($date !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) $date = null;
    }
    if ($time !== null && !preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $time)) {
        $time = null;
    }
    return ['text' => $text, 'date' => $date, 'time' => $time, 'done' => $done];
}

function canonical_payload(array $payload): array {
    $days = is_array($payload['days'] ?? null) ? $payload['days'] : [];
    $notes = is_array($payload['notes'] ?? null) ? $payload['notes'] : [];
    $closed = !empty($payload['closed']) ? 1 : 0;

    $canonical = ['closed' => (bool)$closed, 'days' => [], 'notes' => []];
    foreach (DAYS as $day) {
        $slots = is_array($days[$day] ?? null) ? $days[$day] : [];
        $canonical['days'][$day] = [];
        for ($i = 0; $i < SLOTS_PER_DAY; $i++) {
            $item = sanitize_item($slots[$i] ?? null);
            $canonical['days'][$day][] = [
                'text' => $item['text'],
                'date' => $item['date'] ?? '',
                'time' => $item['time'] ?? '',
                'done' => (bool)$item['done'],
            ];
        }
    }
    foreach (NOTE_SECTIONS as $section) {
        $canonical['notes'][$section] = [];
        $items = is_array($notes[$section] ?? null) ? $notes[$section] : [];
        foreach ($items as $raw) {
            if (!is_array($raw)) continue;
            $item = sanitize_item($raw);
            $canonical['notes'][$section][] = [
                'text' => $item['text'],
                'date' => $item['date'] ?? '',
                'time' => $item['time'] ?? '',
                'done' => (bool)$item['done'],
            ];
        }
    }
    return $canonical;
}

function canonical_hash(array $payload): string {
    $canonical = canonical_payload($payload);
    $json = json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return hash('sha256', $json);
}

// Same per-item validation as the shit-list endpoint: non-string or oversize
// text gets a clean 400 instead of being silently coerced by sanitize_item.
function validate_payload_items(array $payload): void {
    foreach (['days', 'notes'] as $group) {
        $lists = is_array($payload[$group] ?? null) ? $payload[$group] : [];
        foreach ($lists as $list) {
            if (!is_array($list)) continue;
            validate_items($list);
        }
    }
}

// Per-item input validation shared by the week and shit-list saves: non-string
// or oversize text is rejected with a clean 400.
function validate_items(array $items): void {
    foreach ($items as $raw) {
        if (!is_array($raw)) continue;
        if (isset($raw['text']) && !is_string($raw['text'])) bad_request('Invalid item text (expected string).');
        if (isset($raw['date']) && !is_string($raw['date'])) bad_request('Invalid item date (expected string).');
        if (isset($raw['time']) && !is_string($raw['time'])) bad_request('Invalid item time (expected string).');
        $text = (isset($raw['text']) && is_string($raw['text'])) ? $raw['text'] : '';
        if (strlen($text) > 8000) bad_request('Item text too long (max 8000 bytes).');
    }
}

function load_week(PDO $pdo, string $week): array {
    $result = ['days' => [], 'notes' => [], 'closed' => false, 'revision' => 0];

    $stmt = $pdo->prepare('SELECT closed, revision, content_hash FROM weeks WHERE week_start = ?');
    $stmt->execute([$week]);
    $weekRow = $stmt->fetch();
    if ($weekRow) {
        $result['closed'] = (bool)$weekRow['closed'];
        $result['revision'] = (int)$weekRow['revision'];
    }

    foreach (DAYS as $day) {
        $result['days'][$day] = array_fill(0, SLOTS_PER_DAY, ['text' => '', 'date' => '', 'time' => '', 'done' => false]);
    }
    $stmt = $pdo->prepare('SELECT day_name, slot_index, text, item_date, item_time, done FROM day_slots WHERE week_start = ?');
    $stmt->execute([$week]);
    foreach ($stmt as $row) {
        if (!in_array($row['day_name'], DAYS, true)) continue;
        $idx = (int)$row['slot_index'];
        if ($idx < 0 || $idx >= SLOTS_PER_DAY) continue;
        $result['days'][$row['day_name']][$idx] = [
            'text' => $row['text'] ?? '',
            'date' => $row['item_date'] ?? '',
            'time' => $row['item_time'] ? substr((string)$row['item_time'], 0, 5) : '',
            'done' => (bool)$row['done'],
        ];
    }

    foreach (NOTE_SECTIONS as $section) {
        $result['notes'][$section] = [];
    }
    $stmt = $pdo->prepare('SELECT section, text, item_date, item_time, done FROM note_items WHERE week_start = ? ORDER BY section, position ASC');
    $stmt->execute([$week]);
    foreach ($stmt as $row) {
        if (!in_array($row['section'], NOTE_SECTIONS, true)) continue;
        $result['notes'][$row['section']][] = [
            'text' => $row['text'] ?? '',
            'date' => $row['item_date'] ?? '',
            'time' => $row['item_time'] ? substr((string)$row['item_time'], 0, 5) : '',
            'done' => (bool)$row['done'],
        ];
    }

    return $result;
}

function log_conflict(PDO $pdo, string $week, int $baseRevision, int $serverRevision, array $payload): void {
    $stmt = $pdo->prepare('INSERT INTO week_conflicts (week_start, base_revision, server_revision, payload) VALUES (?, ?, ?, ?)');
    $stmt->execute([$week, $baseRevision, $serverRevision, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
}

function save_week(PDO $pdo, string $week, array $payload, int $baseRevision, bool $force): array {
    $days = is_array($payload['days'] ?? null) ? $payload['days'] : [];
    $notes = is_array($payload['notes'] ?? null) ? $payload['notes'] : [];
    $closed = !empty($payload['closed']) ? 1 : 0;
    $newHash = canonical_hash($payload);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT revision, content_hash FROM weeks WHERE week_start = ? FOR UPDATE');
        $stmt->execute([$week]);
        $row = $stmt->fetch();
        $serverRevision = $row ? (int)$row['revision'] : 0;
        $serverHash = $row ? ($row['content_hash'] ?? null) : null;

        if (!$force && $baseRevision !== $serverRevision) {
            // Same content means this is an idempotent retry, not a real conflict.
            if ($serverHash !== null && $newHash === $serverHash) {
                $pdo->commit();
                return ['revision' => $serverRevision];
            }
            log_conflict($pdo, $week, $baseRevision, $serverRevision, $payload);
            $serverState = load_week($pdo, $week);
            $pdo->commit();
            throw new ConflictException($serverRevision, $serverState);
        }

        // Matching revision and unchanged content: skip the rewrite entirely,
        // so a redundant save cannot bump the revision for other devices.
        if (!$force && $baseRevision === $serverRevision && $serverHash !== null && $newHash === $serverHash) {
            $pdo->commit();
            return ['revision' => $serverRevision];
        }

        $nextRevision = $serverRevision + 1;

        $pdo->prepare('INSERT INTO weeks (week_start, closed, revision, content_hash) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE closed = VALUES(closed), revision = VALUES(revision), content_hash = VALUES(content_hash), updated_at = CURRENT_TIMESTAMP')
            ->execute([$week, $closed, $nextRevision, $newHash]);

        $pdo->prepare('DELETE FROM day_slots WHERE week_start = ?')->execute([$week]);
        $pdo->prepare('DELETE FROM note_items WHERE week_start = ?')->execute([$week]);

        $insertSlot = $pdo->prepare(
            'INSERT INTO day_slots (week_start, day_name, slot_index, text, item_date, item_time, done) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (DAYS as $day) {
            $slots = is_array($days[$day] ?? null) ? $days[$day] : [];
            for ($i = 0; $i < SLOTS_PER_DAY; $i++) {
                $item = sanitize_item($slots[$i] ?? null);
                $insertSlot->execute([$week, $day, $i, $item['text'], $item['date'], $item['time'], $item['done'] ? 1 : 0]);
            }
        }

        $insertNote = $pdo->prepare(
            'INSERT INTO note_items (week_start, section, position, text, item_date, item_time, done) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach (NOTE_SECTIONS as $section) {
            $items = is_array($notes[$section] ?? null) ? $notes[$section] : [];
            $pos = 0;
            foreach ($items as $raw) {
                if (!is_array($raw)) continue;
                $item = sanitize_item($raw);
                $insertNote->execute([$week, $section, $pos, $item['text'], $item['date'], $item['time'], $item['done'] ? 1 : 0]);
                $pos++;
            }
        }

        $pdo->commit();
        return ['revision' => $nextRevision];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/* ------------------------------------------------------------------
   Global "Shit-Liste" backlog (not tied to a week).
   Same whole-list read/write with optimistic locking as weeks.
   ------------------------------------------------------------------ */

function canonical_shit_payload(array $payload): array {
    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $canonical = ['items' => []];
    foreach ($items as $raw) {
        $item = sanitize_item($raw);
        $canonical['items'][] = [
            'text' => $item['text'],
            'date' => $item['date'] ?? '',
            'time' => $item['time'] ?? '',
            'done' => (bool)$item['done'],
        ];
    }
    return $canonical;
}

function canonical_shit_hash(array $payload): string {
    $json = json_encode(canonical_shit_payload($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return hash('sha256', $json);
}

function load_shitlist(PDO $pdo): array {
    $result = ['items' => [], 'revision' => 0];

    $stmt = $pdo->prepare('SELECT revision FROM shit_meta WHERE id = 1');
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) {
        $result['revision'] = (int)$row['revision'];
    }

    $stmt = $pdo->prepare('SELECT position, text, item_date, item_time, done FROM shit_items ORDER BY position ASC');
    $stmt->execute();
    foreach ($stmt as $row) {
        $result['items'][(int)$row['position']] = [
            'text' => $row['text'] ?? '',
            'date' => $row['item_date'] ?? '',
            'time' => $row['item_time'] ? substr((string)$row['item_time'], 0, 5) : '',
            'done' => (bool)$row['done'],
        ];
    }

    // Fill holes so clients get a dense, index-addressable list. Use the
    // highest position, not the count, so real gaps can never truncate items.
    $dense = [];
    $maxPos = $result['items'] ? max(array_keys($result['items'])) : -1;
    for ($i = 0; $i <= $maxPos; $i++) {
        $dense[] = $result['items'][$i] ?? ['text' => '', 'date' => '', 'time' => '', 'done' => false];
    }
    $result['items'] = $dense;

    return $result;
}

function save_shitlist(PDO $pdo, array $payload, int $baseRevision): array {
    $newHash = canonical_shit_hash($payload);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT revision, content_hash FROM shit_meta WHERE id = 1 FOR UPDATE');
        $stmt->execute();
        $row = $stmt->fetch();
        $serverRevision = $row ? (int)$row['revision'] : 0;
        $serverHash = $row ? ($row['content_hash'] ?? null) : null;

        if ($baseRevision !== $serverRevision) {
            // Same content means this is an idempotent retry, not a real conflict.
            // No conflict-audit entry here: week_conflicts is keyed by week_start.
            if ($serverHash !== null && $newHash === $serverHash) {
                $pdo->commit();
                return ['revision' => $serverRevision];
            }
            $serverState = load_shitlist($pdo);
            $pdo->commit();
            throw new ConflictException($serverRevision, $serverState, 'shit-list');
        }

        // Matching revision and unchanged content: skip the whole-list rewrite
        // (mirrors the week path; safe — nothing changed).
        if ($serverHash !== null && $newHash === $serverHash) {
            $pdo->commit();
            return ['revision' => $serverRevision];
        }

        $nextRevision = $serverRevision + 1;

        $pdo->prepare('INSERT INTO shit_meta (id, revision, content_hash) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE revision = VALUES(revision), content_hash = VALUES(content_hash)')
            ->execute([$nextRevision, $newHash]);

        $pdo->prepare('DELETE FROM shit_items')->execute();

        $insertItem = $pdo->prepare(
            'INSERT INTO shit_items (position, text, item_date, item_time, done) VALUES (?, ?, ?, ?, ?)'
        );
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $pos = 0;
        foreach ($items as $raw) {
            $item = sanitize_item($raw);
            $insertItem->execute([$pos, $item['text'], $item['date'], $item['time'], $item['done'] ? 1 : 0]);
            $pos++;
        }

        $pdo->commit();
        return ['revision' => $nextRevision];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = get_pdo();

    if ($method === 'GET') {
        if (isset($_GET['shitlist'])) {
            try {
                if (!empty($_GET['revision_only'])) {
                    $stmt = $pdo->prepare('SELECT revision FROM shit_meta WHERE id = 1');
                    $stmt->execute();
                    $row = $stmt->fetch();
                    echo json_encode(['revision' => $row ? (int)$row['revision'] : 0]);
                    exit;
                }
                echo json_encode(load_shitlist($pdo));
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '42S02') { // tables missing: not migrated yet
                    echo json_encode(['items' => [], 'revision' => 0, 'migration' => true]);
                    exit;
                }
                throw $e;
            }
        }

        $week = $_GET['week'] ?? null;
        if (!valid_week($week)) bad_request('Invalid or missing "week" (expected YYYY-MM-DD).');

        if (!empty($_GET['revision_only'])) {
            $stmt = $pdo->prepare('SELECT revision FROM weeks WHERE week_start = ?');
            $stmt->execute([$week]);
            $row = $stmt->fetch();
            $revision = $row ? (int)$row['revision'] : 0;
            echo json_encode(['revision' => $revision]);
            exit;
        }

        echo json_encode(load_week($pdo, $week));
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) bad_request('Invalid JSON body.');
        // only real JSON (fetch/XHR) may write here: blocks text/plain form posts (CSRF)
        $contentType = isset($_SERVER['CONTENT_TYPE']) ? strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'])[0])) : '';
        if ($contentType !== 'application/json') {
            bad_request('Content-Type must be application/json.');
        }
        if (isset($payload['base_revision']) && !is_int($payload['base_revision'])) {
            bad_request('"base_revision" must be an integer.');
        }
        $week = $payload['week'] ?? null;
        if (($payload['type'] ?? '') === 'shitlist') {
            // Global shit-list save (explicit "type" discriminator).
            if (!isset($payload['base_revision'])) bad_request('Missing "base_revision" (optimistic locking required).');
            if (!is_array($payload['items'] ?? null) || count($payload['items']) > 400) {
                bad_request('Too many or invalid items (max 400, expected array).');
            }
            validate_items($payload['items'] ?? []);
            // The client never force-writes the backlog and one force request
            // could replace the whole list — reject it outright.
            if (!empty($payload['force'])) bad_request('force is not supported for the shit-list.');
            $baseRevision = (int)$payload['base_revision'];
            try {
                $result = save_shitlist($pdo, $payload, $baseRevision);
                echo json_encode(['ok' => true, 'revision' => $result['revision']]);
                exit;
            } catch (PDOException $e) {
                if ($e->getCode() === '42S02') { // tables missing: not migrated yet
                    http_response_code(503);
                    echo json_encode(['error' => 'Database migration required (shit-list tables missing).', 'code' => 'migration_required']);
                    exit;
                }
                throw $e;
            } catch (ConflictException $e) {
                http_response_code(409);
                echo json_encode([
                    'error' => 'Conflict: shit-list was modified elsewhere',
                    'code' => 'conflict',
                    'revision' => $e->serverRevision,
                    'server' => $e->serverState,
                ]);
                exit;
            }
        }
        if (!valid_week($week)) bad_request('Invalid or missing "week" (expected YYYY-MM-DD).');
        if (!isset($payload['base_revision'])) bad_request('Missing "base_revision" (optimistic locking required).');
        validate_payload_items($payload);

        $baseRevision = (int)$payload['base_revision'];
        $force = !empty($payload['force']);

        try {
            $result = save_week($pdo, $week, $payload, $baseRevision, $force);
            echo json_encode(['ok' => true, 'revision' => $result['revision']]);
            exit;
        } catch (ConflictException $e) {
            http_response_code(409);
            echo json_encode([
                'error' => 'Conflict: week was modified elsewhere',
                'code' => 'conflict',
                'revision' => $e->serverRevision,
                'server' => $e->serverState,
            ]);
            exit;
        }
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
} catch (Throwable $e) {
    error_log('week-planner: ' . $e->getMessage());
    server_error('Internal server error.');
}
