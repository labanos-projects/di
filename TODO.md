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
- [ ] Auth (login)
- [ ] Manage players + team assignments
- [ ] Set up tournament years
- [ ] Create round pairings
- [ ] Enter match results

## Phase 3b — Player accounts (for 2027)
- [x] One account per player (`di_accounts`), auto-created for every player incl. new ones
- [x] Invite link → player sets own email + password; login / logout / change password
- [x] Admin page `accounts.html`: status per player, copy invite links, new/reset links
- [ ] Link to `accounts.html` from `admin.html` (or fold it in when admin.php gets real auth)
- [x] Organiser account marked as admin (Peter Bugge)
- [ ] Send invite links to the 2027 roster
- [ ] Logged-in player profile page (sizes / details for polo order)
- [ ] Polo ordering
- [ ] Replace the client-side admin password with the new admin login for all admin.php writes

## Deploy
- [ ] Verify the DB_* GitHub secrets against the live `db_connect.php`, then add the remaining endpoints to `DEPLOY_FILES` in `deploy-php.yml` so the live folder matches the repo
- [ ] Remove the dead copy in the SFTP user's home (`users/cib30a83y_ssh/di`, incl. a stray `account.php` from debugging)

## Phase 4 — Polish
- [ ] Mobile responsive
- [ ] Team colour theming (red/blue)
- [ ] Stats: head-to-head records
- [ ] CNAME + deploy to di.labanos.dk
