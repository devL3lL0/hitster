<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

// ─── Auth ────────────────────────────────────────────────────────────────────

function spotify_get_token($client_id, $client_secret) {
    $db   = DB::getInstance();
    $stmt = $db->query("SELECT access_token, expires_at FROM hitster_spotify_token WHERE id = 1");
    $row  = $stmt->fetch();

    if ($row && $row['expires_at'] > time() + 60) {
        return $row['access_token'];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://accounts.spotify.com/api/token');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($client_id . ':' . $client_secret),
        'Content-Type: application/x-www-form-urlencoded',
    ]);
    $result = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($result, true);
    if (isset($data['access_token'])) {
        $token   = $data['access_token'];
        $expires = time() + $data['expires_in'];
        $stmt    = $db->prepare("INSERT INTO hitster_spotify_token (id, access_token, expires_at) VALUES (1, ?, ?) ON DUPLICATE KEY UPDATE access_token=?, expires_at=?");
        $stmt->execute([$token, $expires, $token, $expires]);
        return $token;
    }

    return null;
}

function spotify_request($url, $token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
    $result = curl_exec($ch);
    curl_close($ch);
    return json_decode($result, true) ?? [];
}

// ─── Age Group Helper ────────────────────────────────────────────────────────
// Converte un range numerico (es. 14, 17) nel gruppo usato in DB e nei profili.

function age_range_to_group($min_age, $max_age) {
    $avg = ($min_age + $max_age) / 2;
    if ($avg <= 11) return '8-11';
    if ($avg <= 14) return '12-14';
    if ($avg <= 17) return '14-17';
    if ($avg <= 22) return '18-22';
    return '23+';
}

// ─── DB Seed Loader ───────────────────────────────────────────────────────────
// Carica gli artisti seed attivi per una fascia d'età dal database.

function load_artist_seeds($age_group) {
    $db   = DB::getInstance();
    $stmt = $db->prepare(
        "SELECT spotify_id, artist_name, genre FROM hitster_artist_seeds
          WHERE age_group = ? AND active = 1
          ORDER BY popularity DESC"
    );
    $stmt->execute([$age_group]);
    return $stmt->fetchAll();
}

// ─── Artist Top Tracks Fetcher ────────────────────────────────────────────────
// Per ogni artista seed, recupera le sue top-tracks nel mercato italiano.
// Queste canzoni hanno source_bonus = 4 (il massimo) per privilegiarle nel rank.

function fetch_artist_top_tracks(array $seeds, $token) {
    $tracks = [];
    foreach ($seeds as $seed) {
        $url = "https://api.spotify.com/v1/artists/{$seed['spotify_id']}/top-tracks?market=IT";
        $res = spotify_request($url, $token);
        foreach ($res['tracks'] ?? [] as $t) {
            $tracks[] = [$t, 4]; // source_bonus massimo: artista italiano certificato
        }
    }
    return $tracks;
}

// ─── Dynamic Age Profile ──────────────────────────────────────────────────────
// Costruisce i termini di ricerca in modo dinamico a partire da CURRENT_YEAR.
// Nessun anno o ID playlist è hardcoded: tutto viene calcolato al runtime.

