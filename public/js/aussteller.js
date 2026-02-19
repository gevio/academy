/**
 * aussteller.js – Ausstellerliste, Suche, Filter, Standplan-Karte
 * Lädt /api/aussteller.json und rendert client-seitig.
 * Hallen-Config ist statisch (Prefix → Bild/Label).
 * Koordinaten kommen direkt aus den Aussteller-Daten (Notion).
 */
(function () {
  'use strict';

  const AUSSTELLER_URL = '/api/aussteller.json';
  const WORKSHOPS_URL = '/api/workshops.json';

  // Statisches Hallen-Mapping (Prefix → Bild + Label)
  const HALLEN = {
    FW:  { bild: '/img/plan/FW.jpg', label: 'Foyer West' },
    AT:  { bild: '/img/plan/FW.jpg', label: 'Foyer West (Atrium)' },
    FG:  { bild: '/img/plan/FG.jpg', label: 'Freigel\u00e4nde West' },
    FGO: { bild: '/img/plan/FG.jpg', label: 'Freigel\u00e4nde Ost' },
    A3:  { bild: '/img/plan/A3.jpg', label: 'Halle A3' },
    A4:  { bild: '/img/plan/A4.jpg', label: 'Halle A4' },
    A5:  { bild: '/img/plan/A5.jpg', label: 'Halle A5' },
    A6:  { bild: '/img/plan/A6.jpg', label: 'Halle A6' },
  };

  let allAussteller = [];
  let allWorkshops = [];
  let currentSearch = '';
  let currentKat = 'all';

  // Share-Icon SVG (YouTube-style curved arrow)
  const SHARE_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M14 9V3l8 9-8 9v-6c-7.1 0-11.7 2.1-14.6 7C.8 15.3 4.2 10.1 14 9z"/></svg>';

  // ── Logo Fallback (3 Stufen) ──
  // 1. Brandfetch CDN (logo_url) → prüfe ob Platzhalter (< 10px)
  // 2. Google Favicon API (128px)
  // 3. Letter-Avatar (CSS)
  function buildLogoImg(a, cssClass, letterClass) {
    const letter = `<div class="${letterClass}">${escapeHtml((a.firma || '?')[0].toUpperCase())}</div>`;
    if (!a.logo_url && !a.domain) return letter;

    const src = a.logo_url || '';
    const fallbackSrc = a.domain ? `https://www.google.com/s2/favicons?domain=${encodeURIComponent(a.domain)}&sz=128` : '';

    // data attrs for JS fallback handling
    return `<img class="${cssClass}" src="${escapeHtml(src || fallbackSrc)}" alt=""
      loading="lazy"
      data-fallback="${escapeHtml(fallbackSrc)}"
      data-letter="${escapeHtml((a.firma || '?')[0].toUpperCase())}"
      data-letter-class="${letterClass}"
      onerror="logoFallback(this)"
      onload="logoCheck(this)">`;
  }

  // ── Data Loading ──

  async function loadData() {
    try {
      const [ausResp, wsResp] = await Promise.all([
        fetch(AUSSTELLER_URL),
        fetch(WORKSHOPS_URL).catch(() => null),
      ]);
      if (!ausResp.ok) throw new Error('Aussteller HTTP ' + ausResp.status);
      const data = await ausResp.json();
      allAussteller = data.aussteller || [];

      // Workshop-Daten speichern + Kategorien pro Aussteller mappen
      if (wsResp && wsResp.ok) {
        const wsData = await wsResp.json();
        allWorkshops = wsData.workshops || [];
        const wsKatMap = {}; // aussteller-id → Set<kategorie>
        for (const ws of allWorkshops) {
          for (const a of (ws.aussteller || [])) {
            if (!wsKatMap[a.id]) wsKatMap[a.id] = new Set();
            for (const k of (ws.kategorien || [])) {
              wsKatMap[a.id].add(k);
            }
          }
        }
        for (const a of allAussteller) {
          a.ws_kategorien = wsKatMap[a.id] ? [...wsKatMap[a.id]].sort() : [];
        }
      }

      populateKatFilter();
      renderList();
      return true;
    } catch (err) {
      console.error('Aussteller laden fehlgeschlagen:', err);
      document.getElementById('aussteller-list').innerHTML =
        '<div class="empty">Aussteller konnten nicht geladen werden.<br>Bitte prüfe deine Internetverbindung.</div>';
    }
  }

  // ── Kategorie Filter ──

  function populateKatFilter() {
    const container = document.getElementById('tag-filters');
    // Sammle Aussteller-Kategorie + Workshop-Kategorien
    const katSet = new Set();
    allAussteller.forEach(a => {
      (a.kategorien || []).forEach(k => katSet.add(k));
      (a.ws_kategorien || []).forEach(k => katSet.add(k));
    });
    const kats = [...katSet].sort();

    kats.forEach(kat => {
      const btn = document.createElement('button');
      btn.className = 'tag-btn';
      btn.dataset.tag = kat;
      btn.textContent = kat;
      btn.addEventListener('click', () => {
        currentKat = currentKat === kat ? 'all' : kat;
        updateKatUI();
        renderList();
      });
      container.appendChild(btn);
    });
  }

  function updateKatUI() {
    document.querySelectorAll('.tag-btn').forEach(btn => {
      if (btn.dataset.tag === 'all') {
        btn.classList.toggle('active', currentKat === 'all');
      } else {
        btn.classList.toggle('active', btn.dataset.tag === currentKat);
      }
    });
  }

  // ── Filter Logic ──

  function getFilteredAussteller() {
    let list = allAussteller;

    if (currentKat !== 'all') {
      list = list.filter(a => (a.kategorien || []).includes(currentKat) ||
        (a.ws_kategorien || []).includes(currentKat));
    }

    if (currentSearch) {
      const q = currentSearch.toLowerCase();
      list = list.filter(a =>
        a.firma.toLowerCase().includes(q) ||
        (a.stand || '').toLowerCase().includes(q) ||
        (a.beschreibung || '').toLowerCase().includes(q) ||
        (a.kategorien || []).some(k => k.toLowerCase().includes(q))
      );
    }

    return list;
  }

  // ── Render ──

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function renderCard(a) {
    const katHtml = (a.kategorien || [])
      .map(k => `<span class="aussteller-tag">${escapeHtml(k)}</span>`)
      .join('');

    const wsKatHtml = (a.ws_kategorien || [])
      .map(k => `<span class="aussteller-ws-tag">${escapeHtml(k)}</span>`)
      .join('');

    const standLabel = a.stand
      ? `<span class="aussteller-card-stand">${escapeHtml(a.stand)}</span>`
      : '';

    const logoHtml = buildLogoImg(a, 'aussteller-card-logo', 'aussteller-letter-avatar');

    return `
      <div class="aussteller-card" data-stand="${escapeHtml(a.stand || '')}" data-id="${a.id}">
        ${logoHtml}
        <div class="aussteller-card-body">
          <div class="aussteller-card-firma">${escapeHtml(a.firma)}</div>
          ${standLabel}
          ${a.beschreibung ? `<div class="aussteller-card-desc">${escapeHtml(a.beschreibung)}</div>` : ''}
          ${(katHtml || wsKatHtml) ? `<div class="aussteller-tags">${katHtml}${wsKatHtml}</div>` : ''}
        </div>
        <span class="aussteller-card-arrow">\u203A</span>
      </div>`;
  }

  function renderList() {
    const container = document.getElementById('aussteller-list');
    const countEl = document.getElementById('aussteller-count');
    const filtered = getFilteredAussteller();

    if (countEl) {
      countEl.textContent = '';
    }

    if (filtered.length === 0) {
      if (allAussteller.length === 0) {
        container.innerHTML = '<div class="empty">Noch keine Aussteller verfügbar.<br>Die Standpläne werden demnächst freigeschaltet.</div>';
      } else {
        container.innerHTML = '<div class="empty">Keine Aussteller für diesen Filter gefunden.</div>';
      }
      return;
    }

    container.innerHTML = filtered.map(renderCard).join('');

    // Click handler for cards
    container.querySelectorAll('.aussteller-card').forEach(card => {
      card.addEventListener('click', () => {
        const id = card.dataset.id;
        const aussteller = allAussteller.find(a => a.id === id);
        if (aussteller) openMap(aussteller);
      });
    });
  }

  // ── Map Overlay ──

  function openMap(aussteller) {
    const overlay = document.getElementById('map-overlay');
    const headerFirma = document.getElementById('map-firma');
    const headerStand = document.getElementById('map-stand');
    const imageWrap = document.getElementById('map-image-wrap');

    // Deep-Link setzen
    history.replaceState(null, '', '#id=' + aussteller.id);

    // Header
    headerFirma.textContent = aussteller.firma;

    // Halle aus Prefix ableiten (z.B. "FG-A12" → "FG")
    const stand = aussteller.stand || '';
    const prefix = stand.includes('-') ? stand.split('-')[0] : '';
    const halle = prefix ? HALLEN[prefix] : null;
    const standData = (aussteller.stand_x != null && aussteller.stand_y != null)
      ? { x: aussteller.stand_x, y: aussteller.stand_y, w: aussteller.stand_w, h: aussteller.stand_h }
      : null;

    if (halle) {
      headerStand.textContent = `${halle.label} \u00b7 Stand ${stand}`;
    } else {
      headerStand.textContent = stand ? `Stand ${stand}` : 'Standort wird noch bekannt gegeben';
    }

    // Map image + marker
    imageWrap.innerHTML = '';
    if (halle && halle.bild) {
      // Inner wrapper: position:relative, exakt so groß wie das Bild
      // → %-basierte Marker-Positionen stimmen mit Map-Editor überein
      const innerWrap = document.createElement('div');
      innerWrap.className = 'map-pin-container';
      innerWrap.style.cssText = 'position:relative;width:100%;flex-shrink:0';
      const img = document.createElement('img');
      img.src = halle.bild;
      img.alt = halle.label;
      img.draggable = false;
      innerWrap.appendChild(img);
      imageWrap.appendChild(innerWrap);

      if (standData) {
        img.addEventListener('load', () => {
          addMarker(innerWrap, stand, standData);
          updateMarkerScale(innerWrap, img);
          setTimeout(() => {
            const marker = innerWrap.querySelector('.map-marker');
            if (marker) {
              // Manuell scrollen statt scrollIntoView (funktioniert zuverlässiger im Overlay)
              const wrapRect = imageWrap.getBoundingClientRect();
              const markerRect = marker.getBoundingClientRect();
              const scrollLeft = imageWrap.scrollLeft + (markerRect.left - wrapRect.left) - wrapRect.width / 2 + markerRect.width / 2;
              const scrollTop = imageWrap.scrollTop + (markerRect.top - wrapRect.top) - wrapRect.height / 2 + markerRect.height / 2;
              imageWrap.scrollTo({ left: scrollLeft, top: scrollTop, behavior: 'smooth' });
            }
          }, 100);
        });
        // Resize-Listener für Rotation/Fenstergröße
        const onResize = () => updateMarkerScale(innerWrap, img);
        window.addEventListener('resize', onResize);
        // Cleanup bei Close
        const origClose = closeMap;
        closeMap = function () { window.removeEventListener('resize', onResize); origClose(); };
      }
    } else if (stand) {
      imageWrap.innerHTML = '<div class="empty" style="padding:3rem 1rem">Kein Hallenplan für diesen Bereich verfügbar.<br>Stand: ' + escapeHtml(stand) + '</div>';
    } else {
      imageWrap.innerHTML = '<div class="empty" style="padding:3rem 1rem">Standort wird noch bekannt gegeben.</div>';
    }

    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';

    // \u2500\u2500 Firmensteckbrief rendern \u2500\u2500
    renderProfile(aussteller);
  }

  function renderProfile(a) {
    const container = document.getElementById('map-profile');
    if (!container) return;

    const logoHtml = buildLogoImg(a, 'map-profile-logo', 'map-profile-letter');

    // Kategorien (eigene + Workshop)
    const allKats = [...new Set([...(a.kategorien || []), ...(a.ws_kategorien || [])])];
    const tagsHtml = allKats
      .map(k => `<span class="aussteller-tag">${escapeHtml(k)}</span>`)
      .join('');

    // Links
    let linksHtml = '';
    if (a.website) {
      const display = a.website.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
      linksHtml += `<a class="map-profile-link" href="${escapeHtml(a.website)}" target="_blank" rel="noopener">\ud83c\udf10 ${escapeHtml(display)}</a>`;
    }
    if (a.instagram) {
      linksHtml += `<a class="map-profile-link" href="${escapeHtml(a.instagram)}" target="_blank" rel="noopener">\ud83d\udcf7 Instagram</a>`;
    }

    // Workshops dieses Ausstellers
    const relatedWs = allWorkshops.filter(ws =>
      (ws.aussteller || []).some(aus => aus.id === a.id)
    );
    let wsHtml = '';
    if (relatedWs.length > 0) {
      wsHtml = `<div class="map-profile-workshops">
        <div class="map-profile-workshops-title">\ud83d\udcda Workshops dieses Ausstellers</div>
        ${relatedWs.map(ws => `<a class="map-profile-ws" href="/w/${ws.id}">
          \ud83d\udccb ${escapeHtml(ws.title)}${ws.tag ? ` <span>${escapeHtml(ws.tag)}${ws.zeit ? ' \u00b7 ' + escapeHtml(ws.zeit) : ''}</span>` : ''}
        </a>`).join('')}
      </div>`;
    }

    // Share
    const shareUrl = location.origin + '/aussteller.html#id=' + a.id;
    const shareHtml = `<div class="map-profile-actions">
      <button class="map-profile-share" id="profile-share" data-title="${escapeHtml(a.firma)}" data-url="${escapeHtml(shareUrl)}">
        ${SHARE_SVG} Teilen
      </button>
    </div>`;

    container.innerHTML = `
      <div class="map-profile-header">
        ${logoHtml}
        <div class="map-profile-title">
          <h2>${escapeHtml(a.firma)}</h2>
          ${a.stand ? `<span class="map-profile-stand">${escapeHtml(a.stand)}</span>` : ''}
        </div>
      </div>
      ${a.beschreibung ? `<div class="map-profile-desc">${escapeHtml(a.beschreibung)}</div>` : ''}
      <div class="map-profile-links">${linksHtml}</div>
      <div class="map-profile-tags">${tagsHtml}</div>
      ${wsHtml}
      ${shareHtml}
    `;

    // Share click handler
    const shareBtn = document.getElementById('profile-share');
    if (shareBtn) {
      shareBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const title = shareBtn.dataset.title + ' \u2013 Adventure Southside 2026';
        const url = shareBtn.dataset.url;
        const shareData = { title: title, text: shareBtn.dataset.title + ' auf der Adventure Southside 2026', url: url };
        if (navigator.share) {
          navigator.share(shareData).catch(() => {});
        } else {
          location.href = 'mailto:?subject=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(shareData.text + '\\n\\n' + url);
        }
      });
    }
  }

  // SVG für den Teardrop-Pin
  const PIN_SVG = '<svg viewBox="0 0 40 56" xmlns="http://www.w3.org/2000/svg">' +
    '<path class="pin-body" d="M20 0C9 0 0 9 0 20c0 15.3 18.1 34.2 19 35.1a1.3 1.3 0 0 0 2 0C21.9 54.2 40 35.3 40 20 40 9 31 0 20 0z"/>' +
    '<circle class="pin-inner" cx="20" cy="20" r="9"/>' +
    '</svg>';

  function addMarker(container, stand, data) {
    const marker = document.createElement('div');
    marker.className = 'map-marker';
    marker.style.left = data.x + '%';
    marker.style.top = data.y + '%';

    // Pin-Icon
    const pin = document.createElement('div');
    pin.className = 'map-marker-pin';
    pin.innerHTML = PIN_SVG;
    marker.appendChild(pin);

    // Pulse-Ring
    const pulse = document.createElement('div');
    pulse.className = 'map-marker-pulse';
    marker.appendChild(pulse);

    // Label-Badge
    const label = document.createElement('div');
    label.className = 'map-marker-label';
    label.textContent = stand;
    // Label nach unten wenn Marker im oberen Bereich (<20%)
    if (data.y < 20) marker.classList.add('label-below');
    marker.appendChild(label);

    container.appendChild(marker);
  }

  /**
   * Berechne --marker-scale anhand Bild-Skalierung.
   * Marker soll bei voller Bildgröße scale=1 sein,
   * bei kleinerer Ansicht proportional mitskalieren (min 0.5).
   */
  function updateMarkerScale(container, img) {
    if (!img || !img.naturalWidth) return;
    const ratio = img.clientWidth / img.naturalWidth;
    // Clamp: nicht kleiner als 0.5, nicht größer als 1.2
    const scale = Math.max(0.5, Math.min(1.2, ratio * 1.6));
    container.style.setProperty('--marker-scale', scale.toFixed(3));
  }

  function closeMap() {
    const overlay = document.getElementById('map-overlay');
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('map-image-wrap').innerHTML = '';
    const profile = document.getElementById('map-profile');
    if (profile) profile.innerHTML = '';
    // Zurück-Navigation: ?back= Parameter nutzen wenn vorhanden
    const params = new URLSearchParams(window.location.search);
    const backUrl = params.get('back');
    if (backUrl) {
      window.location.href = backUrl;
      return;
    }
    // Sonst nur Deep-Link entfernen
    history.replaceState(null, '', window.location.pathname);
  }

  // ── Events ──

  function initSearch() {
    const input = document.getElementById('search-input');
    if (!input) return;

    let debounce = null;
    input.addEventListener('input', () => {
      clearTimeout(debounce);
      debounce = setTimeout(() => {
        currentSearch = input.value.trim();
        renderList();
      }, 200);
    });
  }

  function initKatFilters() {
    const allBtn = document.querySelector('.tag-btn[data-tag="all"]');
    if (allBtn) {
      allBtn.addEventListener('click', () => {
        currentKat = 'all';
        updateKatUI();
        renderList();
      });
    }
  }

  function initMapClose() {
    document.getElementById('map-back').addEventListener('click', closeMap);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeMap();
    });
  }

  // ── Init ──

  document.addEventListener('DOMContentLoaded', () => {
    initSearch();
    initKatFilters();
    initMapClose();
    loadData().then(() => {
      // Deep-Link: #id=xxx beim Laden auswerten
      const hash = window.location.hash;
      const match = hash.match(/^#id=([a-f0-9]{32})$/);
      if (match) {
        const aussteller = allAussteller.find(a => a.id === match[1]);
        if (aussteller) openMap(aussteller);
      }
    });
  });

  // ── Globale Logo-Fallback-Funktionen ──
  // Müssen global sein wegen inline onerror/onload

  function replaceWithLetter(img) {
    const letter = img.dataset.letter || '?';
    const cls = img.dataset.letterClass || 'aussteller-letter-avatar';
    const div = document.createElement('div');
    div.className = cls;
    div.textContent = letter;
    img.replaceWith(div);
  }

  // onerror: versuche Google Favicon Fallback, dann Letter-Avatar
  window.logoFallback = function(img) {
    const fallback = img.dataset.fallback;
    if (fallback && img.src !== fallback) {
      img.dataset.fallback = ''; // nur 1x versuchen
      img.src = fallback;
    } else {
      replaceWithLetter(img);
    }
  };

  // onload: prüfe ob Brandfetch einen winzigen Platzhalter geliefert hat
  window.logoCheck = function(img) {
    if (img.naturalWidth < 10 || img.naturalHeight < 10) {
      const fallback = img.dataset.fallback;
      if (fallback && img.src !== fallback) {
        img.dataset.fallback = '';
        img.src = fallback;
      } else if (!fallback) {
        replaceWithLetter(img);
      }
    }
  };

})();
