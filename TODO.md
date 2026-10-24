# DI — TODO

## Phase 1 — Data & Schema ✅
- [x] Analyse Excel data structure
- [x] Design MySQL schema
- [x] Scaffold project
- [ ] Run migration script on production DB
- [ ] Verify all 7 years loaded correctly

## Phase 2 — Public Views
- [ ] All-time leaderboard (points, wins, UPs)
- [ ] Year-by-year results page
- [ ] Player profile pages
- [ ] Current year live round-by-round view

## Phase 3 — Admin Panel
- [x] Auth (login) — DI admin accounts
- [ ] Manage players + team assignments
- [ ] Set up tournament years
- [ ] Create round pairings
- [ ] Enter match results

## Phase 3b — Player accounts (for 2027)
- [x] One account per player (`di_accounts`), auto-created for every player incl. new ones
- [x] Invite link → player sets own email + password; login / logout / change password
- [x] Admin page `accounts.html`: status per player, copy invite links, new/reset links
- [x] Link to `accounts.html` from `admin.html`
- [x] Organiser account marked as admin (Peter Bugge)
- [ ] Send invite links to the 2027 roster
- [x] Team shop: allowance per year, team-colour products with sizes, player orders on own profile, deadline lock
- [x] Shop admin page (`shop-admin.html`): settings, catalogue, order status, GolfExperten summary + CSV
- [ ] Replace the placeholder 2027 products with GolfExperten's real products, prices and pictures
- [ ] Set the 2027 order deadline, then open the shop and send invite links
- [ ] Send the summary/CSV to GolfExperten after the deadline
- [x] admin.html uses the DI admin login; admin.php / players.php / matches.php writes require an admin session

## Deploy
- [x] Deploy all endpoints except `db_connect.php` (live verified identical to repo first)
- [ ] The DB_* GitHub secrets are now unused (db_connect.php is hand-managed) — delete them or verify them if CI should ever manage db_connect.php
- [ ] Remove the dead copy in the SFTP user's home (`users/cib30a83y_ssh/di`, incl. a stray `account.php` from debugging)

## Phase 4 — Polish
- [ ] Mobile responsive
- [ ] Team colour theming (red/blue)
- [ ] Stats: head-to-head records
- [ ] CNAME + deploy to di.labanos.dk
