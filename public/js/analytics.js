// public/js/analytics.js
// AS26 First-Party Analytics – Consent + Tracker
// Nur aktiv nach Einwilligung. Keine personenbezogenen Daten.

(function () {
  'use strict';

  var ENDPOINT    = '/api/analytics.php';
  var CONSENT_KEY = 'as26_analytics_consent';
  var SESSION_KEY = 'as26_analytics_sid';
  var HEARTBEAT_INTERVAL = 30000; // 30s
  var FLUSH_INTERVAL     = 15000; // 15s
  var MAX_QUEUE          = 40;

  // ── State ──────────────────────────────────────────────────────────
  var queue        = [];
  var sessionId    = null;
  var deviceInfo   = null;
  var heartbeatTimer = null;
  var flushTimer     = null;
  var sessionStarted = false;

  // ── Consent ────────────────────────────────────────────────────────
  function hasConsent() {
    return localStorage.getItem(CONSENT_KEY) === '1';
  }

  function setConsent(val) {
    localStorage.setItem(CONSENT_KEY, val ? '1' : '0');
  }

  // ── Consent-Banner ────────────────────────────────────────────────
  function showConsentBanner() {
    if (hasConsent() || localStorage.getItem(CONSENT_KEY) === '0') return;

    var banner = document.createElement('div');
    banner.id = 'as26-consent';
    banner.innerHTML =
      '<div class="as26c-inner">' +
        '<p>Wir nutzen anonyme Nutzungsstatistiken, um die App zu verbessern. ' +
        'Keine personenbezogenen Daten, keine Cookies.</p>' +
        '<div class="as26c-btns">' +
          '<button id="as26c-ok" class="as26c-btn as26c-accept">OK, alles klar</button>' +
          '<button id="as26c-no" class="as26c-btn as26c-decline">Nein danke</button>' +
        '</div>' +
      '</div>';

    var style = document.createElement('style');
    style.textContent =
      '#as26-consent{position:fixed;bottom:0;left:0;right:0;z-index:10000;' +
      'background:rgba(55,47,44,.97);color:#fff;padding:1rem;' +
      'font-family:"PT Sans",sans-serif;font-size:.88rem;' +
      'box-shadow:0 -2px 16px rgba(0,0,0,.25);animation:as26c-in .3s ease}' +
      '@keyframes as26c-in{from{transform:translateY(100%)}to{transform:translateY(0)}}' +
      '.as26c-inner{max-width:600px;margin:0 auto;text-align:center}' +
      '.as26c-inner p{margin:0 0 .75rem;line-height:1.5}' +
      '.as26c-btns{display:flex;gap:.5rem;justify-content:center;flex-wrap:wrap}' +
      '.as26c-btn{border:none;border-radius:8px;padding:.5rem 1.2rem;font-size:.88rem;' +
      'font-weight:700;cursor:pointer;font-family:inherit}' +
      '.as26c-accept{background:#CF3628;color:#fff}' +
      '.as26c-decline{background:rgba(255,255,255,.15);color:#fff}';

    document.head.appendChild(style);
    document.body.appendChild(banner);

    document.getElementById('as26c-ok').addEventListener('click', function () {
      setConsent(true);
      banner.remove();
      startTracking();
    });

    document.getElementById('as26c-no').addEventListener('click', function () {
      setConsent(false);
      banner.remove();
    });
  }

  // ── Session-ID ─────────────────────────────────────────────────────
  function getSessionId() {
    if (sessionId) return sessionId;
    sessionId = sessionStorage.getItem(SESSION_KEY);
    if (!sessionId) {
      sessionId = generateId();
      sessionStorage.setItem(SESSION_KEY, sessionId);
    }
    return sessionId;
  }

  function generateId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    // Fallback
    var a = new Uint8Array(16);
    crypto.getRandomValues(a);
    var h = '';
    for (var i = 0; i < a.length; i++) h += ('0' + a[i].toString(16)).slice(-2);
    return h;
  }

  // ── Device-Info (einmal ermitteln) ─────────────────────────────────
  function getDeviceInfo() {
    if (deviceInfo) return deviceInfo;

    var ua = navigator.userAgent || '';
    var w  = screen.width || window.innerWidth;

    deviceInfo = {
      device_type:    detectDeviceType(ua, w),
      os_family:      detectOS(ua),
      browser_family: detectBrowser(ua),
      display_mode:   window.matchMedia('(display-mode: standalone)').matches ? 'standalone' : 'browser',
      locale:         (navigator.language || '').slice(0, 10),
      screen_bucket:  getScreenBucket(w),
    };
    return deviceInfo;
  }

  function detectDeviceType(ua, w) {
    if (/iPad|tablet/i.test(ua) || (w >= 600 && w <= 1024 && /Android/i.test(ua))) return 'tablet';
    if (/Mobi|Android|iPhone|iPod/i.test(ua) || w < 600) return 'mobile';
    return 'desktop';
  }

  function detectOS(ua) {
    if (/iPhone|iPad|iPod/i.test(ua)) return 'iOS';
    if (/Android/i.test(ua))          return 'Android';
    if (/Windows/i.test(ua))          return 'Windows';
    if (/Macintosh|Mac OS/i.test(ua)) return 'macOS';
    if (/Linux/i.test(ua))            return 'Linux';
    return 'other';
  }

  function detectBrowser(ua) {
    if (/SamsungBrowser/i.test(ua)) return 'Samsung';
    if (/Firefox/i.test(ua))        return 'Firefox';
    if (/Edg\//i.test(ua))          return 'Edge';
    if (/CriOS|Chrome/i.test(ua))   return 'Chrome';
    if (/Safari/i.test(ua))         return 'Safari';
    return 'other';
  }

  function getScreenBucket(w) {
    if (w <= 360)  return '<=360';
    if (w <= 414)  return '361-414';
    if (w <= 768)  return '415-768';
    if (w <= 1024) return '769-1024';
    return '>1024';
  }

  // ── Event sammeln ──────────────────────────────────────────────────
  function track(eventName, extra) {
    if (!hasConsent()) return;

    var ev = Object.assign({
      event_name: eventName,
      session_id: getSessionId(),
      page:       location.pathname,
    }, getDeviceInfo());

    if (extra) {
      if (extra.feature) ev.feature = extra.feature;
      if (extra.payload) ev.payload = extra.payload;
      if (extra.page)    ev.page    = extra.page;
    }

    queue.push(ev);
    if (queue.length >= MAX_QUEUE) flush();
  }

  // ── Queue senden ───────────────────────────────────────────────────
  function flush() {
    if (queue.length === 0) return;

    var batch = queue.splice(0, MAX_QUEUE);

    // sendBeacon bevorzugt (überlebt Tab-Schließen)
    if (navigator.sendBeacon) {
      navigator.sendBeacon(ENDPOINT, JSON.stringify({ events: batch }));
    } else {
      fetch(ENDPOINT, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ events: batch }),
        keepalive: true,
      }).catch(function () { /* silent */ });
    }
  }

  // ── Heartbeat ──────────────────────────────────────────────────────
  function startHeartbeat() {
    stopHeartbeat();
    heartbeatTimer = setInterval(function () {
      if (!document.hidden) track('heartbeat');
    }, HEARTBEAT_INTERVAL);
  }

  function stopHeartbeat() {
    if (heartbeatTimer) {
      clearInterval(heartbeatTimer);
      heartbeatTimer = null;
    }
  }

  // ── Tracking starten ──────────────────────────────────────────────
  function startTracking() {
    if (!hasConsent() || sessionStarted) return;
    sessionStarted = true;

    // Session-Start + Page-View
    track('session_start');
    track('page_view');

    // Standalone-Erkennung
    if (window.matchMedia('(display-mode: standalone)').matches) {
      track('app_opened_standalone');
    }

    // Heartbeat
    startHeartbeat();
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) { stopHeartbeat(); flush(); }
      else startHeartbeat();
    });

    // Regelmäßig flushen
    flushTimer = setInterval(flush, FLUSH_INTERVAL);

    // Beim Verlassen flushen
    window.addEventListener('pagehide', flush);

    // Install-Events
    window.addEventListener('beforeinstallprompt', function () {
      track('install_prompt_shown');
    });
    window.addEventListener('appinstalled', function () {
      track('app_installed');
    });
  }

  // ── Öffentliche API für andere Skripte ─────────────────────────────
  window.as26Analytics = {
    track: track,
    flush: flush,
    hasConsent: hasConsent,
  };

  // ── Init ───────────────────────────────────────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  function init() {
    if (hasConsent()) {
      startTracking();
    } else {
      showConsentBanner();
    }

    // ── Delegated tracking for footer share buttons ──
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.share-btn');
      if (btn && hasConsent()) {
        track('feature_use', { feature: 'share_app' });
      }
    });
  }

})();
