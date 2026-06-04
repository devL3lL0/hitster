-- Sessioni di gioco attive
CREATE TABLE IF NOT EXISTS hitster_sessions (
    code         VARCHAR(6)   PRIMARY KEY,
    age_label    VARCHAR(100) NOT NULL,
    teams        MEDIUMTEXT   NOT NULL, -- DEFAULT '{}',
    stands       MEDIUMTEXT   NOT NULL, -- DEFAULT '{}',
    songs_queue  LONGTEXT     NOT NULL, -- DEFAULT '[]',
    used_ids     TEXT         NOT NULL, -- DEFAULT '[]',
    stands_info  MEDIUMTEXT   NOT NULL, -- DEFAULT '[]',
    created_at   INT          NOT NULL,
    updated_at   INT          NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cache canzoni Spotify (per fascia d'età)
CREATE TABLE IF NOT EXISTS hitster_song_cache (
    age_key    VARCHAR(20)  PRIMARY KEY,   -- es: "8-11", "12-14"
    songs      LONGTEXT     NOT NULL,      -- JSON array
    cached_at  INT          NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Token Spotify (OAuth2 client credentials)
CREATE TABLE IF NOT EXISTS hitster_spotify_token (
    id           INT         PRIMARY KEY DEFAULT 1,
    access_token VARCHAR(512) NOT NULL,
    expires_at   INT         NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Configurazione app (chiave/valore)
CREATE TABLE IF NOT EXISTS hitster_config (
    key_name  VARCHAR(100) PRIMARY KEY,
    value     TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
