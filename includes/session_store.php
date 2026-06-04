<?php
require_once __DIR__ . '/db.php';

function session_create($code, $age_label, $songs, $stands_info) {
    $db = DB::getInstance();
    $teams = new stdClass();
    $stands = [];
    for ($i=1; $i<=6; $i++) {
        $stands[(string)$i] = [
            "team1" => null,
            "team2" => null,
            "current_song" => null,
            "history" => []
        ];
    }
    $now = time();
    $stmt = $db->prepare("INSERT INTO hitster_sessions (code, age_label, teams, stands, songs_queue, used_ids, stands_info, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $code,
        $age_label,
        json_encode($teams, JSON_FORCE_OBJECT),
        json_encode($stands, JSON_FORCE_OBJECT),
        json_encode($songs, JSON_UNESCAPED_UNICODE),
        json_encode([]),
        json_encode($stands_info, JSON_UNESCAPED_UNICODE),
        $now,
        $now
    ]);
}

function session_get($code) {
    $db = DB::getInstance();
    $stmt = $db->prepare("SELECT * FROM hitster_sessions WHERE code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    if (!$row) return null;
    
    $row['teams'] = json_decode($row['teams'], true);
    $row['stands'] = json_decode($row['stands'], true);
    $row['songs_queue'] = json_decode($row['songs_queue'], true);
    $row['used_ids'] = json_decode($row['used_ids'], true);
    $row['stands_info'] = json_decode($row['stands_info'], true);
    
    // Add used_songs implicitly, not saved in DB but can be inferred or left empty as it's mostly for history (if needed)
    // We didn't keep used_songs column, only used_ids. If needed, we can add it, but used_ids is enough for filtering.
    $row['used_songs'] = []; 
    
    return $row;
}

function session_save($session) {
    $db = DB::getInstance();
    $now = time();
    $stmt = $db->prepare("UPDATE hitster_sessions SET teams=?, stands=?, songs_queue=?, used_ids=?, updated_at=? WHERE code=?");
    $stmt->execute([
        json_encode($session['teams'], JSON_FORCE_OBJECT),
        json_encode($session['stands'], JSON_FORCE_OBJECT),
        json_encode($session['songs_queue'], JSON_UNESCAPED_UNICODE),
        json_encode($session['used_ids']),
        $now,
        $session['code']
    ]);
}

function session_delete($code) {
    $db = DB::getInstance();
    $stmt = $db->prepare("DELETE FROM hitster_sessions WHERE code = ?");
    $stmt->execute([$code]);
}

function session_snapshot($session) {
    return [
        "code" => $session["code"],
        "teams" => empty($session["teams"]) ? new stdClass() : $session["teams"],
        "stands" => $session["stands"],
        "age_label" => $session["age_label"],
        "stands_info" => $session["stands_info"],
        "updated_at" => $session["updated_at"] ?? time()
    ];
}

// Garbage collection per sessioni vecchie
function custom_session_gc() {
    $db = DB::getInstance();
    $stmt = $db->query("DELETE FROM hitster_sessions WHERE created_at < " . (time() - 86400));
}
