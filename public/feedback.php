<?php
// public/feedback.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$id = $_GET['id'] ?? $_POST['workshop_id'] ?? '';
if (!preg_match('/^[a-f0-9\-]{32,36}$/', $id)) {
    http_response_code(400);
    exit('Ungültiger Workshop-Link');
}

$notion = new NotionClient(NOTION_TOKEN);
$success = false;
$error = '';

// ── Zeitsperre: Feedback erst ab Workshop-Start ──
$workshop = $notion->getWorkshop($id);
$title = htmlspecialchars($workshop['title'] ?? 'Workshop');
$feedbackActive = false;
$startRaw = $workshop['datum_start'] ?? null;

if ($startRaw) {
    try {
        $workshopStart = new DateTime($startRaw);
        $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
        $workshopStart->setTimezone(new DateTimeZone('Europe/Berlin'));
        $feedbackActive = ($now >= $workshopStart);
    } catch (Exception $e) {
        $feedbackActive = true;
    }
} else {
    $feedbackActive = true; // Kein Datum → offen lassen
}

// Preview-Override: ?preview=1 erzwingt Freischaltung (Test)
if (isset($_GET['preview']) && $_GET['preview'] === '1') {
    $feedbackActive = true;
}

// ── POST: Feedback absenden ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$feedbackActive) {
        $error = 'Feedback ist erst ab Workshop-Start möglich. ⏳';
    } else {
    $deviceId = getOrCreateDeviceId();

    // ── Duplikat-Check: 1 Feedback pro Workshop pro Gerät ──
    $dupeCheck = $notion->queryDatabase(NOTION_FEEDBACK_DB, [
        'filter' => [
            'and' => [
                [
                    'property' => 'Device-ID',
                    'rich_text' => ['equals' => $deviceId],
                ],
                [
                    'property' => 'Workshop',
                    'relation' => ['contains' => $id],
                ],
            ],
        ],
        'page_size' => 1,
    ]);

    if (!empty($dupeCheck['results'])) {
        $error = 'Du hast bereits Feedback für diesen Workshop abgegeben. Danke! 🙏';
    } else {
        $inhalt        = max(1, min(5, (int)($_POST['inhalt'] ?? 0)));
        $praesentation = max(1, min(5, (int)($_POST['praesentation'] ?? 0)));
        $organisation  = max(1, min(5, (int)($_POST['organisation'] ?? 0)));
        $gesamt        = max(1, min(5, (int)($_POST['gesamt'] ?? 0)));
        $kommentar     = mb_substr(trim($_POST['kommentar'] ?? ''), 0, 500);

        // Webhook-First mit Device-ID
        $webhookOk = postToWebhook(N8N_FEEDBACK_WEBHOOK, [
            'workshop_id'   => $id,
            'inhalt'        => $inhalt,
            'praesentation' => $praesentation,
            'organisation'  => $organisation,
            'gesamt'        => $gesamt,
            'kommentar'     => $kommentar,
            'device_id'     => $deviceId,
        ]);

        // Fallback: direkte Notion API
        if (!$webhookOk) {
            $result = $notion->createFeedback(
                $id, $inhalt, $praesentation, $organisation, $gesamt, $kommentar, $deviceId
            );
            $success = $result !== null;
        } else {
            $success = true;
        }

        if (!$success) {
            $error = 'Feedback konnte nicht gespeichert werden. Bitte versuche es erneut.';
        }
    }
    } // Ende: $feedbackActive
}
// Formular nur anzeigen wenn aktiv (oder preview)
// Bei gesperrtem Status → Hinweis statt Formular

$workshop = $notion->getWorkshop($id);
$title = htmlspecialchars($workshop['title'] ?? 'Workshop');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#372F2C">
    <title>Feedback – <?= $title ?></title>
    <link rel="manifest" href="/manifest.json">
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <header>
        <a href="/w/<?= $id ?>" class="back">← Zurück</a>
        <div class="badge">Feedback</div>
    </header>
    <div class="logo-bar">
        <img src="/img/logo-southside.png" alt="Adventure Southside">
        <img src="/img/logo-academy.png" alt="Selbstausbauer Academy">
    </div>
    <main>
        <h1>⭐ <?= $title ?></h1>

        <?php if ($success): ?>
            <div class="alert success">
                <h2>Danke für dein Feedback! 🎉</h2>
                <p>Deine Bewertung hilft uns, das Programm zu verbessern.</p>
		<p style="margin-top: 2em">
                    <a href="/w/<?= $id ?>" class="btn btn-primary">Zurück zum Workshop</a>
		</p>
            </div>
        <?php else: ?>
            <?php if ($error): ?>
                <div class="alert error"><?= $error ?></div>
            <?php endif; ?>

            <form method="post" action="/w/<?= $id ?>/feedback" id="feedback-form">
                <input type="hidden" name="workshop_id" value="<?= $id ?>">

                <div class="rating-group">
                    <label>Wie relevant war der Inhalt?</label>
                    <div class="stars" data-name="inhalt"></div>
                    <input type="hidden" name="inhalt" value="0" required>
                </div>

                <div class="rating-group">
                    <label>Wie war die Präsentation?</label>
                    <div class="stars" data-name="praesentation"></div>
                    <input type="hidden" name="praesentation" value="0" required>
                </div>

                <div class="rating-group">
                    <label>Wie gut war die Organisation?</label>
                    <div class="stars" data-name="organisation"></div>
                    <input type="hidden" name="organisation" value="0" required>
                </div>

                <div class="rating-group">
                    <label>Gesamtbewertung</label>
                    <div class="stars" data-name="gesamt"></div>
                    <input type="hidden" name="gesamt" value="0" required>
                </div>

                <div class="form-group">
                    <label for="kommentar">Kommentar (optional)</label>
                    <textarea name="kommentar" id="kommentar" rows="3"
                              maxlength="500" placeholder="Was hat dir gefallen? Was können wir besser machen?"></textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block">Feedback absenden</button>
            </form>
        <?php endif; ?>
    </main>
    <script src="/js/app.js"></script>
</body>
</html>
