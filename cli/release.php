<?php
/**
 * Release-Management CLI für AS26
 *
 * Nutzung:
 *   php cli/release.php init                          – DB initialisieren
 *   php cli/release.php current                       – Aktuelle Version anzeigen
 *   php cli/release.php log [--limit=N]               – Changelog anzeigen
 *   php cli/release.php release <version> "<beschreibung>"  – Neues Release eintragen
 *
 * Die Release-DB liegt unter storage/releases/releases.sqlite
 * APP_VERSION in config/.env wird bei jedem Release automatisch aktualisiert.
 */

$baseDir = dirname(__DIR__);

// --- SQLite Setup ---
$dbDir  = $baseDir . '/storage/releases';
$dbFile = $dbDir . '/releases.sqlite';

function ensureDb(string $dbDir, string $dbFile): SQLite3
{
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0755, true);
    }
    $db = new SQLite3($dbFile);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec('CREATE TABLE IF NOT EXISTS releases (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        version     TEXT    NOT NULL UNIQUE,
        description TEXT    NOT NULL DEFAULT "",
        git_hash    TEXT    NOT NULL DEFAULT "",
        released_at TEXT    NOT NULL DEFAULT "",
        changes     TEXT    NOT NULL DEFAULT "[]"
    )');
    return $db;
}

function currentGitHash(string $baseDir): string
{
    $hash = trim(shell_exec("cd " . escapeshellarg($baseDir) . " && git rev-parse --short HEAD 2>/dev/null") ?? '');
    return $hash ?: 'unknown';
}

function gitLogSince(string $baseDir, string $sinceHash): array
{
    if ($sinceHash === '' || $sinceHash === 'unknown') {
        $cmd = "cd " . escapeshellarg($baseDir) . " && git log --oneline -30 2>/dev/null";
    } else {
        $cmd = "cd " . escapeshellarg($baseDir) . " && git log --oneline " . escapeshellarg($sinceHash) . "..HEAD 2>/dev/null";
    }
    $output = trim(shell_exec($cmd) ?? '');
    return $output !== '' ? explode("\n", $output) : [];
}

function updateEnvVersion(string $baseDir, string $version): bool
{
    $envFile = $baseDir . '/config/.env';
    if (!file_exists($envFile)) {
        fwrite(STDERR, "FEHLER: {$envFile} nicht gefunden.\n");
        return false;
    }
    $content = file_get_contents($envFile);
    $updated = preg_replace('/^APP_VERSION=.*$/m', 'APP_VERSION=' . $version, $content, 1, $count);
    if ($count === 0) {
        // Zeile hinzufügen, falls nicht vorhanden
        $updated = rtrim($content) . "\nAPP_VERSION={$version}\n";
    }
    file_put_contents($envFile, $updated);
    return true;
}

function printTable(array $rows): void
{
    if (empty($rows)) {
        echo "  (keine Einträge)\n";
        return;
    }
    foreach ($rows as $row) {
        echo sprintf(
            "  %-10s  %-8s  %s  %s\n",
            $row['version'],
            $row['git_hash'],
            $row['released_at'],
            $row['description']
        );
    }
}

// --- CLI Argument Parsing ---
$args = array_slice($argv, 1);
if (empty($args)) {
    fwrite(STDERR, <<<USAGE
AS26 Release Manager
────────────────────
Nutzung:
  php cli/release.php init
  php cli/release.php current
  php cli/release.php log [--limit=N]
  php cli/release.php release <version> "<beschreibung>"

USAGE);
    exit(1);
}

$command = $args[0];