function build_age_profile($min_age, $max_age) {
    $avg = ($min_age + $max_age) / 2;
    $y   = CURRENT_YEAR;
    $y1  = $y - 1;
    $y2  = $y - 2;
    $y3  = $y - 3;
    $y4  = $y - 4;
    $y5  = $y - 5;

    if ($avg <= 11) {
        // Bambini: solo hit recenti e molto popolari, niente esplicito.
        // Year from ristretto: i bambini conoscono solo gli ultimi 5 anni.
        return [
            'year_from'         => $y - 5,
            'pop_threshold'     => 65,
            'allow_explicit'    => false,
            'penalize_explicit' => false,
            'playlist_terms'    => [
                "top 50 italia",
                "viral 50 italia",
                "pop italiano $y",
                "sanremo $y $y1",
                "hits italia $y1 $y",
            ],
            'track_terms'       => [
                "pop italiano $y1 $y",
                "sanremo $y1 $y",
            ],
        ];
    }

    if ($avg <= 14) {
        // Pre-teen: pop hit recenti, TikTok, Sanremo, zero esplicito.
        return [
            'year_from'         => $y - 6,
            'pop_threshold'     => 65,
            'allow_explicit'    => false,
            'penalize_explicit' => false,
            'playlist_terms'    => [
                "top 50 italia",
                "viral 50 italia",
                "tiktok italia $y",
                "pop italiano $y $y1",
                "sanremo $y $y1",
                "hits italia $y1",
            ],
            'track_terms'       => [
                "pop italiano hit $y1 $y",
                "sanremo $y1 $y",
                "tiktok italia $y1 $y",
            ],
        ];
    }

    if ($avg <= 17) {
        // Teen: pop, rap e trap — MA senza esplicito (filtro assoluto).
        // Gli under 18 ai camp non dovrebbero sentire contenuti espliciti.
        return [
            'year_from'         => $y - 7,
            'pop_threshold'     => 60,
            'allow_explicit'    => false,
            'penalize_explicit' => false,
            'playlist_terms'    => [
                "top 50 italia",
                "viral 50 italia",
                "tiktok italia",
                "pop italiano $y",
                "rap italiano $y",
                "sanremo $y $y1",
            ],
            'track_terms'       => [
                "pop italiano hit $y2 $y1 $y",
                "rap italiano $y1 $y",
                "sanremo $y2 $y1 $y",
                "tiktok viral italia $y1 $y",
            ],
        ];
    }

    if ($avg <= 22) {
        // Giovani adulti: rap, pop anni 2010-2020, esplicito penalizzato.
        return [
            'year_from'         => $y - 11,
            'pop_threshold'     => 55,
            'allow_explicit'    => true,
            'penalize_explicit' => true,
            'playlist_terms'    => [
                "top 50 italia",
                "viral 50 italia",
                "rap italiano",
                "pop italiano $y3 $y4",
                "hits italia anni 2010 2020",
            ],
            'track_terms'       => [
                "pop italiano hit $y4 $y3 $y2 $y1",
                "rap italiano $y4 $y3 $y2 $y1",
                "sanremo $y4 $y3 $y2 $y1 $y",
            ],
        ];
    }

    // Adulti / fascia ampia: classici + moderni, esplicito penalizzato.
    return [
        'year_from'         => $y - 21,
        'pop_threshold'     => 50,
        'allow_explicit'    => true,
        'penalize_explicit' => true,
        'playlist_terms'    => [
            "top 50 italia",
            "hits italiane",
            "classici italiani",
            "sanremo classici",
            "cantautori italiani",
        ],
        'track_terms'       => [
            "pop italiano classici hits",
            "cantautori italiani famosi",
            "sanremo classici hits",
            "hits italia " . ($y - 15) . " " . ($y - 10) . " " . ($y - 5),
        ],
    ];
}

// ─── Playlist Discovery ───────────────────────────────────────────────────────
// Cerca su Spotify le playlist italiane che corrispondono ai termini del profilo.
// Restituisce una mappa playlist_id => source_bonus (le prime query valgono di più).

function discover_playlists_for_profile($profile, $token, $per_query = 5) {
    $playlist_map = []; // pid => max_bonus

    foreach ($profile['playlist_terms'] as $i => $term) {
        // I primi termini (più specifici) hanno un bonus sorgente maggiore
        if ($i === 0)      $bonus = 3;
        elseif ($i <= 2)   $bonus = 2;
        else               $bonus = 1;

        $url = "https://api.spotify.com/v1/search?q=" . urlencode($term)
             . "&type=playlist&market=IT&limit={$per_query}";
        $res = spotify_request($url, $token);

        foreach ($res['playlists']['items'] ?? [] as $pl) {
            if (empty($pl['id'])) continue;
            $pid = $pl['id'];
            // Le playlist editoriali di Spotify (owner.id = 'spotify') ricevono +1 bonus
            // extra perché sono curate e contengono brani più noti e rilevanti
            $is_editorial = ($pl['owner']['id'] ?? '') === 'spotify';
            $effective    = $is_editorial ? $bonus + 1 : $bonus;
            $playlist_map[$pid] = max($playlist_map[$pid] ?? 0, $effective);
        }
    }

    return $playlist_map;
}

