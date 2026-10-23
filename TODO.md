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
- [ ] Mark organiser account(s) as admin after deploy (`UPDATE di_accounts SET is_admin = 1 …`)
- [ ] Send invite links to the 2027 roster
- [ ] Logged-in player profile page (sizes / details for polo order)
- [ ] Polo ordering
- [ ] Replace the client-side admin password with the new admin login for all admin.php writes

## Phase 4 — Polish
- [ ] Mobile responsive
- [ ] Team colour theming (red/blue)
- [ ] Stats: head-to-head records
- [ ] CNAME + deploy to di.labanos.dk
