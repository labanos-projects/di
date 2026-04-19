<?php
// Admin endpoint — match results + player management
// GET  ?action=players          -> all players
// GET  ?action=years            -> all tournament years (excluding legacy)
// GET  ?year=X&round=Y          -> match slots for that round
// POST {action:'add_year'}      -> create tournament + rounds for a new year
// POST {action:'add_player'}    -> create player
// POST {action:'update_player'} -> rename / change team
// POST {action:'merge_players'} -> reassign results from merge_id -> keep_id, delete merge_id
// POST {action:'delete_player'} -> delete player (only if no results)
// POST {action:'delete_match'}  -> delete match and all its results
// POST {action:'set_roster'}    -> replace roster_assignments for a year
// POST (no action)              -> replace match results (winner may be omitted for upcoming matches)
// v1.9
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
run_migrations($pdo);

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ─────────────────────────────────────────────────────────────────
if ($method === 'GET') {

    $action = $_GET['action'] ?? '';

    // Player list
    if ($action === 'players') {
        $stmt = $pdo->query("SELECT id, name, team, active FROM players ORDER BY team, name");
        echo json_encode($stmt->fetchAll());
        exit;
    }

    // All tournament years — exclude legacy years (history-only, no match data)
    if ($action === 'years') {
        $stmt = $pdo->query("SELECT year FROM tournaments WHERE COALESCE(legacy, 0) = 0 ORDER BY year DESC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
        exit;
    }

    // Match slots for a year + round
    $year  = (int)($_GET['year']  ?? 0);
    $round = (int)($_GET['round'] ?? 0);
    if (!$year || !$round) {
        http_response_code(400);
        echo json_encode(['error' => 'year and round required']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT r.format, m.id AS match_id, m.match_number,
               mr.player_id, p.name AS player_name, p.team,
               mr.points, mr.ups,
               part.name AS partner_name, mr.partner_id
        FROM tournaments t
        JOIN rounds r  ON r.tournament_id = t.id AND r.round_number = ?
        JOIN matches m ON m.round_id = r.id
        LEFT JOIN match_results mr ON mr.match_id = m.id
        LEFT JOIN players p        ON p.id = mr.player_id
        LEFT JOIN players part     ON part.id = mr.partner_id
        WHERE t.year = ?
        ORDER BY m.match_number, p.team, p.name
    ");
    $stmt->execute([$round, $year]);
    $rows = $stmt->fetchAll();

    $matches = [];
    foreach ($rows as $row) {
        $mn = $row['match_number'];
        if (!isset($matches[$mn])) {
            $matches[$mn] = [
                'match_id'     => $row['match_id'],
                'match_number' => $mn,
                'format'       => $row['format'],
                'results'      => [],
            ];
        }
        if ($row['player_id']) {
            $matches[$mn]['results'][] = [
                'player_id'    => (int)$row['player_id'],
                'player_name'  => $row['player_name'],
                'team'         => $row['team'],
                // Preserve NULL so the admin can distinguish upcoming vs played
                'points'       => $row['points'] !== null ? (int)$row['points'] : null,
                'ups'          => $row['ups']    !== null ? (int)$row['ups']    : null,
                'partner_id'   => $row['partner_id'] ? (int)$row['partner_id'] : null,
                'partner_name' => $row['partner_name'],
            ];
        }
    }

    echo json_encode(array_values($matches));
    exit;
}

// ── POST ────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true);
    $action = $data['action'] ?? '';

    // ── Set roster for a year ─────────────────────────────────────────
    // POST { action: 'set_roster', year: 2026, player_ids: [1,2,...] }
    // Replaces all roster rows for that year. Empty player_ids clears the
    // roster (which makes roster.php fall back to "all active players").
    if ($action === 'set_roster') {
        $year       = (int)($data['year'] ?? 0);
        $player_ids = $data['player_ids'] ?? [];
        if (!$year || $year < 2000 || $year > 2100) {
            http_response_code(400);
            echo json_encode(['error' => 'valid year required']);
            exit;
        }
        if (!is_array($player_ids)) $player_ids = [];
        $player_ids = array_values(array_unique(array_map('intval', $player_ids)));
        $player_ids = array_filter($player_ids, function ($id) { return $id > 0; });

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM roster_assignments WHERE year = ?")->execute([$year]);
            if (!empty($player_ids)) {
                $stmt = $pdo->prepare("INSERT INTO roster_assignments (year, player_id) VALUES (?, ?)");
                foreach ($player_ids as $pid) { $stmt->execute([$year, $pid]); }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }
        echo json_encode(['ok' => true, 'year' => $year, 'count' => count($player_ids)]);
        exit;
    }

    // ── Add year (tournament + rounds) ───────────────────────────────────
    if ($action === 'add_year') {
        $year   = (int)($data['year']   ?? 0);
        $rounds = $data['rounds']       ?? [];
        $valid_formats = ['fourball','greensome','foursome','singles'];

        if (!$year || empty($rounds)) {
            http_response_code(400);
            echo json_encode(['error' => 'year and rounds required']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT id FROM tournaments WHERE year = ?");
        $stmt->execute([$year]);
        if ($stmt->fetch()) {
            http_response_code(409);
            echo json_encode(['error' => "Year $year already exists"]);
            exit;
        }

        $pdo->prepare("INSERT INTO tournaments (year) VALUES (?)")->execute([$year]);
        $tournament_id = (int)$pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO rounds (tournament_id, round_number, format) VALUES (?, ?, ?)");
        foreach ($rounds as $r) {
            $rn  = (int)($r['round_number'] ?? 0);
            $fmt = $r['format'] ?? '';
            if (!$rn || !in_array($fmt, $valid_formats)) continue;
            $stmt->execute([$tournament_id, $rn, $fmt]);
        }

        echo json_encode(['ok' => true, 'tournament_id' => $tournament_id]);
        exit;
    }

    // ── Add player ─────────────────────────────────────────────────
    if ($action === 'add_player') {
        $name = trim($data['name'] ?? '');
        $team = $data['team'] ?? '';
        if (!$name || !in_array($team, ['blue','red'])) {
            http_response_code(400);
            echo json_encode(['error' => 'name and team (blue|red) required']);
            exit;
        }
        try {
            $pdo->prepare("INSERT INTO players (name, team, active) VALUES (?, ?, 1)")
                ->execute([$name, $team]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
        } catch (PDOException $e) {
            http_response_code(409);
            echo json_encode(['error' => 'Player already exists']);
        }
        exit;
    }

    // ── Update player (rename / change team) ──────────────────────────
    if ($action === 'update_player') {
        $id   = (int)($data['id']   ?? 0);
        $name = trim($data['name']  ?? '');
        $team = $data['team'] ?? '';
        if (!$id || !$name || !in_array($team, ['blue','red'])) {
            http_response_code(400);
            echo json_encode(['error' => 'id, name and team required']);
            exit;
        }
        $pdo->prepare("UPDATE players SET name = ?, team = ? WHERE id = ?")
            ->execute([$name, $team, $id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Merge players ────────────────────────────────────────────────
    if ($action === 'merge_players') {
        $keep_id  = (int)($data['keep_id']  ?? 0);
        $merge_id = (int)($data['merge_id'] ?? 0);
        if (!$keep_id || !$merge_id || $keep_id === $merge_id) {
            http_response_code(400);
            echo json_encode(['error' => 'keep_id and merge_id required and must differ']);
            exit;
        }

        $pdo->prepare("UPDATE match_results SET partner_id = ? WHERE partner_id = ?")
            ->execute([$keep_id, $merge_id]);

        $pdo->prepare("
            UPDATE match_results
            SET player_id = ?
            WHERE player_id = ?
              AND match_id NOT IN (
                  SELECT match_id FROM (
                      SELECT match_id FROM match_results WHERE player_id = ?
                  ) AS tmp
              )
        ")->execute([$keep_id, $merge_id, $keep_id]);

        $pdo->prepare("DELETE FROM match_results WHERE player_id = ?")
            ->execute([$merge_id]);
        $pdo->prepare("DELETE FROM players WHERE id = ?")
            ->execute([$merge_id]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Delete player ───────────────────────────────────────────────
    if ($action === 'delete_player') {
        $id = (int)($data['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['error' => 'id required']); exit; }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM match_results WHERE player_id = ?");
        $stmt->execute([$id]);
        if ($stmt->fetchColumn() > 0) {
            http_response_code(409);
            echo json_encode(['error' => 'Player has match results — merge instead of deleting']);
            exit;
        }
        $pdo->prepare("DELETE FROM players WHERE id = ?")->execute([$id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Delete match ─────────────────────────────────────────────────
    if ($action === 'delete_match') {
        $match_id = (int)($data['match_id'] ?? 0);
        if (!$match_id) {
            http_response_code(400);
            echo json_encode(['error' => 'match_id required']);
            exit;
        }
        $pdo->prepare("DELETE FROM match_results WHERE match_id = ?")->execute([$match_id]);
        $pdo->prepare("DELETE FROM matches WHERE id = ?")->execute([$match_id]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // ── Save match ───────────────────────────────────────────────────
    // Creates the match row if needed. Both the players list and the winner are
    // optional — an empty placeholder ("draft") is allowed so captains can
    // pre-create matches before they've decided who plays.
    $year         = (int)($data['year']         ?? 0);
    $round_number = (int)($data['round_number'] ?? 0);
    $match_number = (int)($data['match_number'] ?? 0);
    $winner       = $data['winner']  ?? '';   // '' = upcoming (no result yet)
    $ups_value    = (int)($data['ups'] ?? 0);
    $players      = $data['players'] ?? [];
    if (!is_array($players)) $players = [];

    if (!$year || !$round_number || !$match_number) {
        http_response_code(400);
        echo json_encode(['error' => 'year, round_number, match_number required']);
        exit;
    }

    // Drop any slots with an empty name — those are unfilled draft slots.
    $players = array_values(array_filter($players, function ($p) {
        return isset($p['name']) && trim($p['name']) !== '';
    }));

    // If a final result was provided, we need at least one player per team so
    // the points have someone to attach to. (Upcoming / draft saves don't care.)
    if ($winner !== '') {
        $teams_present = [];
        foreach ($players as $p) { $teams_present[$p['team']] = true; }
        if (empty($teams_present['blue']) || empty($teams_present['red'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Result set but a team has no players — clear the result or pick players']);
            exit;
        }
    }

    // Look up or create the match row
    $stmt = $pdo->prepare("
        SELECT m.id FROM matches m
        JOIN rounds r ON r.id = m.round_id
        JOIN tournaments t ON t.id = r.tournament_id
        WHERE t.year = ? AND r.round_number = ? AND m.match_number = ?
    ");
    $stmt->execute([$year, $round_number, $match_number]);
    $match = $stmt->fetch();

    if (!$match) {
        $stmt2 = $pdo->prepare("
            SELECT r.id FROM rounds r
            JOIN tournaments t ON t.id = r.tournament_id
            WHERE t.year = ? AND r.round_number = ?
        ");
        $stmt2->execute([$year, $round_number]);
        $round_row = $stmt2->fetch();
        if (!$round_row) {
            http_response_code(404);
            echo json_encode(['error' => "Round not found: year=$year round=$round_number. Create the year first."]);
            exit;
        }
        $pdo->prepare("INSERT INTO matches (round_id, match_number) VALUES (?, ?)")
            ->execute([$round_row['id'], $match_number]);
        $match_id = (int)$pdo->lastInsertId();
    } else {
        $match_id = (int)$match['id'];
    }

    $resolved = [];
    foreach ($players as $p) {
        $stmt = $pdo->prepare("SELECT id FROM players WHERE name = ?");
        $stmt->execute([$p['name']]);
        $row = $stmt->fetch();
        if (!$row) {
            http_response_code(404);
            echo json_encode(['error' => "Player not found: {$p['name']}"]);
            exit;
        }
        $player_id = $row['id'];

        $partner_id = null;
        if (!empty($p['partner_name'])) {
            $stmt2 = $pdo->prepare("SELECT id FROM players WHERE name = ?");
            $stmt2->execute([$p['partner_name']]);
            $r2 = $stmt2->fetch();
            if ($r2) $partner_id = $r2['id'];
        }

        // winner = '' means upcoming — store NULL for points and ups
        if ($winner === '') {
            $points = null;
            $ups    = null;
        } elseif ($winner === 'halved') {
            $points = 1; $ups = 0;
        } elseif ($winner === $p['team']) {
            $points = 2; $ups = $ups_value;
        } else {
            $points = 0; $ups = -$ups_value;
        }

        $resolved[] = [$match_id, $player_id, $points, $ups, $partner_id];
    }

    // Replace whatever results existed. If $resolved is empty we just leave
    // the match row in place with no results — a true placeholder / draft.
    $pdo->prepare("DELETE FROM match_results WHERE match_id = ?")->execute([$match_id]);

    if (!empty($resolved)) {
        $stmt = $pdo->prepare("
            INSERT INTO match_results (match_id, player_id, points, ups, partner_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        foreach ($resolved as $r) {
            $stmt->execute($r);
        }
    }

    echo json_encode(['ok' => true, 'match_id' => $match_id]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
