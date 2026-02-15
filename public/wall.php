<?php
// public/wall.php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../src/NotionClient.php';

$id = $_GET['id'] ?? '';
if (!preg_match('/^[a-f0-9\-]{32,36}$/', $id)) {
    http_response_code(400);
    exit('Ungültiger Workshop-Link');
}

$notion = new NotionClient(NOTION_TOKEN);
$workshop = $notion->getWorkshop($id);
$title = htmlspecialchars($workshop['title'] ?? 'Workshop');
$questions = $notion->getQuestions($id);
$openQuestions = array_filter($questions, fn($q) => $q['status'] === 'Offen');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="10">
    <title>Live Q&A – <?= $title ?></title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        body { background: #1a1a2e; color: #fff; font-size: 1.4rem; }
        .wall-header { text-align: center; padding: 2rem; }
        .wall-header h1 { font-size: 2rem; margin-bottom: 0.5rem; }
        .wall-question {
            background: #16213e; border-left: 4px solid #e94560;
            padding: 1.2rem 1.5rem; margin: 0.8rem 2rem;
            border-radius: 8px; display: flex; align-items: center; gap: 1rem;
        }
        .wall-votes {
            font-size: 2rem; font-weight: 700; color: #e94560;
            min-width: 60px; text-align: center;
        }
        .wall-text { flex: 1; }
        .wall-count { text-align: center; color: #888; margin-top: 2rem; }
    </style>
</head>
<body>
    <div class="logo-bar">
        <img src="/img/logo-southside.png" alt="Adventure Southside">
        <img src="/img/logo-academy.png" alt="Selbstausbauer Academy">
    </div>
    <div class="wall-header">
        <h1>💬 <?= $title ?></h1>
        <p><?= count($openQuestions) ?> offene Fragen · Auto-Refresh alle 10s</p>
    </div>

    <?php foreach ($openQuestions as $q): ?>
        <div class="wall-question">
            <div class="wall-votes">▲ <?= $q['upvotes'] ?></div>
            <div class="wall-text"><?= htmlspecialchars($q['frage']) ?></div>
        </div>
    <?php endforeach; ?>

    <?php if (empty($openQuestions)): ?>
        <div class="wall-count">
            <p>Noch keine Fragen – QR-Code scannen und loslegen! 📱</p>
        </div>
    <?php endif; ?>
</body>
</html>
