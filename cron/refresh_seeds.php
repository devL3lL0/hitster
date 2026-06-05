<?php
/**
 * Hitster Camp — Artist Seeds Refresh Endpoint
 *
 * Chiamato da UptimeRobot o qualsiasi servizio di monitoraggio esterno.
 * Protetto da token per servizio, rate-limited a 6 ore per servizio.
 *
 * Utilizzo:
 *   GET /cron/refresh_seeds.php?token=YOUR_SERVICE_TOKEN
 *
 * Risposte:
 *   200 { "status": "ok", "duration_ms": ..., "results": { ... } }
 *   403 { "status": "forbidden", "error": "..." }
 *   429 { "status": "skipped", "reason": "...", "next_run_in_min": ... }
 *   500 { "status": "error", "error": "..." }
 */

header('Content-Type: application/json; charset=utf-8');

// Impedisce che eventuali errori PHP corrompano il JSON in output
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => "PHP Error: {$errstr} in {$errfile}:{$errline}"]);
    exit;
});

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/spotify.php';
require_once __DIR__ . '/../includes/lastfm.php';

$start_time = microtime(true);

// ─── 1. Validazione Token ─────────────────────────────────────────────────────

$provided_token = trim($_GET['token'] ?? '');
if (empty($provided_token)) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden', 'error' => 'Missing token']);
    exit;
}

$db   = DB::getInstance();
$stmt = $db->prepare("SELECT id, service_name, last_called_at FROM hitster_cron_services WHERE token = ? AND active = 1 LIMIT 1");
$stmt->execute([$provided_token]);
$service = $stmt->fetch();

if (!$service) {
    http_response_code(403);
    echo json_encode(['status' => 'forbidden', 'error' => 'Invalid or inactive token']);
    exit;
}

// ─── 2. Rate Limiting (minimo 6 ore tra un run e l'altro per servizio) ────────

$min_interval_seconds = 6 * 3600;
if ($service['last_called_at']) {
    $elapsed = time() - (int)$service['last_called_at'];
    if ($elapsed < $min_interval_seconds) {
        $wait_min = ceil(($min_interval_seconds - $elapsed) / 60);
        http_response_code(429);
        echo json_encode([
            'status'          => 'skipped',
            'service'         => $service['service_name'],
            'reason'          => 'Minimum interval of 6h not yet elapsed.',
            'next_run_in_min' => $wait_min,
        ]);
        exit;
    }
}

// Aggiorna last_called_at subito per bloccare run paralleli
$db->prepare("UPDATE hitster_cron_services SET last_called_at = ? WHERE id = ?")
   ->execute([time(), $service['id']]);

// ─── 3. Carica Configurazioni ─────────────────────────────────────────────────

$config      = load_config();
$spotify_cid = $config['spotify']['client_id']     ?? '';
$spotify_cs  = $config['spotify']['client_secret'] ?? '';
$lastfm_key  = $config['lastfm']['api_key']        ?? null;

if (empty($spotify_cid) || empty($spotify_cs)) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => 'Spotify credentials not configured']);
    exit;
}

$spotify_token = spotify_get_token($spotify_cid, $spotify_cs);
if (!$spotify_token) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => 'Could not obtain Spotify access token']);
    exit;
}

// ─── 4. Configurazione per fascia d'età ──────────────────────────────────────
// Definisce: quali categorie Spotify usare, popolarità minima, massimo artisti da salvare

$age_group_config = [
    '8-11'  => [
        'spotify_categories' => ['pop', 'family'],
        'min_popularity'     => 60,
        'max_artists'        => 25,
    ],
    '12-14' => [
        'spotify_categories' => ['pop', 'toplists'],
        'min_popularity'     => 58,
        'max_artists'        => 25,
    ],
    '14-17' => [
        'spotify_categories' => ['hiphop', 'pop', 'indie_alt'],
        'min_popularity'     => 55,
        'max_artists'        => 30,
    ],
    '18-22' => [
        'spotify_categories' => ['hiphop', 'indie_alt', 'pop'],
        'min_popularity'     => 50,
        'max_artists'        => 30,
    ],
    '23+'   => [
        'spotify_categories' => ['pop', 'decades', 'romance'],
        'min_popularity'     => 45,
        'max_artists'        => 25,
    ],
];

$all_results = [];
$now         = time();

// ─── 5. Discovery per ogni fascia d'età ──────────────────────────────────────

