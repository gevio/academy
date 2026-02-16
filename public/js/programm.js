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
  let currentKat = 'all';
  let currentTab = 'programm';
  let focusId = null;

  // ── URL State ──

  function readStateFromUrl() {
    const p = new URLSearchParams(location.search);
    currentDay = p.get('tag') || 'all';
    currentTyp = p.get('typ') || 'all';
    currentKat = p.get('kategorie') || 'all';
    focusId = p.get('focus') || null;
    if (p.get('tab') === 'favoriten') currentTab = 'favoriten';
  }

  function writeStateToUrl() {
    const p = new URLSearchParams();
    if (currentDay !== 'all') p.set('tag', currentDay);
    if (currentTyp !== 'all') p.set('typ', currentTyp);
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
    if (currentKat !== 'all') {
      list = list.filter(w => (w.kategorien || []).includes(currentKat));
    }
    return list;
  }

  function isFilterActive() {
    return currentDay !== 'all' || currentTyp !== 'all' || currentKat !== 'all';
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
    const person = ws.referent_person || '';
    const firma = ws.referent_firma || '';
    if (person && firma) return `${escapeHtml(firma)} – ${escapeHtml(person)}`;
    if (firma) return `N.N. (${escapeHtml(firma)})`;
    if (person) return escapeHtml(person);
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
            ${ws.ort ? `<span class="meta-item">📍 ${escapeHtml(ws.ort)}</span>` : ''}
            ${referentHtml}
          </div>
        </div>
        <button class="fav-btn ${isFav ? 'active' : ''}" data-id="${ws.id}" title="Favorit">
          ${isFav ? '❤️' : '🤍'}
        </button>
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
    initKatFilter();
    loadWorkshops();
  });
})();
