<?php
/**
 * Hitster Camp — Migration Runner (standalone)
 *
 * Alternativa CLI / URL diretta a admin.php per eseguire le migrazioni.
 *
 * Uso:
 *   Browser: https://tuosito.it/migrate.php  (richiede admin loggato o localhost)
 *   CLI:     php migrate.php
 */

define('RUNNING_CLI', php_sapi_name() === 'cli');

if (!RUNNING_CLI) {
    session_start();
    $is_local = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
    if (empty($_SESSION['is_admin']) && !$is_local) {
        http_response_code(403);
        die('403 Forbidden. Accedi come admin prima di eseguire le migrazioni, oppure usa la sezione "Database" nel pannello admin.');
    }
}

require_once __DIR__ . '/includes/migrations.php';

$result = run_migrations();

if (RUNNING_CLI) {
    echo "\n=== Hitster Camp — Database Migration ===\n\n";
    foreach ($result['log'] as $line) {
        $icon = match($line['status']) { 'ok' => '✅', 'skip' => '⏭️ ', 'error' => '❌' };
        echo "{$icon}  [{$line['version']}] {$line['description']}\n";
        if (!empty($line['error'])) echo "    ⚠️  {$line['error']}\n";
    }
    echo "\n--- {$result['applied']} applicate, {$result['skipped']} saltate";
    echo empty($result['errors']) ? " — tutto OK ✅\n\n" : ", " . count($result['errors']) . " errori ❌\n\n";
    exit(empty($result['errors']) ? 0 : 1);
}

// Browser: redirect all'admin con risultato in sessione
session_start();
$_SESSION['migration_result'] = $result;
header('Location: admin.php#migrations');
exit;
