<?php
require_once __DIR__ . '/db.php';

define('CACHE_TTL', 1800);
define('CURRENT_YEAR', (int)date('Y'));

function get_default_stands() {
    return [
        [
            "id" => "1", 
            "name" => "🎵 ROUND CLASSICO (Timeline)", 
            "desc" => "🎯 COME FUNZIONA: Fai ascoltare la canzone. La squadra deve posizionarla correttamente nella timeline (PRIMA o DOPO le canzoni già indovinate).\n✅ PUNTI: +1 per la posizione corretta.\n⭐ BONUS: +3 se indovinano anche l'anno esatto (o titolo e artista al primo colpo)."
        ],
        [
            "id" => "2", 
            "name" => "🕺 ROUND MOVIMENTO (Decadi)", 
            "desc" => "🎯 COME FUNZIONA: Tutti ballano. Quando fermi la musica, devono correre nell'area della DECADE giusta (80s, 90s, 2000s...).\n✅ PUNTI: +1 alla squadra che arriva per prima nell'area corretta senza sbagliare.\n⭐ BONUS: +3 per il ballo più originale o coordinato."
        ],
        [
            "id" => "3", 
            "name" => "🎤 ROUND CANTA TU (Stop)", 
            "desc" => "🎯 COME FUNZIONA: Stoppa la musica all'improvviso. La squadra deve continuare a cantare le prossime 5-10 parole in modo esatto.\n✅ PUNTI: +1 se continuano correttamente.\n⭐ BONUS: +3 se sanno anche titolo e artista originale."
        ],
        [
            "id" => "4", 
            "name" => "🧩 ROUND INDIZI (Quiz)", 
            "desc" => "🎯 COME FUNZIONA: NON far sentire la musica. Dai 3 indizi (es: 'Hit estiva', 'Cantante bionda', 'Parla di una spiaggia'). Se non indovinano, fai sentire 2 secondi di intro.\n✅ PUNTI: +1 se indovinano dopo l'intro.\n⭐ BONUS: +3 se indovinano solo con gli indizi (senza audio)."
        ],
        [
            "id" => "5", 
            "name" => "🏃 STAFFETTA MIMICA", 
            "desc" => "🎯 COME FUNZIONA: Un giocatore corre da te, ascolta la canzone in cuffia e torna indietro. Deve farla indovinare mimando o canticchiando (senza parole).\n✅ PUNTI: +1 se indovinano entro 60 secondi.\n⭐ BONUS: +3 se indovinano in meno di 15 secondi."
        ],
        [
            "id" => "6", 
            "name" => "🔥 FINALE EPICO (Duello)", 
            "desc" => "🎯 COME FUNZIONA: Scontro diretto tra due squadre. Chi tocca prima un oggetto al centro ha diritto di risposta.\n✅ PUNTI: +1 per ogni risposta corretta al volo.\n⭐ BONUS: +3 per la canzone finale più difficile del mazzo."
        ]
    ];
}

function get_default_age_groups() {
    return [
        ["id" => "8-11", "label" => "8-11 anni", "min_age" => 8, "max_age" => 11],
        ["id" => "12-14", "label" => "12-14 anni", "min_age" => 12, "max_age" => 14],
        ["id" => "15-18", "label" => "15-18 anni", "min_age" => 15, "max_age" => 18]
    ];
}

function load_config() {
    $db = DB::getInstance();
    $stmt = $db->query("SELECT * FROM hitster_config");
    $conf = [];
    while ($row = $stmt->fetch()) {
        $conf[$row['key_name']] = json_decode($row['value'], true);
    }
    
    // Defaults if missing
    if (empty($conf)) {
        $conf = [
            "admin_password" => "hitster-admin",
            "age_groups" => get_default_age_groups(),
            "stands_info" => get_default_stands(),
            "spotify" => ["client_id" => "", "client_secret" => ""],
            "totp_secret" => ""
        ];
        save_config($conf);
    } else {
        // Forziamo stands_info per applicare descrizioni fisse (come nel Python)
        $conf["stands_info"] = get_default_stands();
    }
    
    return $conf;
}

function save_config($config) {
    $db = DB::getInstance();
    $stmt = $db->prepare("INSERT INTO hitster_config (key_name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?");
    foreach ($config as $k => $v) {
        $json_val = json_encode($v, JSON_UNESCAPED_UNICODE);
        $stmt->execute([$k, $json_val, $json_val]);
    }
}
