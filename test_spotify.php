<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/spotify.php';

$config = load_config();
$cid    = $config['spotify']['client_id'];
$cs     = $config['spotify']['client_secret'];

echo "=== Hitster Camp – Spotify Diagnostic ===\n\n";
echo "Client ID:     " . ($cid ? "IMPOSTATO (" . strlen($cid) . " char)" : "❌ VUOTO") . "\n";
echo "Client Secret: " . ($cs  ? "IMPOSTATO (" . strlen($cs)  . " char)" : "❌ VUOTO") . "\n\n";

if (empty($cid) || empty($cs)) {
    echo "ERRORE: Credenziali Spotify mancanti. Configurale nel pannello Admin.\n";
    exit;
}

// ─── Token ────────────────────────────────────────────────────────────────────
echo "--- 1. Test token Spotify ---\n";
$token = spotify_get_token($cid, $cs);
if (!$token) {
    echo "❌ Impossibile ottenere il token. Controlla le credenziali.\n";
    exit;
}
echo "✅ Token ottenuto: " . substr($token, 0, 20) . "...\n\n";

// ─── Playlist discovery per fascia d'età ──────────────────────────────────────
$test_groups = [
    ['min' => 8,  'max' => 11, 'label' => '8-11 anni'],
    ['min' => 12, 'max' => 14, 'label' => '12-14 anni'],
    ['min' => 15, 'max' => 18, 'label' => '15-18 anni'],
];

foreach ($test_groups as $g) {
    echo "--- 2. Playlist Discovery: {$g['label']} ---\n";
    $profile      = build_age_profile($g['min'], $g['max']);
    $playlist_map = discover_playlists_for_profile($profile, $token, 3);

    echo "  Playlist scoperte: " . count($playlist_map) . "\n";
    foreach ($playlist_map as $pid => $bonus) {
        // Fetch nome playlist
        $info = spotify_request("https://api.spotify.com/v1/playlists/{$pid}?fields=name,followers(total)", $token);
        $name = $info['name'] ?? $pid;
        $followers = number_format($info['followers']['total'] ?? 0);
        echo "  [bonus:{$bonus}] {$name} ({$followers} followers)\n";
    }
    echo "\n";
}

// ─── Fetch brani completo (solo per prima fascia) ─────────────────────────────
echo "--- 3. Fetch completo brani per 12-14 anni ---\n";
$songs = fetch_songs_smart(12, 14, $token);
echo "  Brani trovati: " . count($songs) . "\n";

if (!empty($songs)) {
    echo "  Top 10:\n";
    foreach (array_slice($songs, 0, 10) as $i => $s) {
        echo "  " . ($i + 1) . ". {$s['title']} – {$s['artist']} ({$s['year']}) [pop:{$s['popularity']}]\n";
    }
} else {
    echo "  ❌ Nessun brano trovato. Controlla la connessione Spotify.\n";
}

echo "\n=== Fine diagnostic ===\n";
