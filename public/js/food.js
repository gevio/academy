/**
 * food.js – Food & Gastro-Seite
 *
 * Filtert Aussteller aus aussteller.json nach Kategorien
 * "Catering" / "Nahrungsmittel/Getränke" ODER gesetzten food_icons.
 * Zeigt Stand-Pin via ortmap.js (data-show-stand).
 */
(function () {
  'use strict';

  // Kategorien, die als "Food" gelten
  const FOOD_KATEGORIEN = ['Catering', 'Nahrungsmittel/Getränke', 'Getränke', 'Nahrungsmittel'];

  let allFood = [];
  let currentHalle = 'all';

  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function isFood(a) {
    if (a.food_icons && a.food_icons.length > 0) return true;
    return (a.kategorien || []).some(k => FOOD_KATEGORIEN.includes(k));
  }

  function getHallePrefix(stand) {
    if (!stand) return '';
    return stand.includes('-') ? stand.split('-')[0] : '';
  }

  function buildFoodIconsHtml(icons) {
    if (!icons || !icons.length) return '';
    return icons.map(function (name) {
      var safe = esc(name.toLowerCase().replace(/[^a-z0-9_-]/g, ''));
      return `<img class="food-icon-img" src="/img/food-icons/${safe}.png" alt="${esc(name)}" loading="lazy" onerror="this.style.display='none'" title="${esc(name)}">`;
    }).join('');
  }

  function renderCard(a) {
    // Logo
    var logoHtml;
    if (a.logo_local) {
      logoHtml = `<img class="food-card-logo" src="${esc(a.logo_local)}" alt="" loading="lazy" onerror="this.style.display='none'">`;
    } else {
      logoHtml = `<div class="food-card-letter">${esc((a.firma || '?')[0].toUpperCase())}</div>`;
    }

    var iconsHtml = a.food_icons && a.food_icons.length
      ? `<div class="food-card-icons">${buildFoodIconsHtml(a.food_icons)}</div>`
      : '';

    var specialHtml = a.messe_special
      ? `<div class="food-card-special">★ ${esc(a.messe_special.slice(0, 60) + (a.messe_special.length > 60 ? '…' : ''))}</div>`
      : '';

    var standBtn = a.stand
      ? `<button class="food-map-btn" data-show-stand="${esc(a.stand)}" data-firma="${esc(a.firma)}" onclick="event.preventDefault()">📍</button>`
      : '';

    return `<a href="/aussteller.html#id=${esc(a.id)}" class="food-card">
  ${logoHtml}
  <div class="food-card-body">
    <div class="food-card-firma">${esc(a.firma)}</div>
    ${a.stand ? `<div class="food-card-stand">Stand ${esc(a.stand)}</div>` : ''}
    ${iconsHtml}
    ${specialHtml}
  </div>
  ${standBtn}
</a>`;
  }

  function getFiltered() {
    if (currentHalle === 'all') return allFood;
    return allFood.filter(function (a) {
      return getHallePrefix(a.stand) === currentHalle;
    });
  }

  function renderList() {
    var container = document.getElementById('food-list');
    var filtered = getFiltered();
    if (!filtered.length) {
      container.innerHTML = '<div class="food-empty">Keine Anbieter für diesen Bereich gefunden.</div>';
      return;
    }
    container.innerHTML = filtered.map(renderCard).join('');
  }

  async function init() {
    try {
      var r = await fetch('/api/aussteller.json');
      if (!r.ok) throw new Error('Fetch failed');
      var data = await r.json();
      allFood = (data.aussteller || []).filter(isFood);

      if (!allFood.length) {
        document.getElementById('food-list').innerHTML =
          `<div class="food-empty">
            <p>Noch keine Food-Anbieter eingetragen.</p>
            <p style="margin-top:.5rem;font-size:.82rem">
              Food-Anbieter werden in Notion mit der Kategorie<br>
              <strong>„Catering"</strong> oder <strong>„Nahrungsmittel/Getränke"</strong> getaggt.
            </p>
            <a href="/aussteller.html" style="display:inline-block;margin-top:1rem;color:var(--as-rot);font-weight:700;text-decoration:none">
              → Alle Aussteller ansehen
            </a>
          </div>`;
        return;
      }

      renderList();

      // Hallen-Filter
      var filter = document.getElementById('food-halle-filter');
      if (filter) {
        filter.addEventListener('change', function () {
          currentHalle = filter.value;
          renderList();
        });
      }
    } catch (e) {
      document.getElementById('food-list').innerHTML =
        '<div class="food-empty">Daten konnten nicht geladen werden.</div>';
    }
  }

  document.addEventListener('DOMContentLoaded', init);
})();
