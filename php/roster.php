<?php
// Returns the roster for a given tournament year.
//
// GET /php/roster.php?year=2026
//
// Response:
//   {
//     "year":     2026,
//     "explicit": true,     // true if an explicit roster has been saved for the year
//     "players":  [ {id, name, team}, ... ]  // sorted by team then name
//   }
//
// If no explicit roster has been set for the year, we fall back to "all active
// players" so years created before the roster feature shipped keep working.
//
// Past years (2019–2025) derive their roster from actual match results via
// the existing player_stats.php / leaderboard endpoints — this endpoint is only
// used by the admin UI to know who's eligible for new match assignments.

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

$year = intval($_GET['year'] ?? 0);
if ($year < 2000 || $year > 2100) {
    http_response_code(400);
    echo json_encode(['error' => 'year required (2000–2100)']);
    exit;
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM roster_assignments WHERE year = ?");
$stmt->execute([$year]);
$explicit = ((int)$stmt->fetchColumn()) > 0;

if ($explicit) {
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.team
          FROM players p
          JOIN roster_assignments ra ON ra.player_id = p.id
         WHERE ra.year = ?
         ORDER BY p.team, p.name
    ");
    $stmt->execute([$year]);
} else {
    $stmt = $pdo->prepare("
        SELECT id, name, team
          FROM players
         WHERE active = 1
         ORDER BY team, name
    ");
    $stmt->execute();
}

$players = array_map(function ($p) {
    return ['id' => (int)$p['id'], 'name' => $p['name'], 'team' => $p['team']];
}, $stmt->fetchAll(PDO::FETCH_ASSOC));

echo json_encode([
    'year'     => $year,
    'explicit' => $explicit,
    'players'  => $players,
]);
