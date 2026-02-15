/**
 * programm.js – Programmübersicht, Filter, Favoriten
 * Lädt /api/workshops.json (vom Service Worker gecacht) und rendert client-seitig.
 */
(function () {
  'use strict';

  const STORAGE_KEY = 'as26_favorites';
  const API_URL = '/api/workshops.json';

  let allWorkshops = [];
  let currentDay = 'all';
  let currentTyp = 'all';
  let currentTab = 'programm';

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
      renderList();
    } catch (err) {
      console.error('Workshops laden fehlgeschlagen:', err);
      document.getElementById('workshop-list').innerHTML =
        '<div class="empty">Programm konnte nicht geladen werden.<br>Bitte prüfe deine Internetverbindung.</div>';
    }
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

  // ── Filter ──

  function getFilteredWorkshops() {
    let list = allWorkshops;
    if (currentDay !== 'all') {
      list = list.filter(w => w.tag === currentDay);
    }
    if (currentTyp !== 'all') {
      list = list.filter(w => w.typ === currentTyp);
    }
    return list;
  }

  // ── Render ──

  function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
  }

  function renderCard(ws) {
    const favs = getFavorites();
    const isFav = favs.includes(ws.id);
    const desc = ws.beschreibung
      ? `<div class="prog-card-desc">${escapeHtml(ws.beschreibung)}</div>`
      : '';

    return `
      <div class="prog-card" data-id="${ws.id}">
        <div class="prog-card-body">
          <span class="typ-badge">${escapeHtml(ws.typ)}</span>
          <a href="/w/${ws.id}" class="prog-card-title">${escapeHtml(ws.title)}</a>
          <div class="meta-row">
            ${ws.zeit ? `<span class="meta-item">🕐 ${escapeHtml(ws.zeit)}</span>` : ''}
            ${ws.ort ? `<span class="meta-item">📍 ${escapeHtml(ws.ort)}</span>` : ''}
          </div>
          ${desc}
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
      if (filtered.length === 0) {
        container.innerHTML = '<div class="empty">Keine Workshops für diesen Filter gefunden.</div>';
        return;
      }

      // Nach Tag gruppieren
      let html = `<div class="result-count">${filtered.length} Workshop${filtered.length !== 1 ? 's' : ''}</div>`;
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
      renderList();
    });
  }

  function initTypFilter() {
    document.getElementById('typ-filter').addEventListener('change', (e) => {
      currentTyp = e.target.value;
      renderList();
    });
  }

  // ── Init ──

  document.addEventListener('DOMContentLoaded', () => {
    updateFavCount();
    initTabs();
    initDayFilters();
    initTypFilter();
    loadWorkshops();
  });
})();
