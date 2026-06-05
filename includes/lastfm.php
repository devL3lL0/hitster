<?php
/**
 * Last.fm API Helper
 * Docs: https://www.last.fm/api
 *
 * Usato per arricchire la scoperta di artisti italiani per fascia d'età,
 * complementando i dati di Spotify con quelli della community Last.fm.
 */

// ─── Request Helper ───────────────────────────────────────────────────────────

function lastfm_request($method, array $params, $api_key) {
    $params['method']  = $method;
    $params['api_key'] = $api_key;
    $params['format']  = 'json';

    $url = 'https://ws.audioscrobbler.com/2.0/?' . http_build_query($params);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'HitsterCamp/1.0');
    $result = curl_exec($ch);
    curl_close($ch);

    return json_decode($result, true) ?? [];
}

// ─── Artisti per TAG (genere italiano) ───────────────────────────────────────

/**
 * Restituisce i nomi degli artisti più ascoltati per un dato tag Last.fm.
 * Es: "rap italiano", "trap italiana", "cantautorato italiano"
 */
function lastfm_get_artists_by_tag($tag, $api_key, $limit = 30) {
    $res     = lastfm_request('tag.gettopartists', ['tag' => $tag, 'limit' => $limit], $api_key);
    $artists = [];
    foreach ($res['topartists']['artist'] ?? [] as $a) {
        if (!empty($a['name'])) $artists[] = trim($a['name']);
    }
    return $artists;
}

// ─── Artisti top in Italia (geo chart) ────────────────────────────────────────

/**
 * Restituisce i nomi degli artisti più ascoltati in Italia
 * secondo gli utenti Last.fm italiani.
 */
function lastfm_get_top_artists_italy($api_key, $limit = 40) {
    $res     = lastfm_request('geo.gettopartists', ['country' => 'italy', 'limit' => $limit], $api_key);
    $artists = [];
    foreach ($res['topartists']['artist'] ?? [] as $a) {
        if (!empty($a['name'])) $artists[] = trim($a['name']);
    }
    return $artists;
}

// ─── Tag per fascia d'età ─────────────────────────────────────────────────────

/**
 * Restituisce i tag Last.fm da usare per ogni fascia d'età.
 * I tag riflettono i generi preferiti da quella fascia demografica.
 */
function lastfm_tags_for_age_group($age_group) {
    return match($age_group) {
        '8-11'  => ['pop italiano', 'musica italiana'],
        '12-14' => ['pop italiano', 'musica italiana', 'pop italia'],
        '14-17' => ['rap italiano', 'trap italiana', 'indie italiano', 'pop italiano'],
        '18-22' => ['rap italiano', 'hip hop italiano', 'indie italiano', 'trap italiana'],
        '23+'   => ['cantautorato italiano', 'rock italiano', 'pop italiano', 'musica italiana'],
        default => ['pop italiano', 'musica italiana'],
    };
}
