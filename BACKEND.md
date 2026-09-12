# Backend setup (PHP + MySQL)

The planner now persists to MySQL via `api.php` instead of `window.storage`.

## Files

- `schema.sql` — creates `weeks`, `day_slots`, `note_items` tables.
- `config.php.example` — DB connection template. Copy to `config.php` and edit, or set the `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` environment variables.
- `db.php` — PDO connection helper, used by `api.php`.
- `api.php` — the only HTTP endpoint the frontend talks to.
- `index.html` — unchanged UI/UX; `loadWeek()`/`saveWeek()` now call `api.php`.

## Setup (manual / real hosting)

1. Create a database and import the schema:
   ```
   mysql -u <user> -p <database> < schema.sql
   ```
2. Copy `config.php.example` to `config.php` and fill in your host/dbname/user/pass, or set the `DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` environment variables.
3. Deploy `index.html`, `api.php`, `db.php`, and `config.php` together on any PHP host (PHP 8.x, PDO MySQL extension enabled). They must sit in the same directory since `api.php` is referenced as a relative URL.
4. Open `index.html` through the web server (not as a local `file://` — the `fetch()` calls need an HTTP origin to hit `api.php`).

## Setup (Docker, for local testing)

`Dockerfile` + `docker-compose.yml` spin up PHP/Apache and MySQL together:

```
docker compose up --build
```

Then open http://localhost:8080/index.html — `config.php` picks up the
`DB_HOST`/`DB_NAME`/`DB_USER`/`DB_PASS` env vars set in `docker-compose.yml`,
so no manual editing is needed. `schema.sql` is mounted into MySQL's
`/docker-entrypoint-initdb.d/` and runs automatically the first time the `db`
volume is created.

Notes:
- The `web` service bind-mounts the repo into the container, so edits to
  `index.html`/`api.php` show up on refresh without rebuilding.
- The schema only auto-imports on a fresh `db_data` volume. To reset the
  database (e.g. after editing `schema.sql`), run
  `docker compose down -v` and then `docker compose up --build` again.
- MySQL is also published on `localhost:3306` (root password `root`) if you
  want to inspect it with a client.

## API contract

- `GET api.php?week=YYYY-MM-DD` → `{ days: {...}, notes: {...}, closed: bool, revision: int }` (Monday of the requested week; missing weeks return an all-empty, not-closed shape with `revision: 0`).
- `GET api.php?week=YYYY-MM-DD&revision_only=1` → `{ revision: int }` (lightweight poll for multi-device sync).
- `POST api.php` with JSON body `{ week, days, notes, closed, base_revision }` → replaces that week's rows entirely (delete + reinsert in a transaction), bumps the `revision` counter, and returns `{ ok: true, revision: int }`.
- `POST api.php` with `{ ..., force: true }` and the current server `revision` as `base_revision` → overwrites even when another edit arrived in between.
- Stale writes return `HTTP 409` with `{ code: "conflict", revision: int, server: {...} }`; the rejected payload is logged to `week_conflicts`.

The whole week is read/written as one unit, matching how the frontend already keeps the whole week in memory and autosaves after every change — no incremental diffing needed.

## Migrating an existing database

If you already have a database from before the "Abschließen" (close week) and optimistic-locking features, run the bundled migration script instead of applying single `ALTER` statements by hand:

```bash
mysql -u <user> -p <database> < migrate_optimistic_locking.sql
```

It adds the `closed` and `revision` columns to `weeks` (skipping them if they already exist), removes the unused `content_hash` column if it exists, and creates the `week_conflicts` audit table. Run it once on an old database:

## Notes

- No authentication is implemented (per current scope) — anything with network access to `api.php` can read/write. Add auth (e.g. HTTP basic auth via `.htaccess`, or a shared-secret header check in `api.php`) before deploying anywhere non-trusted.
- Note-item IDs are client-side only (generated in the browser, not persisted) — ordering is preserved server-side via a `position` column instead.
