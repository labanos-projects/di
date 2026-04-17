<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

// DB stores points doubled (2=win, 1=halved, 0=loss); divide by 2 for display
$year = isset($_GET['year']) ? (int)$_GET['year'] : null;

$cols = "
    p.id,
    p.name,
    p.team,
    p.active,
    COUNT(mr.id)                                            AS matches_played,
    COALESCE(SUM(mr.points), 0) / 2                        AS total_points,
    COALESCE(SUM(CASE WHEN mr.points = 2 THEN 1 END), 0)   AS wins,
    COALESCE(SUM(CASE WHEN mr.points = 1 THEN 1 END), 0)   AS halves,
    COALESCE(SUM(CASE WHEN mr.points = 0 THEN 1 END), 0)   AS losses,
    COALESCE(SUM(mr.ups), 0)                                AS total_ups,
    ROUND(
        COALESCE(SUM(mr.points), 0) / NULLIF(COUNT(mr.id), 0) / 2,
    3)                                                      AS avg_points";

if ($year) {
    // Year-specific: INNER JOIN so only players who actually played that year appear
    $stmt = $pdo->prepare("
        SELECT $cols
        FROM players p
        JOIN match_results mr ON mr.player_id = p.id
        JOIN matches m        ON m.id = mr.match_id
        JOIN rounds r         ON r.id = m.round_id
        JOIN tournaments t    ON t.id = r.tournament_id
        WHERE t.year = ?
        GROUP BY p.id
        ORDER BY total_points DESC, avg_points DESC, total_ups DESC
    ");
    $stmt->execute([$year]);
} else {
    // All-time: LEFT JOIN to include players with zero matches
    $stmt = $pdo->query("
        SELECT $cols
        FROM players p
        LEFT JOIN match_results mr ON mr.player_id = p.id
        GROUP BY p.id
        ORDER BY total_points DESC, avg_points DESC, total_ups DESC
    ");
}

echo json_encode($stmt->fetchAll());