switch ($command) {
    // ─── INIT ────────────────────────────────────────────────────
    case 'init':
        $db = ensureDb($dbDir, $dbFile);
        echo "✔ Release-DB initialisiert: {$dbFile}\n";
        $db->close();
        break;

    // ─── CURRENT ─────────────────────────────────────────────────
    case 'current':
        $db = ensureDb($dbDir, $dbFile);
        $row = $db->querySingle(
            'SELECT version, git_hash, released_at, description FROM releases ORDER BY id DESC LIMIT 1',
            true
        );
        if ($row) {
            echo "Aktuelle Version: {$row['version']} ({$row['git_hash']}) – {$row['released_at']}\n";
            echo "  {$row['description']}\n";
        } else {
            echo "Noch kein Release eingetragen.\n";
        }
        $db->close();
        break;

    // ─── LOG ─────────────────────────────────────────────────────
    case 'log':
        $limit = 20;
        foreach ($args as $a) {
            if (preg_match('/^--limit=(\d+)$/', $a, $m)) {
                $limit = (int)$m[1];
            }
        }
        $db = ensureDb($dbDir, $dbFile);
        $stmt = $db->prepare('SELECT version, git_hash, released_at, description, changes FROM releases ORDER BY id DESC LIMIT :limit');
        $stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
        $result = $stmt->execute();

        echo "AS26 Changelog\n";
        echo str_repeat('─', 72) . "\n";

        $rows = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $rows[] = $row;
        }

        if (empty($rows)) {
            echo "  (keine Releases)\n";
        } else {
            foreach ($rows as $row) {
                echo "\n## {$row['version']}  ({$row['git_hash']})  –  {$row['released_at']}\n";
                echo "   {$row['description']}\n";
                $changes = json_decode($row['changes'], true);
                if (!empty($changes)) {
                    foreach ($changes as $c) {
                        echo "   • {$c}\n";
                    }
                }
            }
        }
        echo "\n";
        $db->close();
        break;

    // ─── RELEASE ─────────────────────────────────────────────────
    case 'release':
        if (empty($args[1])) {
            fwrite(STDERR, "FEHLER: Version fehlt.\nNutzung: php cli/release.php release 1.2.0 \"Beschreibung\"\n");
            exit(1);
        }
        $version = $args[1];
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            fwrite(STDERR, "FEHLER: Version muss im Format X.Y.Z sein (z.B. 1.2.0)\n");
            exit(1);
        }
        $description = $args[2] ?? '';

        $db = ensureDb($dbDir, $dbFile);

        // Prüfe ob Version schon existiert
        $stmt = $db->prepare('SELECT id FROM releases WHERE version = :v');
        $stmt->bindValue(':v', $version, SQLITE3_TEXT);
        $exists = $stmt->execute()->fetchArray();
        if ($exists) {
            fwrite(STDERR, "FEHLER: Version {$version} existiert bereits.\n");
            $db->close();
            exit(1);
        }

        // Git-Infos sammeln
        $gitHash = currentGitHash($baseDir);

        // Commits seit letztem Release
        $lastHash = $db->querySingle('SELECT git_hash FROM releases ORDER BY id DESC LIMIT 1');
        $commits  = gitLogSince($baseDir, $lastHash ?: '');

        // Release eintragen
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $db->prepare('INSERT INTO releases (version, description, git_hash, released_at, changes) VALUES (:v, :d, :h, :t, :c)');
        $stmt->bindValue(':v', $version, SQLITE3_TEXT);
        $stmt->bindValue(':d', $description, SQLITE3_TEXT);
        $stmt->bindValue(':h', $gitHash, SQLITE3_TEXT);
        $stmt->bindValue(':t', $now, SQLITE3_TEXT);
        $stmt->bindValue(':c', json_encode($commits, JSON_UNESCAPED_UNICODE), SQLITE3_TEXT);
        $stmt->execute();

        // .env aktualisieren
        updateEnvVersion($baseDir, $version);

        echo "✔ Release {$version} eingetragen (Hash: {$gitHash})\n";
        echo "✔ APP_VERSION in config/.env → {$version}\n";
        if (!empty($commits)) {
            echo "\nÄnderungen seit letztem Release:\n";
            foreach ($commits as $c) {
                echo "  • {$c}\n";
            }
        }

        $db->close();
        break;

    default:
        fwrite(STDERR, "Unbekannter Befehl: {$command}\n");
        exit(1);
}
