<?php
// Player shop — team merchandise ordered through GolfExperten.
// Tables: shop_config, shop_products, shop_orders, shop_order_items (db_migrate.php).
//
// Every player on a year's roster gets an allowance (shop_config.allowance, DKK)
// counted against the discounted price. Anything above it is "extra", billed to
// the player individually by GolfExperten. Unused allowance is lost.
//
// Logged-in player (Authorization: Bearer <session>):
//   GET  ?action=my&year=Y                 -> shop state, catalogue and own order
//   POST {action:'save_order', year, items:[{product_id,size,qty}], submit:bool}
//
// Admin (di_accounts.is_admin = 1):
//   GET  ?action=admin&year=Y              -> settings, catalogue, every rostered player's order, summary
//   GET  ?action=export&year=Y&type=lines|summary   -> CSV
//   POST {action:'save_config', year, allowance, deadline:'YYYY-MM-DD HH:MM'|'' , is_open}
//   POST {action:'save_product', id?, year, name, description, list_price, price, sizes,
//         team_colored, has_logo, image_url, max_qty, sort_order, active}
//   POST {action:'delete_product', id}     -> only if never ordered (else deactivate it)
//   POST {action:'reopen_order', year, player_id}  -> back to draft
// v1.0
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/db_migrate.php';
require_once __DIR__ . '/auth_lib.php';
run_migrations($pdo);

date_default_timezone_set('Europe/Copenhagen');   // deadlines are entered in Danish time
const DEFAULT_ALLOWANCE = 300;

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function valid_year($y) {
    $y = (int)$y;
    if ($y < 2000 || $y > 2100) out(['error' => 'valid year required'], 400);
    return $y;
}

function shop_config($pdo, $year) {
    $stmt = $pdo->prepare("SELECT allowance, deadline, is_open FROM shop_config WHERE year = ?");
    $stmt->execute([$year]);
    $c = $stmt->fetch();
    $deadline = $c ? $c['deadline'] : null;
    return [
        'year'      => $year,
        'allowance' => $c ? (int)$c['allowance'] : DEFAULT_ALLOWANCE,
        'deadline'  => $deadline ? substr($deadline, 0, 16) : null,     // 'YYYY-MM-DD HH:MM'
        'is_open'   => $c ? (bool)$c['is_open'] : false,
        'locked'    => $deadline ? (time() > strtotime($deadline)) : false,
    ];
}

// Rostered players for the year (explicit roster, else all active players — same rule as roster.php)
function roster_players($pdo, $year) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM roster_assignments WHERE year = ?");
    $stmt->execute([$year]);
    if ((int)$stmt->fetchColumn() > 0) {
        $stmt = $pdo->prepare("SELECT p.id, p.name, p.team FROM players p
                               JOIN roster_assignments ra ON ra.player_id = p.id
                               WHERE ra.year = ? ORDER BY p.team, p.name");
        $stmt->execute([$year]);
    } else {
        $stmt = $pdo->query("SELECT id, name, team FROM players WHERE active = 1 ORDER BY team, name");
    }
    return $stmt->fetchAll();
}

function on_roster($pdo, $year, $player_id) {
    foreach (roster_players($pdo, $year) as $p) if ((int)$p['id'] === (int)$player_id) return true;
    return false;
}

function product_row($p) {
    return [
        'id'           => (int)$p['id'],
        'year'         => (int)$p['year'],
        'name'         => $p['name'],
        'description'  => $p['description'],
        'list_price'   => $p['list_price'] !== null ? (int)$p['list_price'] : null,
        'price'        => (int)$p['price'],
        'sizes'        => $p['sizes'] ? array_values(array_filter(array_map('trim', explode(',', $p['sizes'])), 'strlen')) : [],
        'team_colored' => (bool)$p['team_colored'],
        'has_logo'     => (bool)$p['has_logo'],
        'image_url'    => $p['image_url'],
        'max_qty'      => (int)$p['max_qty'],
        'sort_order'   => (int)$p['sort_order'],
        'active'       => (bool)$p['active'],
    ];
}

function products($pdo, $year, $active_only) {
    $sql = "SELECT * FROM shop_products WHERE year = ?" . ($active_only ? " AND active = 1" : "") .
           " ORDER BY sort_order, name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$year]);
    return array_map('product_row', $stmt->fetchAll());
}

