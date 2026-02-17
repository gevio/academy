/**
 * aussteller.js – Ausstellerliste, Suche, Filter, Standplan-Karte
 * Lädt /api/aussteller.json und rendert client-seitig.
 * Hallen-Config ist statisch (Prefix → Bild/Label).
 * Koordinaten kommen direkt aus den Aussteller-Daten (Notion).
 */
(function () {
  'use strict';

  const AUSSTELLER_URL = '/api/aussteller.json';

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
  let currentSearch = '';
  let currentKat = 'all';

  // ── Data Loading ──

  async function loadData() {
    try {
      const resp = await fetch(AUSSTELLER_URL);
      if (!resp.ok) throw new Error('Aussteller HTTP ' + resp.status);
      const data = await resp.json();
      allAussteller = data.aussteller || [];

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
    const kats = [...new Set(allAussteller.map(a => a.kategorie).filter(Boolean))].sort();

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
      list = list.filter(a => a.kategorie === currentKat);
    }

    if (currentSearch) {
      const q = currentSearch.toLowerCase();
      list = list.filter(a =>
        a.firma.toLowerCase().includes(q) ||
        (a.stand || '').toLowerCase().includes(q) ||
        (a.beschreibung || '').toLowerCase().includes(q) ||
        (a.kategorie || '').toLowerCase().includes(q)
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
    const katHtml = a.kategorie
      ? `<span class="aussteller-tag">${escapeHtml(a.kategorie)}</span>`
      : '';

    const standLabel = a.stand
      ? `<span class="aussteller-card-stand">${escapeHtml(a.stand)}</span>`
      : '';

    return `
      <div class="aussteller-card" data-stand="${escapeHtml(a.stand || '')}" data-id="${a.id}">
        <div class="aussteller-card-body">
          <div class="aussteller-card-firma">${escapeHtml(a.firma)}</div>
          ${standLabel}
          ${a.beschreibung ? `<div class="aussteller-card-desc">${escapeHtml(a.beschreibung)}</div>` : ''}
          ${katHtml ? `<div class="aussteller-tags">${katHtml}</div>` : ''}
        </div>
        <span class="aussteller-card-arrow">\u203A</span>
      </div>`;
  }

  function renderList() {
    const container = document.getElementById('aussteller-list');
    const countEl = document.getElementById('aussteller-count');
    const filtered = getFilteredAussteller();

    if (countEl) {
      const total = allAussteller.length;
      if (currentSearch || currentKat !== 'all') {
        countEl.textContent = `${filtered.length} von ${total} Ausstellern`;
      } else {
        countEl.textContent = `${total} Aussteller`;
      }
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
    const footerDesc = document.getElementById('map-desc');
    const footerTags = document.getElementById('map-tags');
    const footerLink = document.getElementById('map-link');
    const footerInsta = document.getElementById('map-insta');

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
      }
    } else if (stand) {
      imageWrap.innerHTML = '<div class="empty" style="padding:3rem 1rem">Kein Hallenplan für diesen Bereich verfügbar.<br>Stand: ' + escapeHtml(stand) + '</div>';
    } else {
      imageWrap.innerHTML = '<div class="empty" style="padding:3rem 1rem">Standort wird noch bekannt gegeben.</div>';
    }

    // Footer info
    footerDesc.textContent = aussteller.beschreibung || '';
    footerTags.innerHTML = aussteller.kategorie
      ? `<span class="aussteller-tag">${escapeHtml(aussteller.kategorie)}</span>`
      : '';

    if (aussteller.website) {
      footerLink.href = aussteller.website;
      footerLink.textContent = '🌐 ' + aussteller.website.replace(/^https?:\/\/(www\.)?/, '').replace(/\/$/, '');
      footerLink.style.display = '';
    } else {
      footerLink.style.display = 'none';
    }

    if (aussteller.instagram) {
      footerInsta.href = aussteller.instagram;
      footerInsta.textContent = '📷 Instagram';
      footerInsta.style.display = '';
    } else {
      footerInsta.style.display = 'none';
    }

    overlay.classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function addMarker(container, stand, data) {
    const marker = document.createElement('div');

    if (data.w && data.h) {
      marker.className = 'map-marker rect-variant';
      marker.style.left = (data.x - data.w / 2) + '%';
      marker.style.top = (data.y - data.h / 2) + '%';
      marker.style.width = data.w + '%';
      marker.style.height = data.h + '%';
    } else {
      marker.className = 'map-marker';
      marker.style.left = data.x + '%';
      marker.style.top = data.y + '%';
    }

    const dot = document.createElement('div');
    dot.className = 'map-marker-dot';
    marker.appendChild(dot);

    const label = document.createElement('div');
    label.className = 'map-marker-label';
    label.textContent = stand;
    marker.appendChild(label);

    container.appendChild(marker);
  }

  function closeMap() {
    const overlay = document.getElementById('map-overlay');
    overlay.classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('map-image-wrap').innerHTML = '';
    // Deep-Link entfernen
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
})();
