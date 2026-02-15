<?php
// public/index.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9\-]{32,36}$/', $id)) {
    http_response_code(400);
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Fehler</title></head>';
    echo '<body><h1>Ungültiger Workshop-Link</h1><p>Bitte scanne den QR-Code erneut.</p></body></html>';
    exit;
}

$notion = new NotionClient(NOTION_TOKEN);
$workshop = $notion->getWorkshop($id);

if (!$workshop) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="de"><head><meta charset="utf-8"><title>Nicht gefunden</title></head>';
    echo '<body><h1>Workshop nicht gefunden</h1></body></html>';
    exit;
}

$title = htmlspecialchars($workshop['title']);
$typ   = htmlspecialchars($workshop['typ']);
$zeit  = htmlspecialchars($workshop['zeit']);
$ort   = htmlspecialchars($workshop['ort']);

// ── Feedback-Zeitsperre ──────────────────────────────────
// Feedback erst ab Workshop-Start freischalten
$feedbackActive = false;
$startRaw = $workshop['datum_start'] ?? null;
$startFormatted = '';

if ($startRaw) {
    try {
        $workshopStart = new DateTime($startRaw);
        $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
        $workshopStart->setTimezone(new DateTimeZone('Europe/Berlin'));
        $feedbackActive = ($now >= $workshopStart);
        $startFormatted = $workshopStart->format('d.m.Y, H:i');
    } catch (Exception $e) {
        $feedbackActive = true;
    }
} else {
    $feedbackActive = true;
}

// Preview-Override: ?preview=1 erzwingt Freischaltung
if (isset($_GET['preview']) && $_GET['preview'] === '1') {
    $feedbackActive = true;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $title ?> – Academy Live</title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <div class="hero">
        <div class="hero-top">
            <img src="/img/logo-southside.png" alt="Adventure Southside" class="hero-logo">
            <img src="/img/logo-academy.png" alt="Selbstausbauer Academy" class="hero-logo">
        </div>
        <p class="hero-date">10.–12. Juli 2026 · Messe Friedrichshafen</p>
    </div>

    <main class="landing">
        <div class="workshop-card">
            <span class="typ-badge"><?= $typ ?></span>
            <h1><?= $title ?></h1>
            <?php if ($zeit || $ort): ?>
                <div class="meta-row">
                    <?php if ($zeit): ?><span class="meta-item">🕐 <?= $zeit ?></span><?php endif; ?>
                    <?php if ($ort): ?><span class="meta-item">📍 <?= $ort ?></span><?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <p class="action-hint">Was möchtest du tun?</p>

        <nav class="actions">
            <!-- Q&A: immer aktiv (Fragen auch vorab möglich) -->
            <a href="/qa.php?id=<?= $id ?>" class="action-card">
                <span class="action-icon">💬</span>
                <span class="action-label">Frage stellen</span>
                <span class="action-desc">Stelle vorab oder live eine Frage</span>
            </a>

            <!-- Feedback: erst ab Workshop-Start -->
            <?php if ($feedbackActive): ?>
                <a href="/feedback.php?id=<?= $id ?>" class="action-card">
                    <span class="action-icon">⭐</span>
                    <span class="action-label">Feedback geben</span>
                    <span class="action-desc">Bewerte diesen Vortrag mit Sternen</span>
                </a>
            <?php else: ?>
                <div class="action-card card-locked">
                    <span class="action-icon">🔒</span>
                    <span class="action-label">Feedback geben</span>
                    <span class="action-desc">
                        Wird freigeschaltet ab Workshop-Start
                        <?php if ($startFormatted): ?>
                            <br><span class="card-hint">📅 <?= $startFormatted ?> Uhr</span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </nav>
    </main>

    <footer>
        <img src="/img/logo-southside.png" alt="" class="footer-logo">
        <p>Adventure Southside 2026</p>
    </footer>
</body>
</html>
