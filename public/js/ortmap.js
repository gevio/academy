/**
 * ortmap.js – Karten-Overlay für Veranstaltungsorte + Standplan
 *
 * data-show-ort="Ort-String"   → Geländeübersicht mit Pin
 * data-show-stand="FG-808"     → Hallenplan mit Stand-Pin
 */
(function () {
  let venueData = null;
  let standData = null;
  let overlay = null;

  async function loadVenueData() {
    if (venueData) return venueData;
    try {
      const res = await fetch('/api/veranstaltungsorte.json');
      venueData = await res.json();
      return venueData;
    } catch (e) {
      console.warn('veranstaltungsorte.json nicht verfügbar');
      return null;
    }
  }

  async function loadStandData() {
    if (standData) return standData;
    try {
      const res = await fetch('/api/standplan.json');
      standData = await res.json();
      return standData;
    } catch (e) {
      console.warn('standplan.json nicht verfügbar');
      return null;
    }
  }

  function findVenue(ortString) {
    if (!venueData || !venueData.orte) return null;
    for (const [id, info] of Object.entries(venueData.orte)) {
      if (info.label === ortString) return { id, ...info };
    }
    return null;
  }

  function findStand(standNr) {
    if (!standData) return null;
    var coords = standData.staende[standNr];
    if (!coords) return null;
    var prefix = standNr.split('-')[0];
    var hall = null;
    for (var [hk, hv] of Object.entries(standData.hallen)) {
      if (hk === prefix) { hall = hv; break; }
    }
    if (!hall) return null;
    return { stand: standNr, x: coords.x, y: coords.y, w: coords.w, h: coords.h, bild: hall.bild, halleLabel: hall.label };
  }

  function injectStyles() {
    if (document.getElementById('ortmap-css')) return;
    const s = document.createElement('style');
    s.id = 'ortmap-css';
    s.textContent = `
[data-show-ort],[data-show-stand]{cursor:pointer;text-decoration:underline;text-decoration-style:dotted;text-decoration-color:var(--as-rot,#e94560);text-underline-offset:2px}
[data-show-ort]:hover,[data-show-stand]:hover{text-decoration-style:solid}
.ortmap-overlay{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.85);display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;transition:opacity .2s}
.ortmap-overlay.visible{opacity:1}
.ortmap-box{position:relative;max-width:92vw;max-height:88vh;background:#1a1a2e;border-radius:12px;overflow:hidden;box-shadow:0 8px 32px rgba(0,0,0,.6)}
.ortmap-title{padding:10px 16px;font-size:.95rem;font-weight:600;color:#fff;background:rgba(0,0,0,.4);display:flex;justify-content:space-between;align-items:center}
.ortmap-close{background:none;border:none;color:#fff;font-size:1.5rem;cursor:pointer;padding:4px 8px;opacity:.7;line-height:1}
.ortmap-close:hover{opacity:1}
.ortmap-img-wrap{position:relative;line-height:0;overflow:auto;max-height:74vh;text-align:center}
.ortmap-img-inner{position:relative;display:inline-block}
.ortmap-img-wrap img{display:block;max-width:88vw;max-height:74vh;height:auto}
.ortmap-pin{position:absolute;z-index:2;pointer-events:none;transform:translate(-50%,-100%);filter:drop-shadow(0 2px 6px rgba(0,0,0,.5))}
.ortmap-pin-icon{width:40px;height:56px}
.ortmap-pin-icon svg{width:100%;height:100%;display:block}
.ortmap-pin-icon .pin-body{fill:var(--as-rot,#CF3628);fill-opacity:.88}
.ortmap-pin-icon .pin-inner{fill:#fff;fill-opacity:.35}
.ortmap-pin-pulse{position:absolute;bottom:-4px;left:50%;transform:translateX(-50%);width:16px;height:16px;border-radius:50%;background:rgba(207,54,40,.35);animation:ortmap-pulse 2s ease-out infinite}
.ortmap-pin-label{position:absolute;left:50%;bottom:calc(100% + 4px);transform:translateX(-50%);background:rgba(207,54,40,.82);color:#fff;padding:8px 16px;border-radius:8px;font-size:.85rem;white-space:nowrap;font-weight:700;letter-spacing:.3px;box-shadow:0 2px 8px rgba(0,0,0,.4);text-align:center;line-height:1.4;backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}
.ortmap-pin-label::after{content:'';position:absolute;left:50%;top:100%;transform:translateX(-50%);border:6px solid transparent;border-top-color:rgba(207,54,40,.82)}
.ortmap-no-data{padding:40px;text-align:center;color:#888;font-size:.95rem}
@keyframes ortmap-pulse{0%{transform:translateX(-50%) scale(1);opacity:.6}70%{transform:translateX(-50%) scale(3.5);opacity:0}100%{transform:translateX(-50%) scale(3.5);opacity:0}}
    `;
    document.head.appendChild(s);
  }

  // Styles SOFORT injizieren
  injectStyles();

  function ensureOverlay() {
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'ortmap-overlay';
    overlay.style.display = 'none';
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) hide();
    });
    document.body.appendChild(overlay);
    return overlay;
  }

  var PIN_SVG = '<svg viewBox="0 0 40 56" xmlns="http://www.w3.org/2000/svg">' +
    '<path class="pin-body" d="M20 0C9 0 0 9 0 20c0 15.3 18.1 34.2 19 35.1a1.3 1.3 0 0 0 2 0C21.9 54.2 40 35.3 40 20 40 9 31 0 20 0z"/>' +
    '<circle class="pin-inner" cx="20" cy="20" r="9"/>' +
    '</svg>';

  function renderOverlay(title, imgSrc, pinX, pinY, pinLabel, fallbackMsg) {
    ensureOverlay();
    var pinHtml = '';
    if (pinX != null && pinY != null) {
      pinHtml = '<div class="ortmap-pin" style="left:' + pinX + '%;top:' + pinY + '%">' +
        '<div class="ortmap-pin-label">' + pinLabel + '</div>' +
        '<div class="ortmap-pin-icon">' + PIN_SVG + '</div>' +
        '<div class="ortmap-pin-pulse"></div></div>';
    }

    var noMatchHint = (!pinX && fallbackMsg)
      ? '<div class="ortmap-no-data" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.5)">' + fallbackMsg + '</div>'
      : '';

    overlay.innerHTML =
      '<div class="ortmap-box">' +
        '<div class="ortmap-title"><span>' + title + '</span>' +
        '<button class="ortmap-close">&times;</button></div>' +
        '<div class="ortmap-img-wrap">' +
          '<div class="ortmap-img-inner">' +
            '<img src="' + imgSrc + '" alt="Plan">' +
            pinHtml + noMatchHint +
          '</div>' +
        '</div>' +
      '</div>';

    overlay.querySelector('.ortmap-close').addEventListener('click', hide);
    overlay.style.display = 'flex';
    requestAnimationFrame(function () { overlay.classList.add('visible'); });
  }

  function showOrt(ortString) {
    ensureOverlay();
    loadVenueData().then(function (d) {
      if (!d || !d.orte || Object.keys(d.orte).length === 0) {
        overlay.innerHTML =
          '<div class="ortmap-box">' +
            '<div class="ortmap-title"><span>\ud83d\udccd ' + ortString + '</span>' +
            '<button class="ortmap-close">&times;</button></div>' +
            '<div class="ortmap-no-data">Kartendaten noch nicht verf\u00fcgbar.<br>Die Orte werden bald eingetragen.</div>' +
          '</div>';
        overlay.querySelector('.ortmap-close').addEventListener('click', hide);
        overlay.style.display = 'flex';
        requestAnimationFrame(function () { overlay.classList.add('visible'); });
        return;
      }

      var venue = findVenue(ortString);
      if (venue) {
        renderOverlay('\ud83d\udccd ' + venue.label, d.bild, venue.x, venue.y, venue.label, null);
      } else {
        renderOverlay('\ud83d\udccd ' + ortString, d.bild, null, null, null, 'Standort noch nicht eingetragen');
      }
    });
  }

  function showStand(standNr, firma) {
    loadStandData().then(function (d) {
      var titleText = firma ? '\ud83c\udfe0 ' + firma + ' [Stand ' + standNr + ']' : '\ud83c\udfe0 Stand ' + standNr;
      if (!d) {
        ensureOverlay();
        overlay.innerHTML =
          '<div class="ortmap-box">' +
            '<div class="ortmap-title"><span>' + titleText + '</span>' +
            '<button class="ortmap-close">&times;</button></div>' +
            '<div class="ortmap-no-data">Standplan-Daten nicht verf\u00fcgbar.</div>' +
          '</div>';
        overlay.querySelector('.ortmap-close').addEventListener('click', hide);
        overlay.style.display = 'flex';
        requestAnimationFrame(function () { overlay.classList.add('visible'); });
        return;
      }

      var info = findStand(standNr);
      var pinLabel = firma ? firma + '<br>Stand ' + standNr : standNr;
      if (info) {
        renderOverlay(titleText + ' \u00b7 ' + info.halleLabel, info.bild, info.x, info.y, pinLabel, null);
      } else {
        renderOverlay(titleText, '/img/plan/overview.jpg', null, null, null, 'Stand noch nicht im Plan eingetragen');
      }
    });
  }

  function hide() {
    if (!overlay) return;
    overlay.classList.remove('visible');
    setTimeout(function () { overlay.style.display = 'none'; }, 200);
  }

  // Event delegation
  document.addEventListener('click', function (e) {
    var el = e.target.closest('[data-show-ort]');
    if (el) {
      e.preventDefault();
      e.stopPropagation();
      showOrt(el.getAttribute('data-show-ort'));
      return;
    }
    el = e.target.closest('[data-show-stand]');
    if (el) {
      e.preventDefault();
      e.stopPropagation();
      showStand(el.getAttribute('data-show-stand'), el.getAttribute('data-firma') || '');
    }
  });

  // Close on Escape
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && overlay && overlay.style.display !== 'none') {
      hide();
    }
  });

  window.showOrtMap = showOrt;
  window.showStandMap = function(standNr, firma) { showStand(standNr, firma); };
})();
