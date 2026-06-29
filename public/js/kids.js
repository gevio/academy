/**
 * kids.js – Adventure KIDS Programm
 *
 * Lädt /api/kids.json und rendert Attraktions-Karten.
 * Fallback-Inhalt wenn Notion-DB noch nicht befüllt ist.
 */
(function () {
  'use strict';

  // Fallback-Attraktionen bis Notion-DB befüllt ist
  var FALLBACK = [
    {
      name: 'Riesenhüpfburg Dschungelwald',
      beschreibung: 'Hüpfen, klettern und toben in unserem riesigen Dschungelwald – ein Spaß für die Kleinsten!',
      bereich: 'Freigelände West',
      highlight: false,
      icon: '🏰',
    },
    {
      name: 'BungeeRun – Wer kommt am weitesten?',
      beschreibung: 'Ziel beim Bungee-Run ist es, entgegen der immer stärker werdenden Seilspannung weiter als sein Gegner zu laufen und die Markierung zu platzieren. Hier zählt Feingefühl – ein Schritt zu viel und es geht im hohen Bogen zurück an den Start. Wer am weitesten kommt, gewinnt!',
      bereich: 'Freigelände West',
      highlight: true,
      icon: '🏆',
    },
    {
      name: 'Kettcar & Bobbycar-Parcours',
      beschreibung: 'Flitzer-Action auf dem eigens aufgebauten Parcours – für Groß und Klein.',
      bereich: 'Freigelände West',
      highlight: false,
      icon: '🚗',
    },
    {
      name: 'Tischkicker',
      beschreibung: 'Zeig dein Können am Kicker-Tisch – Einzel- oder Doppel-Matches sind erlaubt.',
      bereich: 'Freigelände West',
      highlight: false,
      icon: '⚽',
    },
    {
      name: 'Tischtennis',
      beschreibung: 'Mal eine Runde Ping-Pong gefällig? Die Schläger liegen bereit.',
      bereich: 'Freigelände West',
      highlight: false,
      icon: '🏓',
    },
    {
      name: 'Mini-Jeeps',
      beschreibung: 'Echte Geländewagen für echte Abenteurer – unsere Mini-Jeeps können im hinteren Freigelände West erkundet werden.',
      bereich: 'Freigelände West (hinten)',
      highlight: false,
      icon: '🚙',
    },
  ];

  function esc(s) {
    return String(s || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function renderCard(a) {
    var icon = a.icon || '🎡';
    var highlightClass = a.highlight ? ' highlight' : '';
    var bereichHtml = a.bereich
      ? `<span class="kids-card-bereich">${esc(a.bereich)}</span>`
      : '';
    return `<div class="kids-card${highlightClass}">
  <div class="kids-card-title">
    <span class="kids-card-icon">${esc(icon)}</span>
    ${esc(a.name)}
  </div>
  ${bereichHtml}
  <div class="kids-card-text">${esc(a.beschreibung)}</div>
</div>`;
  }

  async function init() {
    var container = document.getElementById('kids-content');
    try {
      var r = await fetch('/api/kids.json');
      if (!r.ok) throw new Error('Fetch failed');
      var data = await r.json();
      var list = data.attraktionen || [];

      if (!list.length) {
        // Notion-DB noch nicht befüllt – Fallback-Daten zeigen
        list = FALLBACK;
      }

      container.innerHTML = list.map(renderCard).join('');
    } catch (e) {
      // Bei Fetch-Fehler: Fallback-Daten zeigen
      container.innerHTML = FALLBACK.map(renderCard).join('');
    }
  }

  document.addEventListener('DOMContentLoaded', init);
})();
