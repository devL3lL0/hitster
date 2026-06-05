<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/spotify.php';
require_once __DIR__ . '/includes/session_store.php';
require_once __DIR__ . '/includes/totp.php';
require_once __DIR__ . '/includes/migrations.php';

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

    case 'run_migrations':
        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(["error"=>"403"]); exit; }
        $result = run_migrations();
        echo json_encode([
            'applied' => $result['applied'],
            'skipped' => $result['skipped'],
            'errors'  => $result['errors'],
            'log'     => $result['log'],
        ]);
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

    // ─── Artist Seeds ─────────────────────────────────────────────────────────

    case 'seed_add':
        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(["error"=>"403"]); exit; }
        $artist_name = trim($body['artist_name'] ?? '');
        $age_group   = trim($body['age_group']   ?? '');
        if (!$artist_name || !$age_group) { http_response_code(400); echo json_encode(["error"=>"Missing fields"]); exit; }

        // Cerca l'artista su Spotify
        $sp_token = spotify_get_token($config['spotify']['client_id'], $config['spotify']['client_secret']);
        $search_url = "https://api.spotify.com/v1/search?q=artist:" . urlencode($artist_name) . "&type=artist&market=IT&limit=3";
        $search_res = spotify_request($search_url, $sp_token);
        $found_artist = null;
        foreach ($search_res['artists']['items'] ?? [] as $candidate) {
            similar_text(mb_strtolower($artist_name), mb_strtolower($candidate['name']), $pct);
            if ($pct >= 70) { $found_artist = $candidate; break; }
        }
        if (!$found_artist) { echo json_encode(["error"=>"Artista non trovato su Spotify. Prova con il nome esatto."]); exit; }

        $db = DB::getInstance();
        try {
            $now = time();
            $db->prepare("INSERT INTO hitster_artist_seeds (artist_name,spotify_id,age_group,popularity,source,active,created_at,updated_at) VALUES (?,?,?,?,'admin',1,?,?)")
               ->execute([$found_artist['name'], $found_artist['id'], $age_group, $found_artist['popularity']??0, $now, $now]);
            echo json_encode(["status"=>"ok", "artist_name"=>$found_artist['name']]);
        } catch (Exception $e) {
            echo json_encode(["error"=>"Artista già presente per questa fascia d'età."]);
        }
        break;

    case 'seed_toggle':
        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(["error"=>"403"]); exit; }
        $db = DB::getInstance();
        $db->prepare("UPDATE hitster_artist_seeds SET active=?, updated_at=? WHERE id=?")
           ->execute([(int)($body['active']??0), time(), (int)($body['id']??0)]);
        echo json_encode(["status"=>"ok"]);
        break;

    case 'seed_delete':
        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(["error"=>"403"]); exit; }
        $db = DB::getInstance();
        $stmt = $db->prepare("SELECT source FROM hitster_artist_seeds WHERE id=?");
        $stmt->execute([(int)($body['id']??0)]);
        $row = $stmt->fetch();
        if (!$row) { echo json_encode(["error"=>"Not found"]); exit; }
        if ($row['source'] !== 'admin') { echo json_encode(["error"=>"Solo gli artisti aggiunti manualmente possono essere eliminati."]); exit; }
        $db->prepare("DELETE FROM hitster_artist_seeds WHERE id=?")->execute([(int)$body['id']]);
        echo json_encode(["status"=>"ok"]);
        break;

    case 'seeds_refresh':
        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(["error"=>"403"]); exit; }
        // Include la stessa logica del cron endpoint, senza rate-limit (è admin)
        require_once __DIR__ . '/includes/lastfm.php';
        $start_t = microtime(true);
        $sp_token = spotify_get_token($config['spotify']['client_id'], $config['spotify']['client_secret']);
        if (!$sp_token) { echo json_encode(["error"=>"Impossibile ottenere token Spotify"]); exit; }

        $lastfm_key = $config['lastfm']['api_key'] ?? null;
        $age_group_config = [
            '8-11'  => ['spotify_categories'=>['pop','family'],              'min_popularity'=>60,'max_artists'=>25],
            '12-14' => ['spotify_categories'=>['pop','toplists'],            'min_popularity'=>58,'max_artists'=>25],
            '14-17' => ['spotify_categories'=>['hiphop','pop','indie_alt'],  'min_popularity'=>55,'max_artists'=>30],
            '18-22' => ['spotify_categories'=>['hiphop','indie_alt','pop'],  'min_popularity'=>50,'max_artists'=>30],
            '23+'   => ['spotify_categories'=>['pop','decades','romance'],   'min_popularity'=>45,'max_artists'=>25],
        ];
        $all_results = [];
        $db = DB::getInstance();
        $now = time();
        foreach ($age_group_config as $age_group => $cfg) {
            $candidates = [];
            foreach ($cfg['spotify_categories'] as $cat) {
                $cat_res = spotify_request("https://api.spotify.com/v1/browse/categories/{$cat}/playlists?country=IT&limit=3", $sp_token);
                foreach ($cat_res['playlists']['items'] ?? [] as $pl) {
                    if (empty($pl['id'])) continue;
                    $tr = spotify_request("https://api.spotify.com/v1/playlists/{$pl['id']}/tracks?limit=100&market=IT&fields=items(track(artists,popularity))", $sp_token);
                    foreach ($tr['items'] ?? [] as $item) {
                        $track = $item['track'] ?? null;
                        if (empty($track['artists'][0]['id'])) continue;
                        $aid = $track['artists'][0]['id'];
                        $pop = $track['popularity'] ?? 0;
                        if (!isset($candidates[$aid])) $candidates[$aid]=['name'=>$track['artists'][0]['name'],'freq'=>0,'popularity'=>$pop,'genre'=>$cat];
                        $candidates[$aid]['freq']++;
                        $candidates[$aid]['popularity'] = max($candidates[$aid]['popularity'], $pop);
                    }
                }
            }
            if (!empty($lastfm_key)) {
                $lnames = lastfm_get_top_artists_italy($lastfm_key, 40);
                foreach (lastfm_tags_for_age_group($age_group) as $tag) $lnames = array_merge($lnames, lastfm_get_artists_by_tag($tag, $lastfm_key, 30));
                foreach (array_unique(array_slice($lnames, 0, 50)) as $lname) {
                    $sr = spotify_request("https://api.spotify.com/v1/search?q=artist:".urlencode($lname)."&type=artist&market=IT&limit=1", $sp_token);
                    $fa = $sr['artists']['items'][0] ?? null;
                    if (empty($fa['id'])) continue;
                    similar_text(mb_strtolower($lname), mb_strtolower($fa['name']), $pct);
                    if ($pct < 75) continue;
                    $aid = $fa['id'];
                    if (!isset($candidates[$aid])) $candidates[$aid]=['name'=>$fa['name'],'freq'=>0,'popularity'=>$fa['popularity']??0,'genre'=>'lastfm'];
                    $candidates[$aid]['freq'] += 2;
                    $candidates[$aid]['popularity'] = max($candidates[$aid]['popularity'], $fa['popularity']??0);
                }
            }
            $filtered = array_filter($candidates, fn($c)=>$c['freq']>=2 && $c['popularity']>=$cfg['min_popularity']);
            uasort($filtered, fn($a,$b)=>($b['freq']*$b['popularity'])<=>($a['freq']*$a['popularity']));
            $top = array_slice($filtered, 0, $cfg['max_artists'], true);
            $added=$updated=0;
            foreach ($top as $sid => $data) {
                $chk=$db->prepare("SELECT id,source FROM hitster_artist_seeds WHERE spotify_id=? AND age_group=?");
                $chk->execute([$sid,$age_group]); $ex=$chk->fetch();
                if ($ex) { if($ex['source']==='admin') continue; $db->prepare("UPDATE hitster_artist_seeds SET popularity=?,updated_at=? WHERE id=?")->execute([$data['popularity'],$now,$ex['id']]); $updated++; }
                else { $db->prepare("INSERT INTO hitster_artist_seeds (artist_name,spotify_id,age_group,genre,popularity,source,active,created_at,updated_at) VALUES(?,?,?,?,?,'auto',1,?,?)")->execute([$data['name'],$sid,$age_group,$data['genre'],$data['popularity'],$now,$now]); $added++; }
            }
            $all_results[$age_group]=['found'=>count($top),'added'=>$added,'updated'=>$updated];
        }
        $db->exec("DELETE FROM hitster_song_cache");
        $dur=(int)round((microtime(true)-$start_t)*1000);
        $tf=array_sum(array_column($all_results,'found')); $ta=array_sum(array_column($all_results,'added')); $tu=array_sum(array_column($all_results,'updated'));
        $db->prepare("INSERT INTO hitster_seed_refresh_log (triggered_by,service_name,artists_found,artists_added,artists_updated,duration_ms,ran_at) VALUES('admin','admin',?,?,?,?,?)")->execute([$tf,$ta,$tu,$dur,$now]);
        echo json_encode(["status"=>"ok","duration_ms"=>$dur,"totals"=>["found"=>$tf,"added"=>$ta,"updated"=>$tu],"by_age_group"=>$all_results]);
        break;

    // ─── Cron Services ────────────────────────────────────────────────────────

    case 'service_add':
        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(["error"=>"403"]); exit; }
        $svc_name = trim($body['service_name'] ?? '');
        $svc_desc = trim($body['description']  ?? '');
        if (!$svc_name) { echo json_encode(["error"=>"Nome servizio obbligatorio"]); exit; }
        $token = bin2hex(random_bytes(32)); // 64 chars hex
        $db = DB::getInstance();
        $db->prepare("INSERT INTO hitster_cron_services (service_name,token,description,active,created_at) VALUES(?,?,?,1,?)")
           ->execute([$svc_name, $token, $svc_desc, time()]);
        echo json_encode(["status"=>"ok", "token"=>$token]);
        break;

    case 'service_toggle':
        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(["error"=>"403"]); exit; }
        $db = DB::getInstance();
        $db->prepare("UPDATE hitster_cron_services SET active=? WHERE id=?")->execute([(int)($body['active']??0),(int)($body['id']??0)]);
        echo json_encode(["status"=>"ok"]);
        break;

    case 'service_delete':
        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(["error"=>"403"]); exit; }
        $db = DB::getInstance();
        $db->prepare("DELETE FROM hitster_cron_services WHERE id=?")->execute([(int)($body['id']??0)]);
        echo json_encode(["status"=>"ok"]);
        break;

    case 'service_regen_token':
        if (empty($_SESSION['is_admin'])) { http_response_code(403); echo json_encode(["error"=>"403"]); exit; }
        $new_token = bin2hex(random_bytes(32));
        $db = DB::getInstance();
        $db->prepare("UPDATE hitster_cron_services SET token=? WHERE id=?")->execute([$new_token,(int)($body['id']??0)]);
        echo json_encode(["status"=>"ok", "token"=>$new_token]);
        break;

    default:
        http_response_code(400);
        echo json_encode(["error" => "Invalid action"]);
        break;
}