foreach ($age_group_config as $age_group => $cfg) {

    // candidates: spotify_id => ['name', 'freq', 'popularity', 'genre']
    $candidates = [];

    // ── 5a. Spotify: Category Playlists (editoriali per Italia) ──────────────
    foreach ($cfg['spotify_categories'] as $category) {
        $cat_url = "https://api.spotify.com/v1/browse/categories/{$category}/playlists?country=IT&limit=3";
        $cat_res = spotify_request($cat_url, $spotify_token);

        foreach ($cat_res['playlists']['items'] ?? [] as $playlist) {
            if (empty($playlist['id'])) continue;

            $tracks_url = "https://api.spotify.com/v1/playlists/{$playlist['id']}/tracks"
                        . "?limit=100&market=IT&fields=items(track(artists,popularity))";
            $tracks_res = spotify_request($tracks_url, $spotify_token);

            foreach ($tracks_res['items'] ?? [] as $item) {
                $track = $item['track'] ?? null;
                if (empty($track['artists'])) continue;

                $track_pop = $track['popularity'] ?? 0;
                $artist    = $track['artists'][0];
                if (empty($artist['id'])) continue;

                $aid = $artist['id'];
                if (!isset($candidates[$aid])) {
                    $candidates[$aid] = [
                        'name'       => $artist['name'],
                        'freq'       => 0,
                        'popularity' => $track_pop,
                        'genre'      => $category,
                    ];
                }
                $candidates[$aid]['freq']++;
                // Tiene la popolarità massima vista
                $candidates[$aid]['popularity'] = max($candidates[$aid]['popularity'], $track_pop);
            }
        }
    }

    // ── 5b. Last.fm: artisti per tag + chart Italia ───────────────────────────
    if (!empty($lastfm_key)) {
        $lastfm_names = lastfm_get_top_artists_italy($lastfm_key, 40);

        $tags = lastfm_tags_for_age_group($age_group);
        foreach ($tags as $tag) {
            $tag_artists = lastfm_get_artists_by_tag($tag, $lastfm_key, 30);
            $lastfm_names = array_merge($lastfm_names, $tag_artists);
        }
        $lastfm_names = array_unique($lastfm_names);

        // Fa il match di ogni nome Last.fm su Spotify per ottenere l'ID
        foreach (array_slice($lastfm_names, 0, 50) as $artist_name) {
            $search_url = "https://api.spotify.com/v1/search?q=artist:" . urlencode($artist_name)
                        . "&type=artist&market=IT&limit=1";
            $search_res = spotify_request($search_url, $spotify_token);
            $found      = $search_res['artists']['items'][0] ?? null;
            if (empty($found['id'])) continue;

            // Verifica fuzzy match del nome (almeno 75% simile)
            similar_text(
                mb_strtolower(trim($artist_name)),
                mb_strtolower(trim($found['name'])),
                $similarity_pct
            );
            if ($similarity_pct < 75) continue;

            $aid = $found['id'];
            $pop = $found['popularity'] ?? 0;

            if (!isset($candidates[$aid])) {
                $candidates[$aid] = [
                    'name'       => $found['name'],
                    'freq'       => 0,
                    'popularity' => $pop,
                    'genre'      => 'lastfm',
                ];
            }
            // Last.fm match conta doppio nella frequenza (segnale più forte)
            $candidates[$aid]['freq']       += 2;
            $candidates[$aid]['popularity']  = max($candidates[$aid]['popularity'], $pop);
        }
    }

    // ── 5c. Filtra: frequenza >= 2 + popolarità minima ────────────────────────
    $filtered = array_filter($candidates, fn($c) =>
        $c['freq'] >= 2 && $c['popularity'] >= $cfg['min_popularity']
    );

    // Ordina per (freq × popularity) desc e prende i top N
    uasort($filtered, fn($a, $b) =>
        ($b['freq'] * $b['popularity']) <=> ($a['freq'] * $a['popularity'])
    );
    $top = array_slice($filtered, 0, $cfg['max_artists'], true);

    // ── 5d. Salva in DB ───────────────────────────────────────────────────────
    $added = $updated = 0;

    foreach ($top as $spotify_artist_id => $data) {
        $check_stmt = $db->prepare("SELECT id, source FROM hitster_artist_seeds WHERE spotify_id = ? AND age_group = ?");
        $check_stmt->execute([$spotify_artist_id, $age_group]);
        $existing = $check_stmt->fetch();

        if ($existing) {
            // Non sovrascrivere MAI gli artisti aggiunti manualmente dall'admin
            if ($existing['source'] === 'admin') continue;

            $db->prepare("UPDATE hitster_artist_seeds SET popularity = ?, updated_at = ? WHERE id = ?")
               ->execute([$data['popularity'], $now, $existing['id']]);
            $updated++;
        } else {
            $db->prepare("INSERT INTO hitster_artist_seeds
                (artist_name, spotify_id, age_group, genre, popularity, source, active, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, 'auto', 1, ?, ?)")
               ->execute([
                   $data['name'], $spotify_artist_id, $age_group,
                   $data['genre'], $data['popularity'], $now, $now,
               ]);
            $added++;
        }
    }

    $all_results[$age_group] = [
        'found'   => count($top),
        'added'   => $added,
        'updated' => $updated,
    ];
}

// ─── 6. Invalida la cache canzoni ────────────────────────────────────────────
// I nuovi seed devono essere usati alla prossima partita creata
$db->exec("DELETE FROM hitster_song_cache");

// ─── 7. Logga il run ─────────────────────────────────────────────────────────
$duration_ms     = (int)round((microtime(true) - $start_time) * 1000);
$total_found     = array_sum(array_column($all_results, 'found'));
$total_added     = array_sum(array_column($all_results, 'added'));
$total_updated   = array_sum(array_column($all_results, 'updated'));

$db->prepare("INSERT INTO hitster_seed_refresh_log
    (triggered_by, service_name, artists_found, artists_added, artists_updated, duration_ms, ran_at)
    VALUES ('cron', ?, ?, ?, ?, ?, ?)")
   ->execute([$service['service_name'], $total_found, $total_added, $total_updated, $duration_ms, $now]);

// ─── 8. Risposta JSON ─────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'status'        => 'ok',
    'service'       => $service['service_name'],
    'duration_ms'   => $duration_ms,
    'cache_cleared' => true,
    'totals'        => [
        'found'   => $total_found,
        'added'   => $total_added,
        'updated' => $total_updated,
    ],
    'by_age_group' => $all_results,
    'ran_at'       => date('c', $now),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
