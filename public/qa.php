<?php
// public/qa.php – Spam-Schutz + Optimistic Upvote
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$id = $_GET['id'] ?? $_POST['workshop_id'] ?? '';
if (!preg_match('/^[a-f0-9\-]{32,36}$/', $id)) {
    http_response_code(400);
    exit('Ungültiger Workshop-Link');
}

$notion = new NotionClient(NOTION_TOKEN);

// ── AJAX Upvote (async, kein Redirect) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && !empty($_POST['upvote_id'])
    && isset($_POST['ajax'])) {
    header('Content-Type: application/json');
    $qId = $_POST['upvote_id'];

    // Webhook-First
    $ok = postToWebhook(N8N_UPVOTE_WEBHOOK, ['question_id' => $qId]);

    // Fallback
    if (!$ok) {
        $notion->upvoteQuestion($qId);
    }

    echo json_encode(['ok' => true]);
    exit;
}

$message = '';

// ── POST: Neue Frage ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['frage'])) {
    $deviceId = getOrCreateDeviceId();
    $frage = mb_substr(trim($_POST['frage']), 0, 300);

    // Webhook-First mit Device-ID
    $webhookOk = postToWebhook(N8N_QA_WEBHOOK, [
        'workshop_id' => $id,
        'frage'       => $frage,
        'device_id'   => $deviceId,
    ]);

    // Fallback
    if (!$webhookOk) {
        $result = $notion->createQuestion($id, $frage, $deviceId);
        $message = $result ? 'Frage eingereicht! ✅' : 'Fehler beim Senden.';
    } else {
        $message = 'Frage eingereicht! ✅';
    }

    header('Location: /qa.php?id=' . $id . '&msg=' . urlencode($message));
    exit;
}

$workshop = $notion->getWorkshop($id);
$title = htmlspecialchars($workshop['title'] ?? 'Workshop');
$questions = $notion->getQuestions($id);
$msg = htmlspecialchars($_GET['msg'] ?? '');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fragen – <?= $title ?></title>
    <link rel="stylesheet" href="/css/style.css">
</head>
<body>
    <header>
        <a href="/index.php?id=<?= $id ?>" class="back">← Zurück</a>
        <div class="badge">Q&A</div>
    </header>
    <div class="logo-bar">
        <img src="/img/logo-southside.png" alt="Adventure Southside">
        <img src="/img/logo-academy.png" alt="Selbstausbauer Academy">
    </div>
    <main>
        <h1>💬 <?= $title ?></h1>

        <?php if ($msg): ?>
            <div class="alert success"><?= $msg ?></div>
        <?php endif; ?>

        <form method="post" action="/qa.php?id=<?= $id ?>" class="qa-form">
            <input type="hidden" name="workshop_id" value="<?= $id ?>">
            <textarea name="frage" rows="2" maxlength="300"
                      placeholder="Deine Frage an den Referenten..." required></textarea>
            <button type="submit" class="btn btn-primary btn-block">Frage absenden</button>
        </form>

        <h2>Bisherige Fragen (<?= count($questions) ?>)</h2>

        <?php if (empty($questions)): ?>
            <p class="empty">Noch keine Fragen – sei die erste Person! 🙋</p>
        <?php else: ?>
            <div class="questions-list">
                <?php foreach ($questions as $q): ?>
                    <div class="question-card <?= $q['status'] === 'Beantwortet' ? 'answered' : '' ?>">
                        <button type="button" class="upvote-btn"
                                data-question-id="<?= $q['id'] ?>"
                                data-workshop-id="<?= $id ?>">
                            ▲<br><span class="vote-count"><?= $q['upvotes'] ?></span>
                        </button>
                        <div class="question-text">
                            <p><?= htmlspecialchars($q['frage']) ?></p>
                            <?php if ($q['status'] === 'Beantwortet'): ?>
                                <span class="status-badge">✅ Beantwortet</span>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
    <script src="/js/app.js"></script>
</body>
</html>
