# CLAUDE.md — Damsgaard Invitational (di)

Annual Ryder Cup–style golf tournament tracker. Static HTML/JS frontend hosted on
GitHub Pages with a custom domain (`CNAME`), backed by PHP endpoints on `labanos.dk`
that talk to a shared MySQL database.

## Stack
- **Frontend:** plain HTML/JS at the repo root. No build step. Entry pages: `index.html` (public site) and `admin.html` (admin console, large monolith).
- **Backend:** PHP under `php/`. Each endpoint is a self-contained `*.php` file that `require_once`s `db_connect.php`, returns JSON, and sets permissive CORS.
- **Database:** MySQL on `labanos.dk`. Shared instance with `fitness_buddy` and `investtracker` (one schema, all tables together). See "Database" below.
- **Default branch:** `main`.
- **Deploy:** static files served from GitHub Pages; PHP is uploaded to one.com's web root (via FTP/SFTP/SSH — not from this repo's CI).

## File layout
- `index.html`, `admin.html` — entry points
- `app.js`, `components.js`, `constants.js` — shared UI/state
- `leaderboard.js`, `player.js`, `history.js`, `thisyear.js` — page modules
- `php/db_connect.php` — shared PDO connection (template, credentials substituted on server)
- `php/*.php` — REST-ish endpoints (`leaderboard`, `matches`, `players`, `roster`, `results`, `year_schedule`, `player_stats`, `pair_suggest`, `pairing_analysis`, `admin`)
- `migrate/` — one-off Python + SQL scripts that imported historical Excel data. Not part of the runtime; safe to ignore unless re-importing.
- `TODO.md` — current backlog. Read before suggesting new work.

## Tables this app owns
- `players` — roster (blue/red, active flag)
- `tournaments` — one row per year, plus `legacy`/score override columns for years we don't have match-level data for (e.g. 2018)
- `rounds` — fourball / greensome / foursome / singles per tournament
- `matches` — one or more per round
- `match_results` — per-player result rows (`points`, `ups`, optional `partner_id`); `NULL` points/ups means "scheduled, not yet played"
- `roster_assignments` — per-year roster; if a year has no rows it falls back to "all active players"

Schema lives in `php/db_migrate.php` (idempotent — re-runs are safe).

## Conventions
- PHP endpoints use **PDO** with `utf8mb4`, exception mode, fetch-assoc.
- All responses are **JSON**; CORS is wide open (`Access-Control-Allow-Origin: *`).
- Auth: bearer token in `Authorization` header against `users.api_token` (table lives in the shared DB; same as investtracker/fitness_buddy). Use `require_auth($pdo)` pattern for write routes.
- Frontend is vanilla JS — no framework, no bundler. Keep it that way unless we explicitly agree to introduce one.
- Don't touch `migrate/` files casually; they were one-off importers.

## Database — how I (Claude) work with it
- The DB is shared across all three projects on labanos.dk MySQL. Credentials live in `php/db_connect.php` on the server (filled in from `%%PLACEHOLDERS%%` at deploy time). **Never commit real credentials to this repo.**
- For my own ad-hoc access I use a **dedicated MySQL user**, separate from the app user. Connection details are in the user-level global instructions, not in this repo.
- **Safety rule (I follow this every session):**
  - `SELECT` queries — I run them freely.
  - `INSERT` / `UPDATE` / `DELETE` — I first show you the matching rows (`SELECT … WHERE …` preview) and the exact write statement, and wait for your explicit "yes" in chat before executing.
  - `DROP` / `ALTER` / `TRUNCATE` / `GRANT` — I refuse to run these from chat; raise as a PR or migration instead.
- Schema changes go in `php/db_migrate.php` as idempotent `CREATE TABLE IF NOT EXISTS` / guarded `ALTER`s, matching the existing pattern.

## Working agreements
- **Branch & PR flow:** I branch from `main` as `claude/<short-topic>`, commit small focused changes, open a PR, and wait for your review. I never push directly to `main`.
- **Issues:** if I find something worth doing but out of scope, I open a GitHub issue rather than expanding the PR.
- **TODO.md:** I read it at the start of any non-trivial session and update it (move done items, add discoveries) at the end.
- **Don't break the public site:** `index.html` is the live tournament page. For risky changes, branch + PR + your eyeball.

## Quick start for me
1. Read `TODO.md` and `README.md` for current state.
2. If the task touches the DB, peek at `php/db_migrate.php` for the schema and `php/<relevant-endpoint>.php` for query patterns.
3. Open a branch, make the change, push, open a PR.
