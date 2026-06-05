-- Sessioni di gioco attive
CREATE TABLE IF NOT EXISTS hitster_sessions (
    code         VARCHAR(6)   PRIMARY KEY,
    age_label    VARCHAR(100) NOT NULL,
    teams        MEDIUMTEXT   NOT NULL,
    stands       MEDIUMTEXT   NOT NULL,
    songs_queue  LONGTEXT     NOT NULL,
    used_ids     TEXT         NOT NULL,
    stands_info  MEDIUMTEXT   NOT NULL,
    created_at   INT          NOT NULL,
    updated_at   INT          NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cache canzoni Spotify (per fascia d'età)
CREATE TABLE IF NOT EXISTS hitster_song_cache (
    age_key    VARCHAR(20)  PRIMARY KEY,
    songs      LONGTEXT     NOT NULL,
    cached_at  INT          NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Token Spotify (OAuth2 client credentials)
CREATE TABLE IF NOT EXISTS hitster_spotify_token (
    id           INT          PRIMARY KEY DEFAULT 1,
    access_token VARCHAR(512) NOT NULL,
    expires_at   INT          NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurazione app (chiave/valore)
CREATE TABLE IF NOT EXISTS hitster_config (
    key_name  VARCHAR(100) PRIMARY KEY,
    value     TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Artisti seed per fascia d'età (auto-popolati + aggiungibili dall'admin)
CREATE TABLE IF NOT EXISTS hitster_artist_seeds (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    artist_name VARCHAR(255) NOT NULL,
    spotify_id  VARCHAR(100) NOT NULL,
    age_group   VARCHAR(20)  NOT NULL,           -- '8-11','12-14','14-17','18-22','23+'
    genre       VARCHAR(50)  NULL,               -- 'pop','rap','trap','indie','rock','classici'
    popularity  INT          DEFAULT 0,          -- 0-100 da Spotify
    source      ENUM('auto','admin') DEFAULT 'auto',
    active      TINYINT(1)   DEFAULT 1,
    created_at  INT          NOT NULL,
    updated_at  INT          NOT NULL,
    UNIQUE KEY uq_spotify_age (spotify_id, age_group),
    INDEX idx_age_active (age_group, active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Log dei refresh automatici dei seed
CREATE TABLE IF NOT EXISTS hitster_seed_refresh_log (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    triggered_by     ENUM('cron','admin') DEFAULT 'cron',
    service_name     VARCHAR(100) NULL,
    artists_found    INT DEFAULT 0,
    artists_added    INT DEFAULT 0,
    artists_updated  INT DEFAULT 0,
    duration_ms      INT DEFAULT 0,
    ran_at           INT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Servizi di monitoraggio esterni (UptimeRobot, Freshping, ecc.)
-- Ogni servizio ha il proprio token per autenticarsi all'endpoint cron
CREATE TABLE IF NOT EXISTS hitster_cron_services (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    service_name   VARCHAR(100) NOT NULL,        -- "UptimeRobot", "Freshping", ecc.
    token          VARCHAR(128) NOT NULL UNIQUE, -- token segreto per questo servizio
    description    VARCHAR(255) NULL,            -- note opzionali
    active         TINYINT(1)   DEFAULT 1,
    last_called_at INT          NULL,
    created_at     INT          NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