// ─── Scoring ─────────────────────────────────────────────────────────────────
// Il bonus "artista italiano" è ora applicato tramite un set dinamico passato
// come parametro — nessuna lista hardcoded.

function score_track($track, $profile, $source_bonus = 0, array $italian_set = []) {
    $score = $source_bonus;

    // Bonus popolarità
    $pop = $track['popularity'] ?? 0;
    if ($pop >= 80) $score += 4;
    elseif ($pop >= 70) $score += 3;
    elseif ($pop >= 60) $score += 2;
    elseif ($pop >= 50) $score += 1;

    // Bonus recency
    $age = CURRENT_YEAR - intval($track['year'] ?? 0);
    if ($age <= 1)      $score += 4;
    elseif ($age <= 2)  $score += 3;
    elseif ($age <= 3)  $score += 2;
    elseif ($age <= 5)  $score += 1;

    // Bonus artista italiano (rilevato dinamicamente)
    $artist_key = strtolower(trim($track['artist'] ?? ''));
    if (!empty($italian_set[$artist_key])) $score += 3; // +3 per artisti italiani (rilevati dinamicamente)
    if (!empty($track['explicit']) && $profile['penalize_explicit']) $score -= 5; // penalizzazione forte contenuti espliciti

    return $score;
}

// ─── Main Fetch ───────────────────────────────────────────────────────────────
// Orchestrazione completa: scoperta playlist → raccolta brani → rilevamento
// artisti italiani dinamico → scoring finale → ordinamento e shuffle.

