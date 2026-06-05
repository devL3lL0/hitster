<?php
/**
 * Hitster Camp — Migration Engine
 *
 * Contiene tutte le definizioni delle migrazioni e la funzione runner.
 * Include questo file dove hai bisogno di eseguire o controllare migrazioni.
 *
 * Ogni migrazione è idempotente: viene saltata se già applicata.
 * Le migrazioni applicate sono tracciate in `hitster_migrations`.
 */

require_once __DIR__ . '/db.php';

// ─── Definizione Migrazioni ───────────────────────────────────────────────────
// ⚠️  Non modificare mai 'version' di una migrazione già applicata in produzione.
// ⚠️  Per cambiare qualcosa di già migrato, aggiungi una NUOVA migrazione in fondo.

function get_migrations(): array {
    return [

        // ── v1.0.0 ── Schema iniziale ─────────────────────────────────────────
        [
            'version'     => '1.0.0_initial_schema',
            'description' => 'Schema iniziale: sessioni, cache, token Spotify, config',
            'up' => [
                "CREATE TABLE IF NOT EXISTS hitster_sessions (
                    code         VARCHAR(6)   PRIMARY KEY,
                    age_label    VARCHAR(100) NOT NULL,
                    teams        MEDIUMTEXT   NOT NULL,
                    stands       MEDIUMTEXT   NOT NULL,
                    songs_queue  LONGTEXT     NOT NULL,
                    used_ids     TEXT         NOT NULL,
                    stands_info  MEDIUMTEXT   NOT NULL,
                    created_at   INT          NOT NULL,
                    updated_at   INT          NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "CREATE TABLE IF NOT EXISTS hitster_song_cache (
                    age_key   VARCHAR(20) PRIMARY KEY,
                    songs     LONGTEXT    NOT NULL,
                    cached_at INT         NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "CREATE TABLE IF NOT EXISTS hitster_spotify_token (
                    id           INT          PRIMARY KEY DEFAULT 1,
                    access_token VARCHAR(512) NOT NULL,
                    expires_at   INT          NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

                "CREATE TABLE IF NOT EXISTS hitster_config (
                    key_name VARCHAR(100) PRIMARY KEY,
                    value    TEXT
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ],
        ],

        // ── v1.4.0 ── Artisti seed dinamici ──────────────────────────────────
        [
            'version'     => '1.4.0_artist_seeds',
            'description' => 'Artisti seed per fascia d\'età (auto-popolati + admin)',
            'up' => [
                "CREATE TABLE IF NOT EXISTS hitster_artist_seeds (
                    id          INT AUTO_INCREMENT PRIMARY KEY,
                    artist_name VARCHAR(255) NOT NULL,
                    spotify_id  VARCHAR(100) NOT NULL,
                    age_group   VARCHAR(20)  NOT NULL,
                    genre       VARCHAR(50)  NULL,
                    popularity  INT          DEFAULT 0,
                    source      ENUM('auto','admin') DEFAULT 'auto',
                    active      TINYINT(1)   DEFAULT 1,
                    created_at  INT          NOT NULL,
                    updated_at  INT          NOT NULL,
                    UNIQUE KEY uq_spotify_age (spotify_id, age_group),
                    INDEX idx_age_active (age_group, active)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ],
        ],

        [
            'version'     => '1.4.0_seed_refresh_log',
            'description' => 'Log dei refresh automatici degli artisti seed',
            'up' => [
                "CREATE TABLE IF NOT EXISTS hitster_seed_refresh_log (
                    id               INT AUTO_INCREMENT PRIMARY KEY,
                    triggered_by     ENUM('cron','admin') DEFAULT 'cron',
                    service_name     VARCHAR(100) NULL,
                    artists_found    INT DEFAULT 0,
                    artists_added    INT DEFAULT 0,
                    artists_updated  INT DEFAULT 0,
                    duration_ms      INT DEFAULT 0,
                    ran_at           INT NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ],
        ],

        [
            'version'     => '1.4.0_cron_services',
            'description' => 'Servizi di monitoraggio esterni con token cron (UptimeRobot ecc.)',
            'up' => [
                "CREATE TABLE IF NOT EXISTS hitster_cron_services (
                    id             INT AUTO_INCREMENT PRIMARY KEY,
                    service_name   VARCHAR(100) NOT NULL,
                    token          VARCHAR(128) NOT NULL UNIQUE,
                    description    VARCHAR(255) NULL,
                    active         TINYINT(1)   DEFAULT 1,
                    last_called_at INT          NULL,
                    created_at     INT          NOT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            ],
        ],

        // ── Future migrazioni — aggiungi qui sotto ────────────────────────────
        // [
        //     'version'     => '1.5.0_example',
        //     'description' => 'Descrizione',
        //     'up' => ["ALTER TABLE ..."],
        // ],

    ];
}

// ─── Runner ───────────────────────────────────────────────────────────────────

/**
 * Esegue tutte le migrazioni non ancora applicate.
 *
 * @return array {
 *   applied:  int   — migrazioni appena eseguite
 *   skipped:  int   — già presenti, saltate
 *   errors:   array — errori eventuali
 *   log:      array — dettaglio riga per riga
 *   has_new:  bool  — true se è stata applicata almeno 1 migrazione
 * }
 */
function run_migrations(): array {
    $db = DB::getInstance();

    // Crea la tabella di controllo se non esiste (bootstrap)
    $db->exec("CREATE TABLE IF NOT EXISTS hitster_migrations (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        version    VARCHAR(100) NOT NULL UNIQUE,
        applied_at INT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Versioni già applicate
    $done = array_column(
        $db->query("SELECT version FROM hitster_migrations")->fetchAll(),
        'version'
    );

    $applied = $skipped = 0;
    $errors  = [];
    $log     = [];

    foreach (get_migrations() as $m) {
        $ver  = $m['version'];
        $desc = $m['description'];

        if (in_array($ver, $done)) {
            $skipped++;
            $log[] = ['status' => 'skip', 'version' => $ver, 'description' => $desc];
            continue;
        }

        $ok = true;
        foreach ($m['up'] as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                $ok      = false;
                $errors[] = "[$ver] " . $e->getMessage();
                $log[]   = ['status' => 'error', 'version' => $ver, 'description' => $desc, 'error' => $e->getMessage()];
                break;
            }
        }

        if ($ok) {
            $db->prepare("INSERT INTO hitster_migrations (version, applied_at) VALUES (?, ?)")
               ->execute([$ver, time()]);
            $applied++;
            $log[] = ['status' => 'ok', 'version' => $ver, 'description' => $desc];
        }
    }

    return [
        'applied' => $applied,
        'skipped' => $skipped,
        'errors'  => $errors,
        'log'     => $log,
        'has_new' => $applied > 0,
    ];
}

/**
 * Controlla quante migrazioni sono in attesa senza eseguirle.
 * Utile per mostrare un badge/avviso nell'admin.
 */
function count_pending_migrations(): int {
    $db = DB::getInstance();

    // Se la tabella non esiste ancora, tutte le migrazioni sono pending
    try {
        $done = array_column(
            $db->query("SELECT version FROM hitster_migrations")->fetchAll(),
            'version'
        );
    } catch (PDOException $e) {
        return count(get_migrations());
    }

    $pending = 0;
    foreach (get_migrations() as $m) {
        if (!in_array($m['version'], $done)) $pending++;
    }
    return $pending;
}
