/**
 * special-banner.js – Zufälliger Messe-Special-Werbebanner
 *
 * Füllt alle .special-banner-slot-Elemente auf der Seite mit einem
 * zufälligen Aussteller-Special aus aussteller.json.
 *
 * API: window.AS26Banner.fill() – neu befüllen (z.B. nach renderList)
 */
(function () {
  'use strict';

  /** @type {Array|null} null = noch nicht geladen */
  let banners = null;

  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function buildHtml(a) {
    const text  = (a.messe_special || '').trim().replace(/\n/g, ' ');
    const short = text.length > 130 ? text.slice(0, 127) + '…' : text;

    const logo = a.logo_local
      ? `<img class="sb-logo" src="${esc(a.logo_local)}" alt="" loading="lazy" onerror="this.style.display='none'">`
      : `<span class="sb-logo-letter" aria-hidden="true">${esc((a.firma || '?')[0].toUpperCase())}</span>`;

    const standBtn = a.stand
      ? `<button class="sb-stand-btn" data-show-stand="${esc(a.stand)}" data-firma="${esc(a.firma)}">📍 Stand ${esc(a.stand)}</button>`
      : '';

    return `<div class="special-banner" role="complementary" aria-label="Messe-Special von ${esc(a.firma)}">
  <div class="sb-tag">★ Messe-Special</div>
  <div class="sb-body">
    <div class="sb-logo-wrap">${logo}</div>
    <div class="sb-content">
      <div class="sb-firma">${esc(a.firma)}</div>
      <div class="sb-text">${esc(short)}</div>
    </div>
  </div>
  <div class="sb-actions">
    ${standBtn}
    <a href="/aussteller.html#id=${esc(a.id)}" class="sb-profile-link">Zum Profil →</a>
  </div>
</div>`;
  }

  /** Füllt alle .special-banner-slot-Elemente auf der Seite. */
  function fill() {
    const slots = document.querySelectorAll('.special-banner-slot');
    if (!slots.length || !banners || !banners.length) return;
    slots.forEach(function (slot) {
      var a = banners[Math.floor(Math.random() * banners.length)];
      slot.innerHTML = buildHtml(a);
    });
  }

  async function init() {
    try {
      var r = await fetch('/api/aussteller.json');
      if (!r.ok) return;
      var data = await r.json();
      banners = (data.aussteller || []).filter(function (a) {
        return a.messe_special && a.messe_special.trim();
      });
      fill();
    } catch (e) { /* silent fail */ }
  }

  window.AS26Banner = { init: init, fill: fill };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
