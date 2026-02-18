<?php
/**
 * cli/generate-json.php – Cron-Wrapper für JSON-Generierung
 *
 * Features:
 *  - Lock-Datei verhindert parallele Läufe
 *  - Timestamp-Logging für Nachvollziehbarkeit
 *  - Führt alle drei Generatoren nacheinander aus
 *  - Exit-Code wird weitergegeben
 *
 * Crontab (stündlich):
 *   0 * * * * cd /var/www/as26.cool-camp.site && php cli/generate-json.php >> /var/log/as26-json.log 2>&1
 */

$lockFile = __DIR__ . '/../storage/generate-json.lock';
$storageDir = dirname($lockFile);

// Storage-Verzeichnis anlegen
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0755, true);
}

// ── Lock prüfen ──────────────────────────────────────────
if (file_exists($lockFile)) {
    $lockPid = (int) file_get_contents($lockFile);
    // Prüfen ob der Prozess noch lebt
    if ($lockPid > 0 && posix_kill($lockPid, 0)) {
        echo date('[Y-m-d H:i:s]') . " SKIP – Prozess $lockPid läuft noch.\n";
        exit(0);
    }
    // Verwaiste Lock-Datei → aufräumen
    echo date('[Y-m-d H:i:s]') . " WARN – Verwaiste Lock-Datei (PID $lockPid) entfernt.\n";
    unlink($lockFile);
}

// Lock setzen
file_put_contents($lockFile, getmypid());

// ── Generierung starten ──────────────────────────────────
$t0 = microtime(true);
$exitCode = 0;
$results = [];

// Liste der Generatoren
$generators = [
    'workshops'  => __DIR__ . '/../scripts/generate-workshops-json.php',
    'aussteller' => __DIR__ . '/../scripts/generate-aussteller-json.php',
    'experten'   => __DIR__ . '/../scripts/generate-experten-json.php',
];

foreach ($generators as $name => $script) {
    echo date('[Y-m-d H:i:s]') . " START {$name}\n";
    $gt0 = microtime(true);

    // Jeder Generator läuft als Sub-Prozess (isolierter Scope)
    $cmd = 'php ' . escapeshellarg($script) . ' 2>&1';
    $output = '';
    $rc = 0;
    exec($cmd, $lines, $rc);
    $output = implode("\n", $lines);

    if ($rc === 0) {
        $results[$name] = 'OK';
    } else {
        $exitCode = 1;
        $results[$name] = 'FAIL';
    }
    $gDur = round(microtime(true) - $gt0, 1);

    // Bei Fehler: Output anzeigen
    if ($results[$name] === 'FAIL') {
        echo "--- {$name} Output ---\n{$output}--- Ende ---\n";
    }

    echo date('[Y-m-d H:i:s]') . " {$results[$name]} {$name} ({$gDur}s)\n";
}

$totalDur = round(microtime(true) - $t0, 1);
$summary = implode(', ', array_map(fn($n, $s) => "{$n}={$s}", array_keys($results), $results));
echo date('[Y-m-d H:i:s]') . " DONE – {$summary} – Total: {$totalDur}s\n";

// ── Lock entfernen ───────────────────────────────────────
@unlink($lockFile);

exit($exitCode);
