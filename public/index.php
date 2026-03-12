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
$typRaw = trim((string)($workshop['typ'] ?? ''));
$typ   = htmlspecialchars($typRaw);
$tag   = htmlspecialchars($workshop['tag']);
$zeit  = htmlspecialchars($workshop['zeit']);
$ort   = htmlspecialchars($workshop['ort']);

// Details-Text kontextabhaengig am Veranstaltungsformat ausrichten.
$detailsDescByType = [
    'Workshop' => 'Ausfuehrliche Workshop-Infos',
    'Vortrag' => 'Ausfuehrliche Vortrags-Infos',
    'Expertpanel' => 'Ausfuehrliche Panel-Infos',
    'Roadtrip Girls.Hub' => 'Ausfuehrliche Roadtrip Girls.Hub-Infos',
    'Interview' => 'Ausfuehrliche Interview-Infos',
    'eCamper' => 'Ausfuehrliche eCamper-Infos',
    'Reisebericht' => 'Ausfuehrliche Reisebericht-Infos',
    'Demo' => 'Ausfuehrliche Demo-Infos',
];

$detailsDesc = $detailsDescByType[$typRaw] ?? 'Ausfuehrliche Veranstaltungs-Infos';
if ($typRaw !== '' && !isset($detailsDescByType[$typRaw])) {
    $detailsDesc = 'Ausfuehrliche Infos: ' . $typRaw;
}

// ── Enriched-Daten aus workshops.json (Kategorien, Referent) ──
$kategorien = [];
$referentFirma = '';
$referentPerson = '';
$aussteller = [];
$qaEnabled = false;
$allWorkshops = [];
$cleanId = str_replace('-', '', $id);

$jsonFile = __DIR__ . '/api/workshops.json';
if (file_exists($jsonFile)) {
    $jsonData = json_decode(file_get_contents($jsonFile), true);
    $allWorkshops = $jsonData['workshops'] ?? [];
    foreach (($jsonData['workshops'] ?? []) as $jws) {
        if ($jws['id'] === $cleanId) {
            $kategorien = $jws['kategorien'] ?? [];
            $wsStatus = $jws['status'] ?? '';
            $qaEnabled = $jws['qa_enabled'] ?? false;
            // Referent/Firma nur anzeigen wenn Status "Referent bestätigt"
            if ($wsStatus === 'Referent bestätigt') {
                $referentFirma = $jws['referent_firma'] ?? '';
                $referentPerson = $jws['referent_person'] ?? '';
                $referentPersons = $jws['referent_persons'] ?? [];
                $aussteller = $jws['aussteller'] ?? [];
            }
            break;
        }
    }
}

// Referent-Anzeige formatieren (mit Link zur Experten-Seite)
$referentPersonHtml = '';
if (!empty($referentPersons)) {
    $personLinks = [];
    foreach ($referentPersons as $rp) {
        $pName = htmlspecialchars($rp['name'] ?? 'N.N.');
        $pId   = htmlspecialchars($rp['id'] ?? '');
        $personLinks[] = '<a href="/experte.html#id=' . $pId . '" style="color:inherit;text-decoration:underline;text-decoration-color:var(--as-rot);text-underline-offset:2px">' . $pName . '</a>';
    }
    $referentPersonHtml = '🎤 ' . implode(', ', $personLinks);
} elseif ($referentPerson) {
    $referentPersonHtml = '🎤 ' . htmlspecialchars($referentPerson);
}

// Firma: Aussteller-Links haben Vorrang, dann referent_firma als Fallback-Text
$firmaHtml = '';
if (!empty($aussteller)) {
    $links = [];
    $backUrl = urlencode('/w/' . $id);
    foreach ($aussteller as $a) {
        $firma = htmlspecialchars($a['firma'] ?? '');
        $stand = $a['stand'] ?? '';
        if ($stand) {
            $links[] = '<span data-show-stand="' . htmlspecialchars($stand) . '" data-firma="' . $firma . '">🏪 ' . $firma . ' [Stand ' . htmlspecialchars($stand) . ']</span>';
        } else {
            $links[] = '<span>🏪 ' . $firma . '</span>';
        }
    }
    $firmaHtml = implode(', ', $links);
} elseif ($referentFirma) {
    $firmaHtml = htmlspecialchars($referentFirma);
}

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
// Kalender-Funktion aktiv ab 01.06.2026 ──────────────┤
$calendarActive = false;
try {
    $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $releaseDate = new DateTime('2026-06-01 00:00:00', new DateTimeZone('Europe/Berlin'));
    $calendarActive = ($now >= $releaseDate);
} catch (Exception $e) {
    $calendarActive = false;
}
// Preview-Override: ?preview=1 erzwingt Freischaltung
if (isset($_GET['preview']) && $_GET['preview'] === '1') {
    $feedbackActive = true;
}
// Admin-Override: ?secret=ADMIN_SECRET erzwingt Freischaltung
if (!empty($_GET['secret']) && !empty(ADMIN_SECRET) && hash_equals(ADMIN_SECRET, $_GET['secret'])) {
    $feedbackActive = true;
}

