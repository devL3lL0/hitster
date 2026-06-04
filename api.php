<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/spotify.php';
require_once __DIR__ . '/includes/session_store.php';
require_once __DIR__ . '/includes/totp.php';

$action = $_GET['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    if (isset($body['action'])) $action = $body['action'];
} else {
    $body = $_GET;
}


$config = load_config();
custom_session_gc(); // Pulisce sessioni vecchie

switch ($action) {
    case 'admin_check':
        echo json_encode([
            "is_admin" => !empty($_SESSION['is_admin']),
            "needs_setup" => empty($config['totp_secret'])
        ]);
        break;

    case 'admin_setup':
        if (!empty($config['totp_secret']) && ($body['password'] ?? '') !== $config['admin_password']) {
            http_response_code(403);
            echo json_encode(["error" => "Password errata"]);
            exit;
        }
        $secret = $config['totp_secret'] ?: totp_generate_secret();
        if (empty($config['totp_secret'])) {
            $config['totp_secret'] = $secret;
            save_config($config);
        }
        echo json_encode(["uri" => totp_provisioning_uri($secret, "HitsterCamp", "HitsterCamp")]);
        break;

    case 'admin_verify':
        $code = str_replace(" ", "", $body['code'] ?? '');
        if (totp_verify($config['totp_secret'], $code)) {
            $_SESSION['is_admin'] = true;
            echo json_encode(["ok" => true]);
        } else {
            http_response_code(401);
            echo json_encode(["error" => "Codice errato"]);
        }
        break;

    case 'admin_logout':
        unset($_SESSION['is_admin']);
        header('Location: index.php');
        exit;

    case 'reset_2fa':
        if (empty($_SESSION['is_admin'])) {
            http_response_code(403);
            echo json_encode(["error" => "403"]);
            exit;
        }
        $config['totp_secret'] = "";
        save_config($config);
        echo json_encode(["ok" => true]);
        break;

    case 'create':
        $age_id = $body['age_group'] ?? '';
        $age_info = null;
        foreach ($config['age_groups'] as $g) {
            if ($g['id'] === $age_id) { $age_info = $g; break; }
        }
        if (!$age_info) $age_info = $config['age_groups'][0];

        $token = spotify_get_token($config['spotify']['client_id'], $config['spotify']['client_secret']);
        $songs = get_cached_songs($age_info['min_age'] ?? 10, $age_info['max_age'] ?? 15, $token);
        
        if (empty($songs)) {
            http_response_code(400);
            echo json_encode([
                "ok" => false,
                "error" => "No songs",
                "message" => "Impossibile caricare le canzoni. Verifica che il Client ID e il Client Secret di Spotify siano corretti nell'area Admin, che la connessione sia attiva, e prova a ricreare la partita."
            ]);
            exit;
        }

        $code = strtoupper(substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 6));
        while (session_get($code)) {
            $code = strtoupper(substr(str_shuffle("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"), 0, 6));
        }

        session_create($code, $age_info['label'], $songs, $config['stands_info']);
        echo json_encode(["ok" => true, "code" => $code]);
        break;

    case 'session':
        $code = strtoupper($body['code'] ?? '');
        $sess = session_get($code);
        if (!$sess) {
            http_response_code(404);
            echo json_encode(["error" => "404"]);
        } else {
            echo json_encode(session_snapshot($sess));
        }
        break;

    case 'next_song':
        $code = strtoupper($body['code'] ?? '');
        $stand_id = (string)($body['stand'] ?? '');
        $sess = session_get($code);
        if (!$sess || !$stand_id || !isset($sess['stands'][$stand_id])) {
            http_response_code(400);
            echo json_encode(["error" => "Invalid stand"]);
            exit;
        }

        $used_ids = $sess['used_ids'];
        foreach ($sess['stands'] as $s) {
            if (!empty($s['current_song'])) {
                $used_ids[] = $s['current_song']['id'];
            }
        }
        
        $song = null;
        $remaining = [];
        foreach ($sess['songs_queue'] as $s) {
            if ($song === null && !in_array($s['id'], $used_ids)) {
                $song = $s;
            } else {
                $remaining[] = $s;
            }
        }
        
        $sess['songs_queue'] = $remaining;
        
        if (!$song) {
            http_response_code(404);
            echo json_encode(["error" => "No songs", "message" => "Tutte le canzoni sono già state utilizzate in questa sessione."]);
            exit;
        }
        
        $sess['used_ids'][] = $song['id'];
        $sess['stands'][$stand_id]['current_song'] = $song;
        session_save($sess);
        echo json_encode(["ok" => true, "song" => $song]);
        break;

    case 'confirm_song':
        $code = strtoupper($body['code'] ?? '');
        $stand_id = (string)($body['stand'] ?? '');
        $sess = session_get($code);
        if ($sess && isset($sess['stands'][$stand_id])) {
            $song = $sess['stands'][$stand_id]['current_song'];
            if ($song) {
                $sess['stands'][$stand_id]['history'][] = $song;
                usort($sess['stands'][$stand_id]['history'], function($a, $b) {
                    return intval($a['year']) - intval($b['year']);
                });
                $sess['stands'][$stand_id]['current_song'] = null;
                session_save($sess);
            }
        }
        echo json_encode(["ok" => true]);
        break;

    case 'add_team':
        $code = strtoupper($body['code'] ?? '');
        $name = trim($body['name'] ?? '');
        $sess = session_get($code);
        if (!$sess || !$name || isset($sess['teams'][$name])) {
            http_response_code(400);
            echo json_encode(["error" => "Bad"]);
            exit;
        }
        $sess['teams'][$name] = 0;
        session_save($sess);
        echo json_encode(["ok" => true]);
        break;

    case 'remove_team':
        $code = strtoupper($body['code'] ?? '');
        $name = $body['name'] ?? '';
        $sess = session_get($code);
        if ($sess && isset($sess['teams'][$name])) {
            unset($sess['teams'][$name]);
            session_save($sess);
        }
        echo json_encode(["ok" => true]);
        break;

    case 'set_stand':
        $code = strtoupper($body['code'] ?? '');
        $stand_id = (string)($body['stand'] ?? '');
        $t1 = $body['team1'] ?? null;
        $t2 = $body['team2'] ?? null;
        $sess = session_get($code);
        if ($sess && isset($sess['stands'][$stand_id])) {
            $sess['stands'][$stand_id]['team1'] = $t1;
            $sess['stands'][$stand_id]['team2'] = $t2;
            session_save($sess);
        }
        echo json_encode(["ok" => true]);
        break;

    case 'add_points':
        $code = strtoupper($body['code'] ?? '');
        $team = $body['team'] ?? '';
        $pts = intval($body['points'] ?? 0);
        $sess = session_get($code);
        if ($sess && isset($sess['teams'][$team])) {
            $sess['teams'][$team] += $pts;
            session_save($sess);
        }
        echo json_encode(["ok" => true]);
        break;

    case 'reset_scores':
        $code = strtoupper($body['code'] ?? '');
        $sess = session_get($code);
        if ($sess) {
            foreach ($sess['teams'] as $t => $v) {
                $sess['teams'][$t] = 0;
            }
            session_save($sess);
        }
        echo json_encode(["ok" => true]);
        break;

    case 'delete':
        $code = strtoupper($body['code'] ?? '');
        session_delete($code);
        echo json_encode(["ok" => true]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["error" => "Invalid action"]);
        break;
}
