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
  const AUS_FAV_KEY = 'as26_fav_aussteller';
  const ADMIN_KEY = 'asa_admin_secret';

  // ── Admin-Modus (gleich wie details.html) ────────────
  (function initAdmin() {
    const params = new URLSearchParams(location.search);
    const secret = params.get('admin');
    if (secret) {
      sessionStorage.setItem(ADMIN_KEY, secret);
      params.delete('admin');
      const cleanUrl = location.pathname + (params.toString() ? '?' + params.toString() : '') + location.hash;
      history.replaceState(null, '', cleanUrl);
    }
  })();

  function isAdmin() { return !!sessionStorage.getItem(ADMIN_KEY); }
  function getAdminSecret() { return sessionStorage.getItem(ADMIN_KEY) || ''; }

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

  // ── Aussteller-Favoriten ──

  function getAusstellerFavorites() {
    try { return JSON.parse(localStorage.getItem(AUS_FAV_KEY) || '[]'); } catch { return []; }
  }

  function toggleAusstellerFavorite(id) {
    const favs = getAusstellerFavorites();
    const i = favs.indexOf(id);
    if (i >= 0) favs.splice(i, 1); else favs.push(id);
    try { localStorage.setItem(AUS_FAV_KEY, JSON.stringify(favs)); } catch {}
    return i < 0; // true = hinzugefügt
  }

  // Share-Icon SVG (YouTube-style curved arrow)
  const SHARE_SVG = '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M14 9V3l8 9-8 9v-6c-7.1 0-11.7 2.1-14.6 7C.8 15.3 4.2 10.1 14 9z"/></svg>';

  // ── Logo Fallback (3 Stufen) ──
  // 1. Brandfetch CDN (logo_url) → prüfe ob Platzhalter (< 10px)
  // Logo-Fallback-Kette:
  // 1. logo_local (manuell in Notion hochgeladen, lokal gespeichert)
  // 2. logo_url (Brandfetch CDN)
  // 3. Google Favicon API (128px)
  // 4. Letter-Avatar (CSS)
  function buildLogoImg(a, cssClass, letterClass) {
    const letter = `<div class="${letterClass}">${escapeHtml((a.firma || '?')[0].toUpperCase())}</div>`;
    if (!a.logo_local && !a.logo_url && !a.domain) return letter;

    // Beste verfügbare Quelle als src
    const src = a.logo_local || a.logo_url || '';
    // Fallback-Kette: wenn logo_local → brandfetch → favicon, wenn brandfetch → favicon
    let fallbackSrc = '';
    if (a.logo_local && a.logo_url) {
      fallbackSrc = a.logo_url; // brandfetch als 1. Fallback
    } else if (a.domain) {
      fallbackSrc = `https://www.google.com/s2/favicons?domain=${encodeURIComponent(a.domain)}&sz=128`;
    }
    const faviconSrc = a.domain ? `https://www.google.com/s2/favicons?domain=${encodeURIComponent(a.domain)}&sz=128` : '';

    // data attrs for JS fallback handling
    return `<img class="${cssClass}" src="${escapeHtml(src || fallbackSrc)}" alt=""
      loading="lazy"
      data-fallback="${escapeHtml(fallbackSrc)}"
      data-favicon="${escapeHtml(faviconSrc)}"
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
      window._as26Aussteller = allAussteller; // für Chat-Assistent

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
    const select = document.getElementById('kat-filter');
    if (!select) return;
    // Sammle Aussteller-Kategorie + Workshop-Kategorien
    const katSet = new Set();
    allAussteller.forEach(a => {
      (a.kategorien || []).forEach(k => katSet.add(k));
      (a.ws_kategorien || []).forEach(k => katSet.add(k));
    });
    const kats = [...katSet].sort();

    kats.forEach(kat => {
      const opt = document.createElement('option');
      opt.value = kat;
      opt.textContent = kat;
      select.appendChild(opt);
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
      const q = currentSearch.trim();
      // Regex mit Wortgrenze für präzisere Treffer
      const escaped = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      const re = new RegExp('\\b' + escaped, 'i');

      list = list.filter(a => {
        // Firma und Stand immer durchsuchen
        if (re.test(a.firma)) return true;
        if (re.test(a.stand || '')) return true;
        if ((a.kategorien || []).some(k => re.test(k))) return true;
        // Beschreibung nur bei >= 3 Zeichen durchsuchen
        if (q.length >= 3 && re.test(a.beschreibung || '')) return true;
        return false;
      });
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
    const isFav = getAusstellerFavorites().includes(a.id);
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
        <button class="aus-fav-btn${isFav ? ' active' : ''}" data-id="${a.id}" title="${isFav ? 'Aus Merkliste entfernen' : 'Besuchen merken'}" aria-label="Merken">
          <svg viewBox="0 0 24 24" width="18" height="18" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="${isFav ? 'currentColor' : 'none'}"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
        </button>
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

    // Fav-Button Handler
    container.querySelectorAll('.aus-fav-btn').forEach(btn => {
      btn.addEventListener('click', e => {
        e.stopPropagation();
        const id = btn.dataset.id;
        const isNowFav = toggleAusstellerFavorite(id);
        btn.classList.toggle('active', isNowFav);
        btn.title = isNowFav ? 'Aus Merkliste entfernen' : 'Besuchen merken';
        const svg = btn.querySelector('svg');
        if (svg) svg.setAttribute('fill', isNowFav ? 'currentColor' : 'none');
        if (isNowFav && window.as26Analytics) {
          window.as26Analytics.track('feature_use', { feature: 'favorite_exhibitor', payload: { id: id } });
        }
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

    // Messe-Special
    let messeSpecialHtml = '';
    if (a.messe_special) {
      messeSpecialHtml = `<div class="map-profile-special">
        <div class="map-profile-special-badge">🎯 Messe-Special</div>
        <div class="map-profile-special-text">${escapeHtml(a.messe_special)}</div>
      </div>`;
    }

    // Links
    let linksHtml = '';
    if (a.website) {
      const display = a.website.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
      linksHtml += `<a class="map-profile-link" href="${escapeHtml(a.website)}" target="_blank" rel="noopener">\ud83c\udf10 ${escapeHtml(display)}</a>`;
    }
    if (a.webshop) {
      const shopDisplay = a.webshop.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
      linksHtml += `<a class="map-profile-link" href="${escapeHtml(a.webshop)}" target="_blank" rel="noopener">🛒 ${escapeHtml(shopDisplay)}</a>`;
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

    // Fav + Share
    const isFav = getAusstellerFavorites().includes(a.id);
    const shareUrl = location.origin + '/aussteller.html#id=' + a.id;
    const shareHtml = `<div class="map-profile-actions">
      <button class="map-profile-fav${isFav ? ' active' : ''}" id="profile-fav" data-id="${a.id}">
        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="${isFav ? 'currentColor' : 'none'}"><path d="M19 21l-7-5-7 5V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2z"/></svg>
        ${isFav ? 'Gemerkt' : 'Besuchen merken'}
      </button>
      <button class="map-profile-share" id="profile-share" data-title="${escapeHtml(a.firma)}" data-url="${escapeHtml(shareUrl)}">
        ${SHARE_SVG} Teilen
      </button>
    </div>`;

    // Admin: Review-Button
    let adminHtml = '';
    if (isAdmin()) {
      adminHtml = `<div class="admin-actions" style="margin:.8rem 0;padding:.6rem;background:#fff3cd;border:2px dashed #e76f51;border-radius:8px;text-align:center">
        <span style="font-size:.75rem;color:#666;display:block;margin-bottom:.4rem">🔧 Admin</span>
        <button class="btn btn-primary" id="send-aussteller-review-btn" data-id="${a.id}" data-firma="${escapeHtml(a.firma)}"
            style="background:#e76f51;border:none;font-weight:600;padding:.5rem 1rem;color:#fff;border-radius:6px;cursor:pointer">
            📧 Review an Aussteller senden
        </button>
        <div id="aussteller-review-result" style="margin-top:.5rem;font-size:.85rem"></div>
      </div>`;
    }

    container.innerHTML = `
      <div class="map-profile-header">
        ${logoHtml}
        <div class="map-profile-title">
          <h2>${escapeHtml(a.firma)}</h2>
          ${a.stand ? `<span class="map-profile-stand">${escapeHtml(a.stand)}</span>` : ''}
        </div>
      </div>
      ${a.beschreibung ? `<div class="map-profile-desc">${escapeHtml(a.beschreibung)}</div>` : ''}
      ${messeSpecialHtml}
      <div class="map-profile-links">${linksHtml}</div>
      <div class="map-profile-tags">${tagsHtml}</div>
      ${wsHtml}
      ${shareHtml}
      ${adminHtml}
    `;

    // Profile Fav click handler
    const profileFavBtn = document.getElementById('profile-fav');
    if (profileFavBtn) {
      profileFavBtn.addEventListener('click', () => {
        const id = profileFavBtn.dataset.id;
        const isNowFav = toggleAusstellerFavorite(id);
        if (isNowFav && window.as26Analytics) {
          window.as26Analytics.track('feature_use', { feature: 'favorite_exhibitor', payload: { id: id } });
        }
        profileFavBtn.classList.toggle('active', isNowFav);
        const svg = profileFavBtn.querySelector('svg');
        if (svg) svg.setAttribute('fill', isNowFav ? 'currentColor' : 'none');
        profileFavBtn.childNodes[profileFavBtn.childNodes.length - 1].textContent =
          isNowFav ? ' Gemerkt' : ' Besuchen merken';
        // Auch die Card-Liste aktualisieren
        const card = document.querySelector(`.aussteller-card[data-id="${id}"] .aus-fav-btn`);
        if (card) {
          card.classList.toggle('active', isNowFav);
          const cSvg = card.querySelector('svg');
          if (cSvg) cSvg.setAttribute('fill', isNowFav ? 'currentColor' : 'none');
        }
      });
    }

    // Share click handler
    const shareBtn = document.getElementById('profile-share');
    if (shareBtn) {
      shareBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        const title = shareBtn.dataset.title + ' \u2013 Adventure Southside 2026';
        const url = shareBtn.dataset.url;
        const shareData = { title: title, text: shareBtn.dataset.title + ' auf der Adventure Southside 2026', url: url };
        if (window.as26Analytics) {
          window.as26Analytics.track('feature_use', { feature: 'share_exhibitor', payload: { id: shareBtn.dataset.id || '' } });
        }
        if (navigator.share) {
          navigator.share(shareData).catch(() => {});
        } else {
          location.href = 'mailto:?subject=' + encodeURIComponent(title) + '&body=' + encodeURIComponent(shareData.text + '\\n\\n' + url);
        }
      });
    }

    // Admin: Review senden Event
    const reviewBtn = document.getElementById('send-aussteller-review-btn');
    if (reviewBtn) {
      reviewBtn.addEventListener('click', async () => {
        const ausId = reviewBtn.dataset.id;
        const firma = reviewBtn.dataset.firma;
        const resultEl = document.getElementById('aussteller-review-result');

        const deadlineDefault = new Date(Date.now() + 14 * 86400000).toISOString().split('T')[0];
        const deadline = prompt(
          `Review für "${firma}" erstellen?\n\nDeadline (YYYY-MM-DD):`,
          deadlineDefault
        );
        if (!deadline) return;

        reviewBtn.disabled = true;
        reviewBtn.textContent = '⏳ Wird erstellt…';
        resultEl.textContent = '';

        try {
          const resp = await fetch('/api/send-aussteller-review.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-Admin-Secret': getAdminSecret(),
            },
            body: JSON.stringify({ aussteller_id: ausId, deadline: deadline }),
          });
          const data = await resp.json();

          if (data.success) {
            let emailInfo = '';
            if (data.email_result) {
              emailInfo = data.email_result.ok
                ? `<small>✅ E-Mail-Draft: ${data.email_result.name} (${data.email_result.email})</small>`
                : `<small>⚠️ E-Mail-Draft fehlgeschlagen</small>`;
            }
            if (data.warning) {
              emailInfo += `<br><small>⚠️ ${data.warning}</small>`;
            }
            resultEl.innerHTML = `<span style="color:green">✅ Review erstellt!</span><br>
              ${emailInfo}<br>
              <a href="${data.review_url}" target="_blank" style="color:#e76f51">→ Review-Seite öffnen</a>`;
            reviewBtn.textContent = '✅ Erstellt';
          } else {
            resultEl.innerHTML = `<span style="color:red">❌ ${data.error || 'Fehler'}</span>`;
            reviewBtn.textContent = '📧 Review an Aussteller senden';
            reviewBtn.disabled = false;
          }
        } catch (err) {
          resultEl.innerHTML = `<span style="color:red">❌ Netzwerkfehler</span>`;
          reviewBtn.textContent = '📧 Review an Aussteller senden';
          reviewBtn.disabled = false;
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

  function initKatFilter() {
    const select = document.getElementById('kat-filter');
    if (!select) return;
    select.addEventListener('change', () => {
      currentKat = select.value;
      renderList();
    });
  }

  function initMapClose() {
    document.getElementById('map-back').addEventListener('click', closeMap);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') closeMap();
    });
  }

  // ── Init ──

  function openFromHash() {
    const match = window.location.hash.match(/^#id=([a-f0-9]{32})$/);
    if (match) {
      const aussteller = allAussteller.find(a => a.id === match[1]);
      if (aussteller) openMap(aussteller);
    }
  }

  document.addEventListener('DOMContentLoaded', () => {
    initSearch();
    initKatFilter();
    initMapClose();
    loadData().then(() => openFromHash());
  });

  window.addEventListener('hashchange', openFromHash);

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

  // onerror: versuche nächsten Fallback in der Kette
  window.logoFallback = function(img) {
    const fallback = img.dataset.fallback;
    const favicon  = img.dataset.favicon;
    if (fallback && img.src !== fallback) {
      img.dataset.fallback = ''; // 1. Fallback verbraucht
      img.src = fallback;
    } else if (favicon && img.src !== favicon) {
      img.dataset.favicon = ''; // Favicon verbraucht
      img.src = favicon;
    } else {
      replaceWithLetter(img);
    }
  };

  // onload: prüfe ob Brandfetch einen winzigen Platzhalter geliefert hat
  window.logoCheck = function(img) {
    if (img.naturalWidth < 10 || img.naturalHeight < 10) {
      const fallback = img.dataset.fallback;
      const favicon  = img.dataset.favicon;
      if (fallback && img.src !== fallback) {
        img.dataset.fallback = '';
        img.src = fallback;
      } else if (favicon && img.src !== favicon) {
        img.dataset.favicon = '';
        img.src = favicon;
      } else {
        replaceWithLetter(img);
      }
    }
  };

})();