function fetch_songs_smart($min_age, $max_age, $token) {
    if (!$token) return [];

    $profile   = build_age_profile($min_age, $max_age);
    $year_from = $profile['year_from'];

    // $raw: tid => [track_data, max_source_bonus]
    $raw  = [];
    // Frequenza artisti nelle playlist italiane scoperte
    // → determina dinamicamente chi è "italiano"
    $freq = [];

    // Closure per aggiungere un brano alla raccolta
    $ingest = function ($t, $bonus) use (&$raw, &$freq, $profile, $year_from) {
        if (empty($t['id'])) return;
        if (!empty($t['explicit']) && !$profile['allow_explicit']) return;
        // Filtro popolarità minima assoluta: esclude brani oscuri/di nicchia
        // indipendentemente da quanto siano recenti o italiani
        if (($t['popularity'] ?? 0) < 40) return;

        $year = intval(substr($t['album']['release_date'] ?? '0', 0, 4));
        if ($year < $year_from) return;

        $tid    = $t['id'];
        $artist = $t['artists'][0]['name'] ?? 'Unknown';

        // Conteggio frequenza artisti (case-insensitive)
        $key       = strtolower($artist);
        $freq[$key] = ($freq[$key] ?? 0) + 1;

        $td = [
            'title'       => $t['name'],
            'artist'      => $artist,
            'id'          => $tid,
            'year'        => $year > 0 ? (string)$year : '???',
            'preview_url' => $t['preview_url'] ?? null,
            'spotify_url' => $t['external_urls']['spotify'] ?? null,
            'explicit'    => $t['explicit'] ?? false,
            'popularity'  => $t['popularity'] ?? 0,
        ];

        // Teniamo l'entry con il bonus sorgente più alto
        if (!isset($raw[$tid]) || $raw[$tid][1] < $bonus) {
            $raw[$tid] = [$td, $bonus];
        }
    };

    // ── 0. Artisti seed dal DB → top-tracks italiane (source_bonus massimo) ────
    // Questi brani vanno sicuramente in cima: sono top-tracks di artisti italiani
    // noti per questa fascia d'età, certificati dal refresh periodico.
    $age_group = age_range_to_group($min_age, $max_age);
    $seeds     = load_artist_seeds($age_group);
    if (!empty($seeds)) {
        $seed_tracks = fetch_artist_top_tracks($seeds, $token);
        foreach ($seed_tracks as [$t, $bonus]) {
            $ingest($t, $bonus);
        }
    }

    // ── 1. Scoperta playlist dinamica ────────────────────────────────────────
    $playlist_map = discover_playlists_for_profile($profile, $token);

    // ── 2. Fetch brani dalle playlist scoperte ───────────────────────────────
    foreach ($playlist_map as $pid => $bonus) {
        $url = "https://api.spotify.com/v1/playlists/{$pid}/tracks"
             . "?limit=100&market=IT"
             . "&fields=items(track(id,name,artists,album(release_date),explicit,popularity,preview_url,external_urls))";
        $res = spotify_request($url, $token);
        foreach ($res['items'] ?? [] as $item) {
            if (!empty($item['track'])) {
                $ingest($item['track'], $bonus);
            }
        }
    }

    // ── 3. Ricerche dirette di brani per copertura extra ─────────────────────
    foreach ($profile['track_terms'] as $q) {
        $url = "https://api.spotify.com/v1/search?q=" . urlencode($q)
             . "&type=track&limit=50&market=IT";
        $res = spotify_request($url, $token);
        foreach ($res['tracks']['items'] ?? [] as $t) {
            if (($t['popularity'] ?? 0) >= $profile['pop_threshold']) {
                $ingest($t, 1);
            }
        }
    }

    // ── 4. Rilevamento artisti italiani dinamico ─────────────────────────────
    // Un artista è considerato "italiano" se appare 2+ volte nelle playlist
    // italiane scoperte — nessuna lista hardcoded necessaria.
    $italian_set = array_fill_keys(
        array_keys(array_filter($freq, fn($count) => $count >= 2)),
        true
    );

    // ── 5. Scoring finale con set italiano dinamico ───────────────────────────
    $scored = [];
    foreach ($raw as $tid => [$td, $bonus]) {
        $scored[] = [$td, score_track($td, $profile, $bonus, $italian_set)];
    }

    // ── 6. Ordinamento + shuffle per bucket ──────────────────────────────────
    // Ordina per score decrescente, poi mischia brani dello stesso "livello"
    // per variare l'ordine mantenendo la qualità complessiva.
    usort($scored, fn($a, $b) => $b[1] <=> $a[1]);

    $result = [];
    $bucket = [];
    $prev   = null;

    foreach ($scored as [$td, $sc]) {
        if ($prev === null) $prev = $sc;
        if (abs($sc - $prev) > 2) {
            shuffle($bucket);
            $result = array_merge($result, $bucket);
            $bucket = [];
            $prev   = $sc;
        }
        $bucket[] = $td;
    }
    shuffle($bucket);
    $result = array_merge($result, $bucket);

    return array_slice($result, 0, 300);
}

// ─── Cache Layer ──────────────────────────────────────────────────────────────
// Invariato: la cache a 30 minuti su DB riduce il carico sulle API Spotify.

function get_cached_songs($min_age, $max_age, $token) {
    $db  = DB::getInstance();
    $key = "{$min_age}-{$max_age}";

    $stmt = $db->prepare("SELECT songs, cached_at FROM hitster_song_cache WHERE age_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    if ($row && (time() - $row['cached_at']) < CACHE_TTL) {
        $songs = json_decode($row['songs'], true);
        if (!empty($songs)) {
            shuffle($songs);
            return $songs;
        }
    }

    $songs = fetch_songs_smart($min_age, $max_age, $token);

    if (!empty($songs)) {
        $stmt = $db->prepare("INSERT INTO hitster_song_cache (age_key, songs, cached_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE songs=?, cached_at=?");
        $json = json_encode($songs, JSON_UNESCAPED_UNICODE);
        $now  = time();
        $stmt->execute([$key, $json, $now, $json, $now]);
    }

    shuffle($songs);
    return $songs;
}