// A player's order with lines and totals (or an empty draft shape).
function load_order($pdo, $year, $player_id, $allowance) {
    $stmt = $pdo->prepare("SELECT * FROM shop_orders WHERE year = ? AND player_id = ?");
    $stmt->execute([$year, $player_id]);
    $o = $stmt->fetch();
    $items = [];
    if ($o) {
        $stmt = $pdo->prepare("SELECT i.product_id, i.size, i.qty, i.unit_price, p.name
                               FROM shop_order_items i JOIN shop_products p ON p.id = i.product_id
                               WHERE i.order_id = ? ORDER BY p.sort_order, p.name, i.size");
        $stmt->execute([$o['id']]);
        foreach ($stmt->fetchAll() as $r) {
            $items[] = ['product_id' => (int)$r['product_id'], 'name' => $r['name'], 'size' => $r['size'],
                        'qty' => (int)$r['qty'], 'unit_price' => (int)$r['unit_price']];
        }
    }
    $total = 0;
    foreach ($items as $i) $total += $i['qty'] * $i['unit_price'];
    return [
        'status'       => $o ? $o['status'] : 'none',
        'submitted_at' => $o ? $o['submitted_at'] : null,
        'updated_at'   => $o ? $o['updated_at'] : null,
        'items'        => $items,
        'total'        => $total,
        'extra'        => max(0, $total - $allowance),
    ];
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET ──────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $action = $_GET['action'] ?? '';

    if ($action === 'my') {
        $acc  = require_di_account($pdo);
        $year = valid_year($_GET['year'] ?? 0);
        $cfg  = shop_config($pdo, $year);
        $stmt = $pdo->prepare("SELECT id, name, team FROM players WHERE id = ?");
        $stmt->execute([$acc['player_id']]);
        $player   = $stmt->fetch();
        $is_admin = (bool)$acc['is_admin'];
        $rostered = on_roster($pdo, $year, $acc['player_id']);
        $res = [
            'config'    => $cfg,
            'player'    => ['id' => (int)$player['id'], 'name' => $player['name'], 'team' => $player['team']],
            'on_roster' => $rostered,
            'is_admin'  => $is_admin,
            'preview'   => !$cfg['is_open'] && $is_admin,   // admins can see a closed shop
        ];
        if (!$cfg['is_open'] && !$is_admin) out($res);
        $res['products'] = products($pdo, $year, true);
        $res['order']    = load_order($pdo, $year, $acc['player_id'], $cfg['allowance']);
        out($res);
    }

    if ($action === 'admin') {
        require_di_admin($pdo);
        $year = valid_year($_GET['year'] ?? 0);
        $cfg  = shop_config($pdo, $year);
        $prods = products($pdo, $year, false);
        $byId = [];
        foreach ($prods as $p) $byId[$p['id']] = $p;

        $orders = [];
        $summary = [];      // product|colour|size => qty
        $totals = ['ordered' => 0, 'submitted' => 0, 'value' => 0, 'extra' => 0];
        foreach (roster_players($pdo, $year) as $pl) {
            $o = load_order($pdo, $year, $pl['id'], $cfg['allowance']);
            $orders[] = ['player_id' => (int)$pl['id'], 'name' => $pl['name'], 'team' => $pl['team']] + $o;
            if ($o['status'] !== 'none' && $o['items']) $totals['ordered']++;
            if ($o['status'] === 'submitted') $totals['submitted']++;
            $totals['value'] += $o['total'];
            $totals['extra'] += $o['extra'];
            foreach ($o['items'] as $i) {
                $p = $byId[$i['product_id']] ?? null;
                $colour = ($p && !$p['team_colored']) ? '-' : $pl['team'];
                $k = $i['product_id'] . '|' . $colour . '|' . $i['size'];
                if (!isset($summary[$k])) {
                    $summary[$k] = ['product_id' => $i['product_id'], 'name' => $i['name'], 'colour' => $colour,
                                    'size' => $i['size'], 'qty' => 0, 'value' => 0];
                }
                $summary[$k]['qty']   += $i['qty'];
                $summary[$k]['value'] += $i['qty'] * $i['unit_price'];
            }
        }
        $summary = array_values($summary);
        usort($summary, function ($a, $b) use ($byId) {
            $sa = $byId[$a['product_id']]['sort_order'] ?? 0; $sb = $byId[$b['product_id']]['sort_order'] ?? 0;
            return [$sa, $a['name'], $a['colour'], $a['size']] <=> [$sb, $b['name'], $b['colour'], $b['size']];
        });
        $totals['players'] = count($orders);
        out(['config' => $cfg, 'products' => $prods, 'orders' => $orders, 'summary' => $summary, 'totals' => $totals]);
    }

    if ($action === 'export') {
        require_di_admin($pdo);
        $year = valid_year($_GET['year'] ?? 0);
        $type = ($_GET['type'] ?? 'lines') === 'summary' ? 'summary' : 'lines';
        $cfg  = shop_config($pdo, $year);
        $team_colored = [];
        foreach (products($pdo, $year, false) as $p) $team_colored[$p['id']] = $p['team_colored'];

        header('Content-Type: text/csv; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"di-shop-$year-$type.csv\"");
        $f = fopen('php://output', 'w');
        fwrite($f, "\xEF\xBB\xBF");   // BOM so Excel reads æøå correctly
        $colour_name = function ($c) { return $c === 'blue' ? 'Blue' : ($c === 'red' ? 'Red' : '-'); };

        if ($type === 'lines') {
            fputcsv($f, ['Player', 'Team', 'Status', 'Product', 'Colour', 'Size', 'Qty', 'Unit price', 'Line total', 'Order total', 'Allowance', 'Extra to bill player'], ';');
            foreach (roster_players($pdo, $year) as $pl) {
                $o = load_order($pdo, $year, $pl['id'], $cfg['allowance']);
                foreach ($o['items'] as $i) {
                    $colour = empty($team_colored[$i['product_id']]) ? '-' : $pl['team'];
                    fputcsv($f, [$pl['name'], $colour_name($pl['team']), $o['status'], $i['name'], $colour_name($colour),
                                 $i['size'], $i['qty'], $i['unit_price'], $i['qty'] * $i['unit_price'],
                                 $o['total'], $cfg['allowance'], $o['extra']], ';');
                }
            }
        } else {
            fputcsv($f, ['Product', 'Colour', 'Size', 'Qty'], ';');
            $sum = [];
            foreach (roster_players($pdo, $year) as $pl) {
                $o = load_order($pdo, $year, $pl['id'], $cfg['allowance']);
                foreach ($o['items'] as $i) {
                    $colour = empty($team_colored[$i['product_id']]) ? '-' : $pl['team'];
                    $k = $i['name'] . '|' . $colour . '|' . $i['size'];
                    $sum[$k] = ($sum[$k] ?? 0) + $i['qty'];
                }
            }
            ksort($sum);
            foreach ($sum as $k => $q) {
                [$name, $colour, $size] = explode('|', $k);
                fputcsv($f, [$name, $colour_name($colour), $size, $q], ';');
            }
        }
        fclose($f);
        exit;
    }

    out(['error' => 'Unknown action'], 400);
}

// ── POST ─────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $data   = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $data['action'] ?? '';

    if ($action === 'save_order') {
        $acc  = require_di_account($pdo);
        $year = valid_year($data['year'] ?? 0);
        $cfg  = shop_config($pdo, $year);
        if (!$cfg['is_open'] && !$acc['is_admin']) out(['error' => 'The shop is not open yet'], 403);
        if ($cfg['locked'])                          out(['error' => 'The order deadline has passed'], 403);
        if (!on_roster($pdo, $year, $acc['player_id'])) out(['error' => "You're not on the $year roster"], 403);

        $catalogue = [];
        foreach (products($pdo, $year, true) as $p) $catalogue[$p['id']] = $p;

        // Validate + merge lines
        $lines = [];
        foreach (($data['items'] ?? []) as $it) {
            $pid  = (int)($it['product_id'] ?? 0);
            $qty  = (int)($it['qty'] ?? 0);
            $size = trim((string)($it['size'] ?? ''));
            if ($qty <= 0) continue;
            $p = $catalogue[$pid] ?? null;
            if (!$p) out(['error' => 'Unknown product'], 400);
            if ($p['sizes']) {
                if (!in_array($size, $p['sizes'], true)) out(['error' => "Choose a size for {$p['name']}"], 400);
            } else {
                $size = '';
            }
            $k = $pid . '|' . $size;
            $lines[$k] = ['product_id' => $pid, 'size' => $size, 'qty' => ($lines[$k]['qty'] ?? 0) + $qty, 'price' => $p['price']];
        }
        // Max quantity per product (across sizes)
        $perProduct = [];
        foreach ($lines as $l) $perProduct[$l['product_id']] = ($perProduct[$l['product_id']] ?? 0) + $l['qty'];
        foreach ($perProduct as $pid => $q) {
            if ($q > $catalogue[$pid]['max_qty']) out(['error' => "Max {$catalogue[$pid]['max_qty']} × {$catalogue[$pid]['name']}"], 400);
        }

        $stmt = $pdo->prepare("SELECT team FROM players WHERE id = ?");
        $stmt->execute([$acc['player_id']]);
        $team   = $stmt->fetchColumn();
        $submit = !empty($data['submit']);
        if ($submit && !$lines) out(['error' => 'Your order is empty'], 400);

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO shop_orders (year, player_id, team, status, submitted_at)
                           VALUES (?, ?, ?, ?, ?)
                           ON DUPLICATE KEY UPDATE team = VALUES(team), status = VALUES(status),
                               submitted_at = VALUES(submitted_at), updated_at = NOW()")
                ->execute([$year, $acc['player_id'], $team, $submit ? 'submitted' : 'draft', $submit ? date('Y-m-d H:i:s') : null]);
            $stmt = $pdo->prepare("SELECT id FROM shop_orders WHERE year = ? AND player_id = ?");
            $stmt->execute([$year, $acc['player_id']]);
            $order_id = (int)$stmt->fetchColumn();
            $pdo->prepare("DELETE FROM shop_order_items WHERE order_id = ?")->execute([$order_id]);
            $ins = $pdo->prepare("INSERT INTO shop_order_items (order_id, product_id, size, qty, unit_price) VALUES (?, ?, ?, ?, ?)");
            foreach ($lines as $l) $ins->execute([$order_id, $l['product_id'], $l['size'], $l['qty'], $l['price']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            out(['error' => 'Could not save order'], 500);
        }
        out(['ok' => true, 'order' => load_order($pdo, $year, $acc['player_id'], $cfg['allowance'])]);
    }

    // Everything below is admin-only
    require_di_admin($pdo);

    if ($action === 'save_config') {
        $year      = valid_year($data['year'] ?? 0);
        $allowance = max(0, (int)($data['allowance'] ?? DEFAULT_ALLOWANCE));
        $deadline  = trim((string)($data['deadline'] ?? ''));
        if ($deadline !== '') {
            $ts = strtotime(str_replace('T', ' ', $deadline));
            if ($ts === false) out(['error' => 'Invalid deadline'], 400);
            $deadline = date('Y-m-d H:i:s', $ts);
        } else {
            $deadline = null;
        }
        $pdo->prepare("INSERT INTO shop_config (year, allowance, deadline, is_open) VALUES (?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE allowance = VALUES(allowance), deadline = VALUES(deadline), is_open = VALUES(is_open)")
            ->execute([$year, $allowance, $deadline, !empty($data['is_open']) ? 1 : 0]);
        out(['ok' => true, 'config' => shop_config($pdo, $year)]);
    }

    if ($action === 'save_product') {
        $id    = (int)($data['id'] ?? 0);
        $year  = valid_year($data['year'] ?? 0);
        $name  = trim((string)($data['name'] ?? ''));
        $price = (int)($data['price'] ?? -1);
        if ($name === '') out(['error' => 'Name required'], 400);
        if ($price < 0)   out(['error' => 'Price required'], 400);
        $list  = ($data['list_price'] ?? '') === '' || $data['list_price'] === null ? null : (int)$data['list_price'];
        $sizes = is_array($data['sizes'] ?? null) ? implode(',', $data['sizes']) : (string)($data['sizes'] ?? '');
        $sizes = implode(',', array_values(array_filter(array_map('trim', explode(',', $sizes)), 'strlen')));
        $vals = [
            $year, $name, trim((string)($data['description'] ?? '')) ?: null, $list, $price, $sizes ?: null,
            !empty($data['team_colored']) ? 1 : 0, !empty($data['has_logo']) ? 1 : 0,
            trim((string)($data['image_url'] ?? '')) ?: null, max(1, (int)($data['max_qty'] ?? 5)),
            (int)($data['sort_order'] ?? 0), !empty($data['active']) ? 1 : 0,
        ];
        if ($id) {
            $vals[] = $id;
            $pdo->prepare("UPDATE shop_products SET year=?, name=?, description=?, list_price=?, price=?, sizes=?,
                           team_colored=?, has_logo=?, image_url=?, max_qty=?, sort_order=?, active=? WHERE id=?")
                ->execute($vals);
        } else {
            $pdo->prepare("INSERT INTO shop_products (year, name, description, list_price, price, sizes,
                           team_colored, has_logo, image_url, max_qty, sort_order, active)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")->execute($vals);
            $id = (int)$pdo->lastInsertId();
        }
        out(['ok' => true, 'id' => $id]);
    }

    if ($action === 'delete_product') {
        $id = (int)($data['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM shop_order_items WHERE product_id = ?");
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() > 0) out(['error' => 'Someone has ordered this product — set it inactive instead'], 409);
        $pdo->prepare("DELETE FROM shop_products WHERE id = ?")->execute([$id]);
        out(['ok' => true]);
    }

    if ($action === 'reopen_order') {
        $year = valid_year($data['year'] ?? 0);
        $pdo->prepare("UPDATE shop_orders SET status = 'draft', submitted_at = NULL WHERE year = ? AND player_id = ?")
            ->execute([$year, (int)($data['player_id'] ?? 0)]);
        out(['ok' => true]);
    }

    out(['error' => 'Unknown action'], 400);
}

out(['error' => 'Method not allowed'], 405);