// ── Waiting-Links: vor Messe-Start dezente Empfehlungen ───────────────────
$eventStarted = false;
$relatedWorkshops = [];
$relatedAussteller = [];

try {
    $now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
    $eventStart = new DateTime('2026-07-10 00:00:00', new DateTimeZone('Europe/Berlin'));
    $eventStarted = ($now >= $eventStart);
} catch (Exception $e) {
    $eventStarted = true;
}

if (!$eventStarted && !empty($kategorien)) {
    // Aehnliche Veranstaltungen aus workshops.json ableiten.
    $scoredWorkshops = [];
    foreach ($allWorkshops as $candidate) {
        $candidateId = $candidate['id'] ?? '';
        if ($candidateId === '' || $candidateId === $cleanId) {
            continue;
        }

        $candidateKats = $candidate['kategorien'] ?? [];
        if (!is_array($candidateKats)) {
            $candidateKats = [];
        }

        $score = count(array_intersect($kategorien, $candidateKats));
        if ($score <= 0) {
            continue;
        }

        $sameDay = (($candidate['tag'] ?? '') === ($workshop['tag'] ?? '')) ? 1 : 0;
        $sameType = (($candidate['typ'] ?? '') === ($workshop['typ'] ?? '')) ? 1 : 0;

        $scoredWorkshops[] = [
            'w' => $candidate,
            'score' => $score,
            'same_day' => $sameDay,
            'same_type' => $sameType,
        ];
    }

    usort($scoredWorkshops, function($a, $b) {
        if ($a['score'] !== $b['score']) {
            return $b['score'] <=> $a['score'];
        }
        if ($a['same_day'] !== $b['same_day']) {
            return $b['same_day'] <=> $a['same_day'];
        }
        return $b['same_type'] <=> $a['same_type'];
    });

    $relatedWorkshops = array_slice(array_map(function($entry) {
        return $entry['w'];
    }, $scoredWorkshops), 0, 3);

    // Passende Aussteller ueber Kategorien (ohne bereits direkt verlinkte).
    $directAusstellerIds = [];
    foreach ($aussteller as $a) {
        if (!empty($a['id'])) {
            $directAusstellerIds[$a['id']] = true;
        }
    }

    $ausstellerFile = __DIR__ . '/api/aussteller.json';
    if (file_exists($ausstellerFile)) {
        $ausstellerData = json_decode(file_get_contents($ausstellerFile), true);
        $allAussteller = $ausstellerData['aussteller'] ?? [];
        $scoredAussteller = [];

        foreach ($allAussteller as $candidate) {
            $candidateId = $candidate['id'] ?? '';
            if ($candidateId === '' || isset($directAusstellerIds[$candidateId])) {
                continue;
            }

            $candidateKats = $candidate['kategorien'] ?? [];
            if (!is_array($candidateKats)) {
                $candidateKats = [];
            }

            $score = count(array_intersect($kategorien, $candidateKats));
            if ($score <= 0) {
                continue;
            }

            $scoredAussteller[] = [
                'a' => $candidate,
                'score' => $score,
            ];
        }

        usort($scoredAussteller, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });

        $relatedAussteller = array_slice(array_map(function($entry) {
            return $entry['a'];
        }, $scoredAussteller), 0, 4);
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#372F2C">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= $title ?> – AS26 Live</title>
    <link rel="manifest" href="/manifest.json">
    <link rel="icon" type="image/png" sizes="192x192" href="/img/icon-192.png">
    <link rel="apple-touch-icon" href="/img/icon-192.png">
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/programm.css">
    <script src="/js/ortmap.js" defer></script>
    <script src="/js/chat.js" defer></script>
</head>
<body>
    <div class="hero">
        <a href="/" class="hero-top">
            <img src="/img/logo-southside.png" alt="Adventure Southside" class="hero-logo">
        </a>
        <p class="hero-date">10.–12. Juli 2026 · Messe Friedrichshafen</p>
    </div>

    <main class="landing">
        <div class="workshop-card">
            <h1><?= $title ?></h1>
            <div class="typ-kat-row">
                <span class="typ-badge" data-typ="<?= $typ ?>"><?= $typ ?></span>
                <?php foreach ($kategorien as $kat): ?>
                    <span class="kat-tag"><?= htmlspecialchars($kat) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="meta-row">
                <?php if ($tag): ?><span class="meta-item">📅 <?= $tag ?></span><?php endif; ?>
                <?php if ($zeit): ?><span class="meta-item">🕐 <?= $zeit ?></span><?php endif; ?>
                <?php if ($ort): ?><span class="meta-item" data-show-ort="<?= $ort ?>">📍 <?= $ort ?></span><?php endif; ?>
            </div>
            <?php if ($referentPersonHtml || $firmaHtml): ?>
                <div style="margin-top:.4rem;font-size:.85rem;color:var(--text-light)"><?php
                    if ($referentPersonHtml) echo $referentPersonHtml;
                    if ($firmaHtml) echo '<div style="margin-top:.3rem">' . $firmaHtml . '</div>';
                ?></div>
            <?php endif; ?>
            <button class="fav-btn-landing" id="fav-btn" data-id="<?= htmlspecialchars($cleanId) ?>" title="Favorit">
                🤍
            </button>
            <button class="share-btn-landing" id="share-btn" title="Teilen">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M14 9V3l8 9-8 9v-6c-7.1 0-11.7 2.1-14.6 7C.8 15.3 4.2 10.1 14 9z"/></svg>
            </button>
        </div>

        <p class="action-hint">Was möchtest du tun?</p>

        <nav class="actions">
            <!-- Details: Workshop-Infos -->
            <a href="/w/<?= $id ?>/details?back=<?= urlencode('/w/' . $id) ?>" class="action-card">
                <span class="action-icon">📋</span>
                <span class="action-label">Details anzeigen</span>
                <span class="action-desc"><?= htmlspecialchars($detailsDesc) ?></span>
            </a>

            <!-- Q&A: nur wenn "Fragen erlauben?" aktiv -->
            <?php if ($qaEnabled): ?>
            <a href="/w/<?= $id ?>/qa" class="action-card">
                <span class="action-icon">💬</span>
                <span class="action-label">Frage stellen</span>
                <span class="action-desc">Stelle vorab oder live eine Frage</span>
            </a>
            <?php endif; ?>

            <!-- Feedback: erst ab Workshop-Start -->
            <?php if ($feedbackActive): ?>
                <a href="/w/<?= $id ?>/feedback" class="action-card">
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

            <!-- Kalender-Download (ab 01.06.2026) -->
            <?php if ($calendarActive): ?>
                <a href="/w/<?= $id ?>/ical" class="action-card">
                    <span class="action-icon">📅</span>
                    <span class="action-label">Zum Kalender</span>
                    <span class="action-desc">Termin in deinen Kalender eintragen</span>
                </a>
            <?php else: ?>
                <div class="action-card card-locked">
                    <span class="action-icon">🔒</span>
                    <span class="action-label">Zum Kalender</span>
                    <span class="action-desc">
                        Wird freigeschaltet ab 01.06.2026
                    </span>
                </div>
            <?php endif; ?>

        </nav>

        <?php if (!$eventStarted && (!empty($relatedWorkshops) || !empty($relatedAussteller))): ?>
        <section class="wait-links" aria-label="Empfehlungen vor Messe-Start">
            <p class="wait-links-line">
                <span class="wait-prefix">Während du wartest, bis die Messe startet:</span>
                <?php if (!empty($relatedWorkshops)): ?>
                    <span class="wait-topic">Ähnliche Vorträge</span>
                    <span class="wait-link-list">
                        <?php $wsTotal = count($relatedWorkshops); foreach ($relatedWorkshops as $i => $rw): ?>
                            <a class="wait-link" href="/w/<?= htmlspecialchars($rw['id'] ?? '') ?>/details?back=<?= urlencode('/w/' . $id) ?>"><?= htmlspecialchars($rw['title'] ?? 'Veranstaltung') ?></a><?php if ($i < $wsTotal - 1): ?><span class="wait-sep">·</span><?php endif; ?>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
                <?php if (!empty($relatedWorkshops) && !empty($relatedAussteller)): ?>
                    <span class="wait-divider">•</span>
                <?php endif; ?>
                <?php if (!empty($relatedAussteller)): ?>
                    <span class="wait-topic">Passende Aussteller</span>
                    <span class="wait-link-list">
                        <?php $aTotal = count($relatedAussteller); foreach ($relatedAussteller as $i => $ra): ?>
                            <a class="wait-link" href="/aussteller.html#id=<?= htmlspecialchars($ra['id'] ?? '') ?>"><?= htmlspecialchars($ra['firma'] ?? 'Aussteller') ?></a><?php if ($i < $aTotal - 1): ?><span class="wait-sep">·</span><?php endif; ?>
                        <?php endforeach; ?>
                    </span>
                <?php endif; ?>
            </p>
        </section>
        <?php endif; ?>

        <a href="/programm.html" class="programm-banner" id="programm-back-link">
            📋 Zurück zum Programm
        </a>
    </main>

    <footer>
        <a href="/"><img src="/img/logo-southside.png" alt="" class="footer-logo"></a>
        <p>Adventure Southside 2026</p>
        <div class="footer-cta-row">
            <a href="https://adventuresouthside.com/" target="_blank" rel="noopener" class="ticket-btn">🎫 Ticket sichern</a>
            <button class="share-btn" onclick="(function(){var d={title:'AS26 Live – Dein Messe-Begleiter',text:'Schau dir die Selbstausbauer Academy auf der Adventure Southside 2026 an! Workshops, Referenten & Standplan – alles in einer App:',url:'https://agenda.adventuresouthside.com'};if(navigator.share){navigator.share(d).catch(function(){})}else{window.location.href='mailto:?subject='+encodeURIComponent(d.title)+'&body='+encodeURIComponent(d.text+'\n\n'+d.url)}})()"><svg style="vertical-align:middle;margin-right:.3rem" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M14 9V3l8 9-8 9v-6c-7.1 0-11.7 2.1-14.6 7C.8 15.3 4.2 10.1 14 9z"/></svg>Freunden empfehlen</button>
        </div>
        <p>
            <a href="/impressum.html">Impressum & Datenschutz</a>
            &nbsp;·&nbsp;
            <a href="/faq.html">FAQ & Hilfe</a>
        </p>
        <div class="footer-partners">
            <a href="https://adventuresouthside.com/selbstausbau-academy/" target="_blank" rel="noopener"><img src="/img/logo-academy.png" alt="Selbstausbauer Academy"></a>
            <a href="https://adventuresouthside.com/programm-adventure-southside/" target="_blank" rel="noopener"><img src="/img/logo-ecamper.png" alt="E-Mobility &amp; Friends"></a>
            <a href="https://adventuresouthside.com/roadtrip-girls/" target="_blank" rel="noopener"><img src="/img/logo-roadtripgirls.png" alt="Roadtrip Girls"></a>
        </div>
    </footer>
    <script src="/js/sw-update-notice.js"></script>
    <script>
    // ── Admin: Feedback-Link mit Secret versehen ──
    (function() {
        var secret = sessionStorage.getItem('asa_admin_secret');
        if (secret) {
            // Bereits aktive Feedback-Links mit Secret versehen
            document.querySelectorAll('a[href*="/feedback"]').forEach(function(a) {
                var url = new URL(a.href, location.origin);
                url.searchParams.set('secret', secret);
                a.href = url.pathname + url.search;
            });
            // Gesperrte Feedback-Karte durch aktiven Link ersetzen
            document.querySelectorAll('.card-locked').forEach(function(div) {
                var label = div.querySelector('.action-label');
                if (label && label.textContent.trim() === 'Feedback geben') {
                    var a = document.createElement('a');
                    a.href = '/w/<?= $id ?>/feedback?secret=' + encodeURIComponent(secret);
                    a.className = 'action-card';
                    a.innerHTML = '<span class="action-icon">⭐</span>'
                        + '<span class="action-label">Feedback geben</span>'
                        + '<span class="action-desc">Bewerte diesen Vortrag mit Sternen</span>';
                    div.replaceWith(a);
                }
            });
        }
    })();

    // ── Back-Link mit Filter-State ──
    (function() {
        const params = new URLSearchParams(location.search);
        const back = params.get('back');
        if (back) {
            const link = document.getElementById('programm-back-link');
            if (link) link.href = back;
        }
    })();

    // ── Favoriten-Logik ──
    (function() {
        const STORAGE_KEY = 'asa_favorites';
        const btn = document.getElementById('fav-btn');
        if (!btn) return;
        const id = btn.dataset.id;

        function getFavs() {
            try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); }
            catch { return []; }
        }
        function saveFavs(arr) { localStorage.setItem(STORAGE_KEY, JSON.stringify(arr)); }

        function updateBtn() {
            const isFav = getFavs().includes(id);
            btn.textContent = isFav ? '❤️' : '🤍';
            btn.classList.toggle('active', isFav);
        }

        btn.addEventListener('click', () => {
            const favs = getFavs();
            const idx = favs.indexOf(id);
            if (idx > -1) favs.splice(idx, 1);
            else favs.push(id);
            saveFavs(favs);
            updateBtn();
        });

        updateBtn();
    })();

    // ── Teilen-Logik ──
    (function() {
        const shareBtn = document.getElementById('share-btn');
        if (!shareBtn) return;
        shareBtn.addEventListener('click', () => {
            const shareData = { title: document.title, text: document.querySelector('.workshop-card h1').textContent, url: location.href };
            if (navigator.share) {
                navigator.share(shareData).catch(() => {});
            } else {
                location.href = 'mailto:?subject=' + encodeURIComponent(shareData.title) + '&body=' + encodeURIComponent(shareData.text + '\n\n' + shareData.url);
            }
        });
    })();
    </script>
</body>
</html>
