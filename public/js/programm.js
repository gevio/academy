/**
 * programm.js – Programmübersicht, Filter, Favoriten
 * Lädt /api/workshops.json (vom Service Worker gecacht) und rendert client-seitig.
 * Filter-State wird in der URL gehalten (Ortskonstanz).
 */
(function () {
  'use strict';

  const STORAGE_KEY = 'as26_favorites';
  const API_URL = '/api/workshops.json';

  let allWorkshops = [];
  let currentDay = 'all';
  let currentTyp = 'all';
  let currentOrt = 'all';
  let currentKat = 'all';
  let currentTab = 'programm';
  let focusId = null;

  // ── URL State ──

  function readStateFromUrl() {
    const p = new URLSearchParams(location.search);
    currentDay = p.get('tag') || 'all';
    currentTyp = p.get('typ') || 'all';
    currentOrt = p.get('ort') || 'all';
    currentKat = p.get('kategorie') || 'all';
    focusId = p.get('focus') || null;
    if (p.get('tab') === 'favoriten') currentTab = 'favoriten';
  }

  function writeStateToUrl() {
    const p = new URLSearchParams();
    if (currentDay !== 'all') p.set('tag', currentDay);
    if (currentTyp !== 'all') p.set('typ', currentTyp);
    if (currentOrt !== 'all') p.set('ort', currentOrt);
    if (currentKat !== 'all') p.set('kategorie', currentKat);
    if (currentTab === 'favoriten') p.set('tab', 'favoriten');
    const qs = p.toString();
    const url = location.pathname + (qs ? '?' + qs : '');
    history.replaceState(null, '', url);
  }

  function buildBackParam(workshopId) {
    const p = new URLSearchParams();
    if (currentDay !== 'all') p.set('tag', currentDay);
    if (currentTyp !== 'all') p.set('typ', currentTyp);
    if (currentOrt !== 'all') p.set('ort', currentOrt);
    if (currentKat !== 'all') p.set('kategorie', currentKat);
    if (currentTab === 'favoriten') p.set('tab', 'favoriten');
    p.set('focus', workshopId);
    return '/programm.html?' + p.toString();
  }

  // ── State ──

  function getFavorites() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '[]'); }
    catch { return []; }
  }

  function saveFavorites(arr) {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(arr));
    updateFavCount();
  }

  function toggleFavorite(id) {
    const favs = getFavorites();
    const idx = favs.indexOf(id);
    if (idx > -1) favs.splice(idx, 1);
    else favs.push(id);
    saveFavorites(favs);
    return favs.includes(id);
  }

  function updateFavCount() {
    const el = document.getElementById('fav-count');
    const count = getFavorites().length;
    el.textContent = count > 0 ? count : '';
  }

  // ── Data ──

  async function loadWorkshops() {
    try {
      const resp = await fetch(API_URL);
      if (!resp.ok) throw new Error('HTTP ' + resp.status);
      const data = await resp.json();
      allWorkshops = data.workshops || [];
      populateTypFilter();
      populateOrtFilter();
      populateKatFilter();
      applyStateToUI();
      renderList();
      handleFocus();
    } catch (err) {
      console.error('Workshops laden fehlgeschlagen:', err);
      document.getElementById('workshop-list').innerHTML =
        '<div class="empty">Programm konnte nicht geladen werden.<br>Bitte prüfe deine Internetverbindung.</div>';
    }
  }

  function applyStateToUI() {
    // Day buttons
    document.querySelectorAll('.day-btn').forEach(b => {
      b.classList.toggle('active', b.dataset.day === currentDay);
    });
    // Typ select
    const typSelect = document.getElementById('typ-filter');
    if (typSelect) typSelect.value = currentTyp;
    // Ort select
    const ortSelect = document.getElementById('ort-filter');
    if (ortSelect) ortSelect.value = currentOrt;
    // Kategorie select
    const katSelect = document.getElementById('kat-filter');
    if (katSelect) katSelect.value = currentKat;
    // Tab
    document.querySelectorAll('.tab').forEach(t => {
      t.classList.toggle('active', t.dataset.tab === currentTab);
    });
    document.querySelector('.filter-bar').style.display =
      currentTab === 'programm' ? '' : 'none';
  }

  function handleFocus() {
    if (!focusId) return;
    const el = document.querySelector(`.prog-card[data-id="${focusId}"]`);
    if (el) {
      // Kurz warten damit Layout steht, dann scrollen
      requestAnimationFrame(() => {
        el.scrollIntoView({ block: 'center', behavior: 'instant' });
        el.classList.add('focus-highlight');
        // Highlight nach 2s entfernen
        setTimeout(() => el.classList.remove('focus-highlight'), 2000);
      });
    }
    // Focus aus URL entfernen, damit es nicht "klebt"
    focusId = null;
    writeStateToUrl();
  }

  function populateTypFilter() {
    const select = document.getElementById('typ-filter');
    const types = [...new Set(allWorkshops.map(w => w.typ).filter(Boolean))].sort();
    types.forEach(t => {
      const opt = document.createElement('option');
      opt.value = t;
      opt.textContent = t;
      select.appendChild(opt);
    });
  }

  function populateOrtFilter() {
    const select = document.getElementById('ort-filter');
    const orte = [...new Set(allWorkshops.map(w => w.ort).filter(Boolean))].sort();
    orte.forEach(o => {
      const opt = document.createElement('option');
      opt.value = o;
      opt.textContent = o;
      select.appendChild(opt);
    });
  }

  function populateKatFilter() {
    const select = document.getElementById('kat-filter');
    const kats = [...new Set(allWorkshops.flatMap(w => w.kategorien || []).filter(Boolean))].sort();
    kats.forEach(k => {
      const opt = document.createElement('option');
      opt.value = k;
      opt.textContent = k;
      select.appendChild(opt);
    });
  }

  // ── Filter ──

  function getFilteredWorkshops() {
    let list = allWorkshops;
    if (currentDay !== 'all') {
      list = list.filter(w => w.tag === currentDay);
    }
    if (currentTyp !== 'all') {
      list = list.filter(w => w.typ === currentTyp);
    }
    if (currentOrt !== 'all') {
      list = list.filter(w => w.ort === currentOrt);
    }
    if (currentKat !== 'all') {
      list = list.filter(w => (w.kategorien || []).includes(currentKat));
    }
    return list;
  }

  function isFilterActive() {
    return currentDay !== 'all' || currentTyp !== 'all' || currentOrt !== 'all' || currentKat !== 'all';
  }

  function updateResultSummary(filteredCount) {
    const el = document.getElementById('result-summary');
    if (!el) return;
    const total = allWorkshops.length;
    if (currentTab !== 'programm') {
      el.style.display = 'none';
      return;
    }
    el.style.display = '';
    if (isFilterActive()) {
      el.textContent = `${total} Veranstaltungen insgesamt (${filteredCount} passen zu Deinem Filter)`;
    } else {
      el.textContent = `${total} Veranstaltungen insgesamt`;
    }
  }

  // ── Render ──

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function formatReferent(ws) {
    const persons = ws.referent_persons || [];
    const firma = ws.referent_firma || '';
    const aussteller = ws.aussteller || [];
    const personHtml = persons.length
      ? persons.map(p => `<a href="/experte.html#id=${p.id}" style="color:inherit;text-decoration:underline;text-decoration-color:var(--as-rot);text-underline-offset:2px">${escapeHtml(p.name)}</a>`).join(', ')
      : '';

    // Firma: Aussteller-Link hat Vorrang, dann referent_firma als Fallback
    let firmaHtml = '';
    if (aussteller.length > 0) {
      firmaHtml = aussteller.map(a => {
        if (a.stand) {
          return `<span data-show-stand="${escapeHtml(a.stand)}" data-firma="${escapeHtml(a.firma)}">🏪 ${escapeHtml(a.firma)} [Stand ${escapeHtml(a.stand)}]</span>`;
        }
        return `<span>🏪 ${escapeHtml(a.firma)}</span>`;
      }).join(', ');
    } else if (firma) {
      firmaHtml = escapeHtml(firma);
    }

    if (personHtml && firmaHtml) return `${personHtml}<div style="margin-top:.35rem">${firmaHtml}</div>`;
    if (firmaHtml) return firmaHtml;
    if (personHtml) return personHtml;
    return 'N.N.';
  }

  function renderCard(ws) {
    const favs = getFavorites();
    const isFav = favs.includes(ws.id);

    // Kategorien als kleine Tags
    const kats = (ws.kategorien || []);
    const katHtml = kats.length
      ? kats.map(k => `<span class="kat-tag">${escapeHtml(k)}</span>`).join('')
      : '';

    // Typ + Kategorien Zeile
    const typKatRow = `<div class="typ-kat-row">
      <span class="typ-badge">${escapeHtml(ws.typ)}</span>${katHtml}
    </div>`;

    // Meta-Zeile: Zeit, Ort, Referent
    const referentHtml = `<span class="meta-item">🎤 ${formatReferent(ws)}</span>`;

    return `
      <div class="prog-card" data-id="${ws.id}">
        <div class="prog-card-body">
          <a href="/w/${ws.id}?back=${encodeURIComponent(buildBackParam(ws.id))}" class="prog-card-title">${escapeHtml(ws.title)}</a>
          ${typKatRow}
          <div class="meta-row">
            ${ws.zeit ? `<span class="meta-item">🕐 ${escapeHtml(ws.zeit)}</span>` : ''}
            ${ws.ort ? `<span class="meta-item" data-show-ort="${escapeHtml(ws.ort)}">📍 ${escapeHtml(ws.ort)}</span>` : ''}
            ${referentHtml}
          </div>
        </div>
        <div class="prog-card-actions">
          <button class="fav-btn ${isFav ? 'active' : ''}" data-id="${ws.id}" title="Favorit">
            ${isFav ? '❤️' : '🤍'}
          </button>
          <button class="share-ws-btn" data-id="${ws.id}" data-title="${escapeHtml(ws.title)}" title="Teilen">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M14 9V3l8 9-8 9v-6c-7.1 0-11.7 2.1-14.6 7C.8 15.3 4.2 10.1 14 9z"/></svg>
          </button>
        </div>
      </div>`;
  }

  function renderList() {
    const container = document.getElementById('workshop-list');
    const favContainer = document.getElementById('favoriten-list');

    if (currentTab === 'programm') {
      container.style.display = '';
      favContainer.style.display = 'none';

      const filtered = getFilteredWorkshops();
      updateResultSummary(filtered.length);
      if (filtered.length === 0) {
        container.innerHTML = '<div class="empty">Keine Veranstaltungen für diesen Filter gefunden.</div>';
        return;
      }

      // Nach Tag gruppieren
      let html = '';
      let lastDay = '';
      filtered.forEach(ws => {
        if (ws.tag !== lastDay && currentDay === 'all') {
          html += `<div class="day-divider">${escapeHtml(ws.tag)}</div>`;
          lastDay = ws.tag;
        }
        html += renderCard(ws);
      });
      container.innerHTML = html;
    } else {
      // Favoriten
      container.style.display = 'none';
      favContainer.style.display = '';

      const favs = getFavorites();
      const favWorkshops = allWorkshops.filter(w => favs.includes(w.id));

      if (favWorkshops.length === 0) {
        favContainer.innerHTML = '<div class="empty">Noch keine Favoriten gespeichert.<br>Tippe auf 🤍 um Workshops zu merken.</div>';
        return;
      }

      updateResultSummary(0);
      let html = `<div class="result-count">${favWorkshops.length} Favorit${favWorkshops.length !== 1 ? 'en' : ''}</div>`;
      let lastDay = '';
      favWorkshops.forEach(ws => {
        if (ws.tag !== lastDay) {
          html += `<div class="day-divider">${escapeHtml(ws.tag)}</div>`;
          lastDay = ws.tag;
        }
        html += renderCard(ws);
      });
      favContainer.innerHTML = html;
    }

    // Delegated event listeners für Fav-Buttons
    bindFavButtons();
  }

  function bindFavButtons() {
    document.querySelectorAll('.fav-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const id = btn.dataset.id;
        const isNowFav = toggleFavorite(id);
        btn.classList.toggle('active', isNowFav);
        btn.textContent = isNowFav ? '❤️' : '🤍';
        // Bei Favoriten-Tab: Liste neu rendern
        if (currentTab === 'favoriten') {
          renderList();
        }
      });
    });
    // Share-Buttons
    document.querySelectorAll('.share-ws-btn').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        const id = btn.dataset.id;
        const title = btn.dataset.title;
        const url = location.origin + '/w/' + id;
        const shareData = { title: title + ' – AS26 Live', text: title, url: url };
        if (navigator.share) {
          navigator.share(shareData).catch(() => {});
        } else {
          location.href = 'mailto:?subject=' + encodeURIComponent(shareData.title) + '&body=' + encodeURIComponent(shareData.text + '\n\n' + shareData.url);
        }
      });
    });
  }

  // ── Events ──

  function initTabs() {
    document.querySelectorAll('.tab').forEach(tab => {
      tab.addEventListener('click', () => {
        document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        currentTab = tab.dataset.tab;

        // Filter nur im Programm-Tab zeigen
        document.querySelector('.filter-bar').style.display =
          currentTab === 'programm' ? '' : 'none';

        writeStateToUrl();
        renderList();
      });
    });
  }

  function initDayFilters() {
    document.getElementById('day-filters').addEventListener('click', (e) => {
      const btn = e.target.closest('.day-btn');
      if (!btn) return;
      document.querySelectorAll('.day-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      currentDay = btn.dataset.day;
      writeStateToUrl();
      renderList();
    });
  }

  function initTypFilter() {
    document.getElementById('typ-filter').addEventListener('change', (e) => {
      currentTyp = e.target.value;
      writeStateToUrl();
      renderList();
    });
  }

  function initOrtFilter() {
    document.getElementById('ort-filter').addEventListener('change', (e) => {
      currentOrt = e.target.value;
      writeStateToUrl();
      renderList();
    });
  }

  function initKatFilter() {
    document.getElementById('kat-filter').addEventListener('change', (e) => {
      currentKat = e.target.value;
      writeStateToUrl();
      renderList();
    });
  }

  // ── Init ──

  document.addEventListener('DOMContentLoaded', () => {
    readStateFromUrl();
    updateFavCount();
    initTabs();
    initDayFilters();
    initTypFilter();
    initOrtFilter();
    initKatFilter();
    loadWorkshops();
  });
})();
