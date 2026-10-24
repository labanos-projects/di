<?php
// Schema migrations — idempotent, safe to call on every request

// Create a di_accounts row (with an unguessable invite token) for any player
// that doesn't have one yet. Cheap no-op once everyone has an account.
function ensure_player_accounts($pdo) {
    $missing = $pdo->query("
        SELECT p.id FROM players p
        LEFT JOIN di_accounts a ON a.player_id = p.id
        WHERE a.id IS NULL
    ")->fetchAll(PDO::FETCH_COLUMN);
    if (!$missing) return;
    $stmt = $pdo->prepare("INSERT IGNORE INTO di_accounts (player_id, invite_token) VALUES (?, ?)");
    foreach ($missing as $pid) {
        $stmt->execute([(int)$pid, bin2hex(random_bytes(32))]);
    }
}

function run_migrations($pdo) {

    $pdo->exec("CREATE TABLE IF NOT EXISTS players (
        id     INT AUTO_INCREMENT PRIMARY KEY,
        name   VARCHAR(100) NOT NULL,
        team   ENUM('blue','red') NOT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        UNIQUE KEY uniq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS tournaments (
        id   INT AUTO_INCREMENT PRIMARY KEY,
        year INT NOT NULL,
        UNIQUE KEY uniq_year (year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rounds (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        tournament_id INT NOT NULL,
        round_number  TINYINT NOT NULL,
        format        ENUM('fourball','greensome','foursome','singles') NOT NULL,
        UNIQUE KEY uniq_round (tournament_id, round_number),
        CONSTRAINT fk_rounds_tourn FOREIGN KEY (tournament_id) REFERENCES tournaments(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS matches (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        round_id     INT NOT NULL,
        match_number TINYINT NOT NULL,
        UNIQUE KEY uniq_match (round_id, match_number),
        CONSTRAINT fk_matches_round FOREIGN KEY (round_id) REFERENCES rounds(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS match_results (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        match_id   INT NOT NULL,
        player_id  INT NOT NULL,
        points     TINYINT NULL     COMMENT '0=loss 1=halved 2=win NULL=upcoming/not played yet',
        ups        INT NULL DEFAULT NULL COMMENT 'positive=win margin negative=loss margin 0=halved NULL=not played yet',
        partner_id INT DEFAULT NULL,
        UNIQUE KEY uniq_result (match_id, player_id),
        CONSTRAINT fk_mr_match   FOREIGN KEY (match_id)   REFERENCES matches(id),
        CONSTRAINT fk_mr_player  FOREIGN KEY (player_id)  REFERENCES players(id),
        CONSTRAINT fk_mr_partner FOREIGN KEY (partner_id) REFERENCES players(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Per-year roster. If there are no rows for a given year the year falls
    // back to "all active players" (keeps past years working without back-fill).
    $pdo->exec("CREATE TABLE IF NOT EXISTS roster_assignments (
        year       INT NOT NULL,
        player_id  INT NOT NULL,
        PRIMARY KEY (year, player_id),
        KEY idx_ra_player (player_id),
        CONSTRAINT fk_ra_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Allow NULL for upcoming matches (players scheduled, not yet played)
    // Safe to re-run — MySQL silently succeeds if column is already nullable
    try { $pdo->exec("ALTER TABLE match_results MODIFY COLUMN points TINYINT NULL COMMENT '0=loss 1=halved 2=win NULL=not played yet'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE match_results MODIFY COLUMN ups INT NULL DEFAULT NULL COMMENT 'hole margin NULL=not played'"); } catch (Exception $e) {}

    // Legacy years: historical results with no individual match data
    try { $pdo->exec("ALTER TABLE tournaments ADD COLUMN legacy TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE tournaments ADD COLUMN blue_score_override INT NULL DEFAULT NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE tournaments ADD COLUMN red_score_override  INT NULL DEFAULT NULL"); } catch (Exception $e) {}

    // ── Player accounts (one login per player) ───────────────────────────
    // Kept separate from the shared `users` table so DI players get no access
    // to investtracker / fitness_buddy. email + password_hash stay NULL until
    // the player opens their invite link and sets them.
    $pdo->exec("CREATE TABLE IF NOT EXISTS di_accounts (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        player_id       INT NOT NULL,
        email           VARCHAR(200) NULL,
        password_hash   VARCHAR(255) NULL,
        invite_token    CHAR(64) NULL,
        is_admin        TINYINT(1) NOT NULL DEFAULT 0,
        failed_logins   INT NOT NULL DEFAULT 0,
        locked_until    DATETIME NULL,
        activated_at    DATETIME NULL,
        last_login_at   DATETIME NULL,
        created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_acc_player (player_id),
        UNIQUE KEY uniq_acc_email  (email),
        UNIQUE KEY uniq_acc_invite (invite_token),
        CONSTRAINT fk_acc_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Login sessions. Only a SHA-256 of the bearer token is stored.
    $pdo->exec("CREATE TABLE IF NOT EXISTS di_sessions (
        token_hash  CHAR(64) PRIMARY KEY,
        account_id  INT NOT NULL,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at  DATETIME NOT NULL,
        KEY idx_sess_account (account_id),
        CONSTRAINT fk_sess_account FOREIGN KEY (account_id) REFERENCES di_accounts(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Every player gets an account row (with a fresh invite token) — this
    // also covers players added later via the admin panel.
    ensure_player_accounts($pdo);

    // ── Player shop (team merchandise ordered via GolfExperten) ─────────
    // One config row per tournament year: allowance (DKK, against the discounted
    // price), order deadline, and whether players can see the shop yet.
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_config (
        year        INT PRIMARY KEY,
        allowance   INT NOT NULL DEFAULT 300,
        deadline    DATETIME NULL,
        is_open     TINYINT(1) NOT NULL DEFAULT 0,
        updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Catalogue per year. price = our discounted price (what counts against the
    // allowance and what GolfExperten bills); list_price is shown struck through.
    // sizes: comma-separated (e.g. 'S,M,L,XL') or NULL for one-size items.
    // team_colored: delivered in the player's team colour (blue/red).
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_products (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        year          INT NOT NULL,
        name          VARCHAR(100) NOT NULL,
        description   VARCHAR(500) NULL,
        list_price    INT NULL,
        price         INT NOT NULL,
        sizes         VARCHAR(200) NULL,
        team_colored  TINYINT(1) NOT NULL DEFAULT 1,
        has_logo      TINYINT(1) NOT NULL DEFAULT 1,
        image_url     VARCHAR(500) NULL,
        max_qty       INT NOT NULL DEFAULT 5,
        sort_order    INT NOT NULL DEFAULT 0,
        active        TINYINT(1) NOT NULL DEFAULT 1,
        KEY idx_shop_products_year (year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // One order per player per year. status: draft until the player submits.
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_orders (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        year          INT NOT NULL,
        player_id     INT NOT NULL,
        status        ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
        team          ENUM('blue','red') NOT NULL,
        submitted_at  DATETIME NULL,
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_shop_order (year, player_id),
        CONSTRAINT fk_shop_order_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Lines on an order. unit_price is a snapshot of products.price when saved.
    $pdo->exec("CREATE TABLE IF NOT EXISTS shop_order_items (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        order_id    INT NOT NULL,
        product_id  INT NOT NULL,
        size        VARCHAR(20) NOT NULL DEFAULT '',
        qty         INT NOT NULL,
        unit_price  INT NOT NULL,
        UNIQUE KEY uniq_shop_line (order_id, product_id, size),
        CONSTRAINT fk_shop_item_order   FOREIGN KEY (order_id)   REFERENCES shop_orders(id)   ON DELETE CASCADE,
        CONSTRAINT fk_shop_item_product FOREIGN KEY (product_id) REFERENCES shop_products(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 2018: Blue 18 – Red 12 (match data not available)
    try {
        $pdo->exec("INSERT INTO tournaments (year, legacy, blue_score_override, red_score_override)
                    VALUES (2018, 1, 18, 12)
                    ON DUPLICATE KEY UPDATE legacy = 1, blue_score_override = 18, red_score_override = 12");
    } catch (Exception $e) {}
}
