<?php
declare(strict_types=1);

// Wochenplaner backend API.
//   GET  api.php?week=YYYY-MM-DD          -> { days, notes, closed, revision }
//   GET  api.php?week=YYYY-MM-DD&revision_only=1 -> { revision }
//   POST api.php  { week, days, notes, closed, base_revision } -> { ok: true, revision }
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
    public function __construct(int $revision, array $state) {
        $this->serverRevision = $revision;
        $this->serverState = $state;
        parent::__construct('Conflict: week was modified elsewhere');
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
    $text = isset($item['text']) ? (string)$item['text'] : '';
    $date = (isset($item['date']) && $item['date'] !== '') ? (string)$item['date'] : null;
    $time = (isset($item['time']) && $item['time'] !== '') ? (string)$item['time'] : null;
    $done = !empty($item['done']);

    if ($date !== null) {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) $date = null;
    }
    if ($time !== null && !preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time)) {
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

$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = get_pdo();

    if ($method === 'GET') {
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
        $week = $payload['week'] ?? null;
        if (!valid_week($week)) bad_request('Invalid or missing "week" (expected YYYY-MM-DD).');
        if (!isset($payload['base_revision'])) bad_request('Missing "base_revision" (optimistic locking required).');

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
    server_error('Server error: ' . $e->getMessage());
}
