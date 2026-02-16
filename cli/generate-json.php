<?php
/**
 * cli/generate-json.php – Cron-Wrapper für JSON-Generierung
 *
 * Features:
 *  - Lock-Datei verhindert parallele Läufe
 *  - Timestamp-Logging für Nachvollziehbarkeit
 *  - Kompakte Ausgabe (nur Zusammenfassung, kein Einzel-Log pro Workshop)
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
echo date('[Y-m-d H:i:s]') . " START generate-workshops-json\n";
$t0 = microtime(true);

// Output des eigentlichen Scripts unterdrücken (nur Fehler durchlassen)
ob_start();
$exitCode = 0;
try {
    require __DIR__ . '/../scripts/generate-workshops-json.php';
} catch (Throwable $e) {
    $exitCode = 1;
    echo date('[Y-m-d H:i:s]') . " ERROR – " . $e->getMessage() . "\n";
}
$scriptOutput = ob_get_clean();

$duration = round(microtime(true) - $t0, 1);

// Kompakte Zusammenfassung: nur Zeilen mit Zahlen/Ergebnis extrahieren
if (preg_match('/(\d+)\s+Workshops gefunden/', $scriptOutput, $m)) {
    $count = $m[1];
} else {
    $count = '?';
}

if (preg_match('/([\d.]+)\s*KB/', $scriptOutput, $m2)) {
    $size = $m2[1] . ' KB';
} else {
    $size = '?';
}

$status = ($exitCode === 0) ? 'OK' : 'FAIL';
echo date('[Y-m-d H:i:s]') . " {$status} – {$count} Workshops, {$size}, {$duration}s\n";

// Bei Fehler: komplettes Output mit ausgeben
if ($exitCode !== 0) {
    echo "--- Script-Output ---\n";
    echo $scriptOutput;
    echo "--- Ende ---\n";
}

// ── Lock entfernen ───────────────────────────────────────
@unlink($lockFile);

exit($exitCode);
