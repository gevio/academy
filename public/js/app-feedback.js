// public/js/app-feedback.js
// AS26 App-Feedback – Floating Button (links unten) + 3-Step-Modal

(function () {
  'use strict';

  var STORAGE_KEY = 'asa_app_feedback_last'; // Timestamp der letzten Einreichung
  var API_URL     = '/api/app-feedback.php';
  var COOLDOWN_MS = 24 * 60 * 60 * 1000;    // 24h in ms

  // ── Rate-Limit prüfen ────────────────────────────────────────────────
  function isOnCooldown() {
    var last = localStorage.getItem(STORAGE_KEY);
    if (!last) return false;
    return (Date.now() - parseInt(last, 10)) < COOLDOWN_MS;
  }

  // ── Plattform erkennen ────────────────────────────────────────────────
  function detectPlatform() {
    var ua = navigator.userAgent || '';
    if (/iPhone|iPad|iPod/.test(ua)) return 'iOS';
    if (/Android/.test(ua)) return 'Android';
    return 'Browser';
  }

  // ── App-Version aus sw-update-notice.js (globale Variable) ───────────
  function getAppVersion() {
    return (typeof APP_VERSION !== 'undefined' ? APP_VERSION : '') || '';
  }

  // ── State ────────────────────────────────────────────────────────────
  var isOpen  = false;
  var step    = 1;  // 1, 2, 3 oder 4 (success)
  var ratings = { app_bewertung: 0, navigation: 0, ladegeschwindigkeit: 0, nuetzlichkeit: 0 };
  var nps     = -1; // -1 = nicht gesetzt
  var texts   = { verbesserung: '', feature_wunsch: '' };

  // ── DOM aufbauen ─────────────────────────────────────────────────────
  function buildUI() {
    var css = document.createElement('style');
    css.textContent = `
      /* ── Floating Button ── */
      #afm-btn {
        position: fixed; bottom: 1.5rem; left: 1.5rem; z-index: 9000;
        width: 56px; height: 56px; border-radius: 50%;
        background: var(--as-orange, #F18864); color: #fff; border: none;
        box-shadow: 0 4px 16px rgba(241,136,100,.45);
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        transition: transform .2s, box-shadow .2s;
      }
      #afm-btn:hover { transform: scale(1.08); box-shadow: 0 6px 20px rgba(241,136,100,.6); }
      #afm-btn svg { width: 26px; height: 26px; }

      /* ── Panel ── */
      #afm-panel {
        position: fixed; bottom: 0; left: 0; z-index: 8999;
        width: 100%; max-width: 420px; height: 85dvh; max-height: 640px;
        background: #fff; border-radius: 16px 16px 0 0;
        box-shadow: 0 -4px 32px rgba(0,0,0,.18);
        display: flex; flex-direction: column;
        transform: translateY(105%); transition: transform .3s cubic-bezier(.4,0,.2,1);
        font-family: 'PT Sans', sans-serif;
      }
      #afm-panel.open { transform: translateY(0); }

      /* ── Header ── */
      .afm-header {
        background: var(--as-braun-dark, #372F2C); color: #fff;
        padding: .9rem 1rem; border-radius: 16px 16px 0 0;
        display: flex; align-items: center; gap: .75rem; flex-shrink: 0;
      }
      .afm-header-avatar {
        width: 36px; height: 36px; border-radius: 50%;
        background: var(--as-orange, #F18864);
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
      }
      .afm-header-avatar svg { width: 20px; height: 20px; }
      .afm-header-info { flex: 1; }
      .afm-header-title { font-weight: 700; font-size: 1rem; }
      .afm-header-sub { font-size: .78rem; opacity: .75; }
      .afm-close {
        background: none; border: none; color: #fff; cursor: pointer;
        padding: .25rem; border-radius: 6px; display: flex;
        opacity: .8; transition: opacity .15s;
      }
      .afm-close:hover { opacity: 1; }
      .afm-close svg { width: 20px; height: 20px; }

      /* ── Fortschrittsleiste ── */
      .afm-progress {
        display: flex; gap: .3rem; padding: .75rem 1rem .5rem;
        flex-shrink: 0;
      }
      .afm-progress-dot {
        height: 4px; flex: 1; border-radius: 2px;
        background: #e0d8d3; transition: background .2s;
      }
      .afm-progress-dot.active { background: var(--as-orange, #F18864); }
      .afm-progress-dot.done   { background: var(--as-braun-dark, #372F2C); }

      /* ── Body ── */
      .afm-body {
        flex: 1; overflow-y: auto; padding: .25rem 1rem 1rem;
        display: flex; flex-direction: column; min-height: 0;
      }

      /* ── Step-Titel ── */
      .afm-step-title {
        font-weight: 700; font-size: 1rem; color: var(--as-braun-dark, #372F2C);
        margin: .5rem 0 1rem;
      }

      /* ── Star-Ratings ── */
      .afm-rating-group { margin-bottom: 1rem; }
      .afm-rating-label {
        font-size: .85rem; color: #555; margin-bottom: .35rem; display: block;
      }
      .afm-stars { display: flex; gap: .25rem; }
      .afm-star {
        font-size: 1.6rem; cursor: pointer; color: #d0c8c0;
        transition: color .15s, transform .1s; line-height: 1;
        user-select: none; -webkit-user-select: none;
      }
      .afm-star.active { color: var(--as-orange, #F18864); }
      .afm-star:hover  { transform: scale(1.15); }

      /* ── NPS-Buttons ── */
      .afm-nps-grid {
        display: grid; grid-template-columns: repeat(11, 1fr); gap: .25rem;
        margin: .5rem 0 .75rem;
      }
      .afm-nps-btn {
        aspect-ratio: 1; border-radius: 6px; border: 2px solid #ddd;
        background: #fff; font-size: .82rem; font-weight: 700;
        cursor: pointer; color: #555; transition: all .15s;
        display: flex; align-items: center; justify-content: center;
      }
      .afm-nps-btn:hover { border-color: var(--as-orange, #F18864); }
      .afm-nps-btn.active {
        background: var(--as-orange, #F18864);
        border-color: var(--as-orange, #F18864); color: #fff;
      }
      .afm-nps-labels {
        display: flex; justify-content: space-between;
        font-size: .72rem; color: #999; margin-bottom: .25rem;
      }

      /* ── Textfelder ── */
      .afm-textarea-group { margin-bottom: .85rem; }
      .afm-textarea-label {
        font-size: .85rem; color: #555; margin-bottom: .35rem; display: block;
      }
      .afm-textarea {
        width: 100%; box-sizing: border-box;
        border: 1.5px solid #ddd; border-radius: 8px;
        padding: .6rem .75rem; font-size: .9rem; font-family: inherit;
        resize: none; transition: border-color .15s;
        line-height: 1.45;
      }
      .afm-textarea:focus { outline: none; border-color: var(--as-orange, #F18864); }

      /* ── Footer / Buttons ── */
      .afm-footer {
        padding: .75rem 1rem; border-top: 1px solid #f0ebe6;
        display: flex; justify-content: flex-end; gap: .5rem; flex-shrink: 0;
      }
      .afm-btn-next, .afm-btn-submit {
        padding: .6rem 1.4rem; border-radius: 8px; border: none;
        background: var(--as-rot, #CF3628); color: #fff;
        font-size: .9rem; font-weight: 700; cursor: pointer;
        font-family: inherit; transition: opacity .15s;
      }
      .afm-btn-next:disabled, .afm-btn-submit:disabled {
        opacity: .45; cursor: default;
      }
      .afm-btn-skip {
        padding: .6rem 1rem; border-radius: 8px; border: none;
        background: none; color: #999;
        font-size: .88rem; cursor: pointer; font-family: inherit;
      }
      .afm-btn-skip:hover { color: #555; }

      /* ── Success ── */
      .afm-success {
        display: none; flex-direction: column; align-items: center;
        justify-content: center; text-align: center;
        padding: 2rem 1rem; gap: 1rem;
      }
      .afm-success.visible { display: flex; }
      .afm-success-icon { font-size: 3rem; line-height: 1; }
      .afm-success h2 { font-size: 1.15rem; color: var(--as-braun-dark, #372F2C); margin: 0; }
      .afm-success p  { font-size: .9rem; color: #666; margin: 0; }
      .afm-success-close {
        padding: .6rem 1.4rem; border-radius: 8px; border: none;
        background: var(--as-rot, #CF3628); color: #fff;
        font-size: .9rem; font-weight: 700; cursor: pointer; font-family: inherit;
      }

      /* ── Step-Wrapper ── */
      .afm-step { display: none; }
      .afm-step.active { display: block; }

      /* ── Tooltip ── */
      #afm-tooltip {
        position: fixed; bottom: 1.75rem; left: 5.5rem; z-index: 9000;
        background: rgba(55,47,44,.92); color: #fff;
        padding: .4rem .75rem; border-radius: 8px;
        font-size: .82rem; font-weight: 600; white-space: nowrap;
        pointer-events: none; opacity: 0;
        transform: translateX(-8px);
        transition: opacity .3s, transform .3s;
        font-family: 'PT Sans', sans-serif;
      }
      #afm-tooltip.visible { opacity: 1; transform: translateX(0); }
      #afm-tooltip::after {
        content: ''; position: absolute; top: 50%; left: -6px;
        transform: translateY(-50%);
        border: 6px solid transparent; border-left: none;
        border-right-color: rgba(55,47,44,.92);
      }
    `;
    document.head.appendChild(css);

    // ── Button ──────────────────────────────────────────────────────────
    var btn = document.createElement('button');
    btn.id = 'afm-btn';
    btn.setAttribute('aria-label', 'App bewerten');
    btn.innerHTML = `<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>`;
    btn.addEventListener('click', openPanel);
    document.body.appendChild(btn);

    // Tooltip (1x pro Session)
    if (!sessionStorage.getItem('as_feedback_tooltip_shown')) {
      var tip = document.createElement('span');
      tip.id = 'afm-tooltip';
      tip.textContent = 'App bewerten';
      document.body.appendChild(tip);
      setTimeout(function() { tip.classList.add('visible'); }, 800);
      setTimeout(function() {
        tip.classList.remove('visible');
        setTimeout(function() { tip.remove(); }, 300);
      }, 4200);
      sessionStorage.setItem('as_feedback_tooltip_shown', '1');
    }

    // ── Panel ────────────────────────────────────────────────────────────
    var panel = document.createElement('div');
    panel.id = 'afm-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', 'App bewerten');
    panel.innerHTML = `
      <div class="afm-header">
        <div class="afm-header-avatar">
          <svg viewBox="0 0 24 24" fill="white"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
        </div>
        <div class="afm-header-info">
          <div class="afm-header-title">App bewerten</div>
          <div class="afm-header-sub">Adventure Southside 2026</div>
        </div>
        <button class="afm-close" id="afm-close" aria-label="Schließen">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
      </div>

      <div class="afm-progress" id="afm-progress">
        <div class="afm-progress-dot active" data-step="1"></div>
        <div class="afm-progress-dot" data-step="2"></div>
        <div class="afm-progress-dot" data-step="3"></div>
      </div>

      <div class="afm-body" id="afm-body">
        <!-- Step 1: Sterne -->
        <div class="afm-step active" id="afm-step-1">
          <div class="afm-step-title">Wie gefällt dir die App?</div>
          <div class="afm-rating-group">
            <span class="afm-rating-label">App gesamt ★</span>
            <div class="afm-stars" data-key="app_bewertung" data-max="5"></div>
          </div>
          <div class="afm-rating-group">
            <span class="afm-rating-label">Wie leicht findest du dich zurecht?</span>
            <div class="afm-stars" data-key="navigation" data-max="5"></div>
          </div>
          <div class="afm-rating-group">
            <span class="afm-rating-label">Wie schnell lädt die App?</span>
            <div class="afm-stars" data-key="ladegeschwindigkeit" data-max="5"></div>
          </div>
          <div class="afm-rating-group">
            <span class="afm-rating-label">Hat dir die App auf der Messe geholfen?</span>
            <div class="afm-stars" data-key="nuetzlichkeit" data-max="5"></div>
          </div>
        </div>

        <!-- Step 2: NPS -->
        <div class="afm-step" id="afm-step-2">
          <div class="afm-step-title">Würdest du die App weiterempfehlen?</div>
          <div class="afm-nps-grid" id="afm-nps-grid"></div>
          <div class="afm-nps-labels">
            <span>Nein, nie</span>
            <span>Ja, definitiv</span>
          </div>
        </div>

        <!-- Step 3: Freitext -->
        <div class="afm-step" id="afm-step-3">
          <div class="afm-step-title">Dein Feedback (optional)</div>
          <div class="afm-textarea-group">
            <label class="afm-textarea-label" for="afm-verbesserung">Was können wir an der App verbessern?</label>
            <textarea id="afm-verbesserung" class="afm-textarea" rows="3" maxlength="2000"
              placeholder="z. B. Funktionen, Navigation, Design…"></textarea>
          </div>
          <div class="afm-textarea-group">
            <label class="afm-textarea-label" for="afm-feature">Welches Feature fehlt dir am meisten?</label>
            <textarea id="afm-feature" class="afm-textarea" rows="3" maxlength="2000"
              placeholder="z. B. Offline-Karte, Push-Benachrichtigungen…"></textarea>
          </div>
        </div>

        <!-- Success -->
        <div class="afm-success" id="afm-success">
          <div class="afm-success-icon">🎉</div>
          <h2>Danke für dein Feedback!</h2>
          <p>Das hilft uns, die App für die nächste Messe noch besser zu machen.</p>
          <button class="afm-success-close" id="afm-success-close">Schließen</button>
        </div>
      </div>

      <div class="afm-footer" id="afm-footer">
        <button class="afm-btn-skip" id="afm-skip">Überspringen</button>
        <button class="afm-btn-next" id="afm-next" disabled>Weiter</button>
      </div>
    `;
    document.body.appendChild(panel);

    // ── Event-Listener ───────────────────────────────────────────────────
    document.getElementById('afm-close').addEventListener('click', closePanel);
    document.getElementById('afm-success-close').addEventListener('click', closePanel);

    // Star ratings
    panel.querySelectorAll('.afm-stars').forEach(function (starsEl) {
      var key = starsEl.dataset.key;
      var max = parseInt(starsEl.dataset.max, 10) || 5;
      for (var i = 1; i <= max; i++) {
        (function (val) {
          var star = document.createElement('span');
          star.className = 'afm-star';
          star.textContent = '★';
          star.dataset.val = val;
          star.addEventListener('click', function () {
            ratings[key] = val;
            updateStars(starsEl, val);
            updateNextBtn();
          });
          star.addEventListener('mouseenter', function () { updateStars(starsEl, val); });
          star.addEventListener('mouseleave', function () { updateStars(starsEl, ratings[key] || 0); });
          starsEl.appendChild(star);
        })(i);
      }
    });

    // NPS grid
    var npsGrid = document.getElementById('afm-nps-grid');
    for (var n = 0; n <= 10; n++) {
      (function (val) {
        var b = document.createElement('button');
        b.className = 'afm-nps-btn';
        b.textContent = val;
        b.type = 'button';
        b.addEventListener('click', function () {
          nps = val;
          npsGrid.querySelectorAll('.afm-nps-btn').forEach(function (x) { x.classList.remove('active'); });
          b.classList.add('active');
          updateNextBtn();
        });
        npsGrid.appendChild(b);
      })(n);
    }

    // Weiter/Absenden
    document.getElementById('afm-next').addEventListener('click', handleNext);
    document.getElementById('afm-skip').addEventListener('click', handleSkip);
  }

  function updateStars(starsEl, val) {
    starsEl.querySelectorAll('.afm-star').forEach(function (s) {
      s.classList.toggle('active', parseInt(s.dataset.val, 10) <= val);
    });
  }

  function updateProgress() {
    document.querySelectorAll('.afm-progress-dot').forEach(function (dot) {
      var s = parseInt(dot.dataset.step, 10);
      dot.classList.toggle('done',   s < step);
      dot.classList.toggle('active', s === step);
    });
  }

  function updateNextBtn() {
    var btn = document.getElementById('afm-next');
    if (!btn) return;
    if (step === 1) {
      btn.disabled = ratings.app_bewertung === 0;
    } else if (step === 2) {
      btn.disabled = nps < 0;
    } else {
      btn.disabled = false;
    }
  }

  function showStep(n) {
    step = n;
    document.querySelectorAll('.afm-step').forEach(function (el) { el.classList.remove('active'); });
    var stepEl = document.getElementById('afm-step-' + n);
    if (stepEl) stepEl.classList.add('active');

    var nextBtn  = document.getElementById('afm-next');
    var skipBtn  = document.getElementById('afm-skip');
    var footer   = document.getElementById('afm-footer');
    var progress = document.getElementById('afm-progress');

    if (n === 3) {
      nextBtn.textContent = 'Absenden';
      nextBtn.disabled = false;
      skipBtn.textContent = 'Überspringen';
    } else {
      nextBtn.textContent = 'Weiter';
      skipBtn.textContent = n === 1 ? 'Abbrechen' : 'Überspringen';
    }

    footer.style.display = '';
    progress.style.display = '';
    updateProgress();
    updateNextBtn();
  }

  function handleNext() {
    if (step === 1 && ratings.app_bewertung === 0) return;
    if (step < 3) {
      showStep(step + 1);
    } else {
      submitFeedback();
    }
  }

  function handleSkip() {
    if (step === 1) { closePanel(); return; }
    if (step < 3) {
      showStep(step + 1);
    } else {
      submitFeedback();
    }
  }

  function submitFeedback() {
    var nextBtn   = document.getElementById('afm-next');
    var skipBtn   = document.getElementById('afm-skip');
    nextBtn.disabled = true;
    skipBtn.disabled = true;
    nextBtn.textContent = '…';

    texts.verbesserung  = (document.getElementById('afm-verbesserung')?.value || '').trim();
    texts.feature_wunsch = (document.getElementById('afm-feature')?.value || '').trim();

    var payload = {
      app_bewertung:       ratings.app_bewertung,
      navigation:          ratings.navigation,
      ladegeschwindigkeit: ratings.ladegeschwindigkeit,
      nuetzlichkeit:       ratings.nuetzlichkeit,
      nps:                 nps >= 0 ? nps : 0,
      verbesserung:        texts.verbesserung,
      feature_wunsch:      texts.feature_wunsch,
      plattform:           detectPlatform(),
      app_version:         getAppVersion(),
    };

    fetch(API_URL, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(payload),
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (data.ok || (data.error && data.error.indexOf('bereits') !== -1)) {
        // Erfolg oder bereits abgegeben – beide Male als "erledigt" behandeln
        localStorage.setItem(STORAGE_KEY, Date.now().toString());
        showSuccess();
        hideFeedbackBtn();
        if (window.as26Analytics && window.as26Analytics.track) {
          window.as26Analytics.track('app_feedback_submitted', { step: 3 });
        }
      } else {
        nextBtn.disabled = false;
        skipBtn.disabled = false;
        nextBtn.textContent = 'Absenden';
        alert('Fehler: ' + (data.error || 'Bitte versuche es erneut.'));
      }
    })
    .catch(function () {
      nextBtn.disabled = false;
      skipBtn.disabled = false;
      nextBtn.textContent = 'Absenden';
      alert('Verbindungsfehler. Bitte versuche es erneut.');
    });
  }

  function showSuccess() {
    document.querySelectorAll('.afm-step').forEach(function (el) { el.classList.remove('active'); });
    document.getElementById('afm-success').classList.add('visible');
    document.getElementById('afm-footer').style.display = 'none';
    document.getElementById('afm-progress').style.display = 'none';
  }

  function hideFeedbackBtn() {
    var btn = document.getElementById('afm-btn');
    if (btn) btn.style.display = 'none';
  }

  function openPanel() {
    if (isOpen) return;
    isOpen = true;
    step = 1;
    ratings = { app_bewertung: 0, navigation: 0, ladegeschwindigkeit: 0, nuetzlichkeit: 0 };
    nps = -1;
    // Reset stars
    document.querySelectorAll('.afm-star').forEach(function (s) { s.classList.remove('active'); });
    // Reset NPS
    document.querySelectorAll('.afm-nps-btn').forEach(function (b) { b.classList.remove('active'); });
    // Reset textareas
    var v = document.getElementById('afm-verbesserung');
    var f = document.getElementById('afm-feature');
    if (v) v.value = '';
    if (f) f.value = '';
    // Reset success
    document.getElementById('afm-success').classList.remove('visible');
    document.getElementById('afm-footer').style.display = '';
    document.getElementById('afm-progress').style.display = '';

    showStep(1);
    document.getElementById('afm-panel').classList.add('open');
  }

  function closePanel() {
    isOpen = false;
    document.getElementById('afm-panel').classList.remove('open');
  }

  // ── Init ──────────────────────────────────────────────────────────────
  function init() {
    if (isOnCooldown()) return; // Button nicht anzeigen, wenn 24h noch nicht um
    buildUI();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
