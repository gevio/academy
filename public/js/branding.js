/**
 * branding.js – Multi-Tenant Frontend-Branding-Layer
 *
 * Lädt die Event-Konfiguration von /api/branding.php (mit sessionStorage-Cache),
 * stellt window.AS_BRANDING bereit und wendet Branding auf DOM-Elemente an.
 *
 * data-Attribute die angewandt werden:
 *   data-branding-logo     → img.src = '/img/' + branding.logo
 *   data-branding-date     → el.textContent = branding.eventDate
 *   data-branding-event    → el.textContent = branding.eventName
 *   data-branding-ticket   → a.href = branding.ticketUrl
 *   data-branding-name     → el.innerHTML = '<parts[0]> <span>parts[1+]</span>'
 */
(function () {
    var CACHE_KEY = 'as_branding_v1';
    var CACHE_TTL = 300000; // 5 Minuten

    // Default-Werte (AS26) – gelten bis API-Antwort kommt
    var defaults = {
        name: 'AS26 Live',
        eventName: 'Adventure Southside 2026',
        eventShort: 'AS26',
        eventDate: '10.\u201312. Juli 2026 \u00b7 Messe Friedrichshafen',
        eventWebsite: 'https://adventuresouthside.com/',
        ticketUrl: 'https://adventuresouthside.com/',
        logo: 'logo-southside.png',
        shareUrl: 'https://agenda.adventuresouthside.com',
        shareText: 'Schau dir die Selbstausbauer Academy auf der Adventure Southside 2026 an! Workshops, Referenten & Standplan \u2013 alles in einer App:'
    };

    // Aus sessionStorage laden
    var branding = Object.assign({}, defaults);
    try {
        var raw = sessionStorage.getItem(CACHE_KEY);
        if (raw) {
            var cached = JSON.parse(raw);
            if (cached && cached._ts && (Date.now() - cached._ts) < CACHE_TTL) {
                branding = Object.assign({}, defaults, cached);
            }
        }
    } catch (e) {}

    function applyToDOM() {
        // Logo-Bilder
        document.querySelectorAll('[data-branding-logo]').forEach(function (el) {
            el.src = '/img/' + branding.logo;
        });
        // Event-Datum
        document.querySelectorAll('[data-branding-date]').forEach(function (el) {
            el.textContent = branding.eventDate;
        });
        // Event-Name
        document.querySelectorAll('[data-branding-event]').forEach(function (el) {
            el.textContent = branding.eventName;
        });
        // Ticket-Links
        document.querySelectorAll('[data-branding-ticket]').forEach(function (el) {
            el.href = branding.ticketUrl;
        });
        // Event-Kurzname + "-Assistent" (Chat-Widget)
        document.querySelectorAll('[data-branding-short-name]').forEach(function (el) {
            el.textContent = branding.eventShort + '-Assistent';
        });
        // App-Name (aufgeteilt in Text + <span> für Styling: "AS26 <span>Live</span>")
        document.querySelectorAll('[data-branding-name]').forEach(function (el) {
            var parts = branding.name.split(' ');
            if (parts.length >= 2) {
                el.innerHTML = parts[0] + ' <span>' + parts.slice(1).join(' ') + '</span>';
            } else {
                el.textContent = branding.name;
            }
        });
    }

    function share() {
        var d = {
            title: branding.name + ' \u2013 Dein Messe-Begleiter',
            text: branding.shareText,
            url: branding.shareUrl
        };
        if (navigator.share) {
            navigator.share(d).catch(function () {});
        } else {
            window.location.href = 'mailto:?subject=' + encodeURIComponent(d.title) +
                '&body=' + encodeURIComponent(d.text + '\n\n' + d.url);
        }
    }

    // window.AS_BRANDING sofort setzen (aus Cache oder Defaults)
    window.AS_BRANDING = Object.assign({}, branding, {
        applyToDOM: applyToDOM,
        share: share
    });

    // DOM anwenden
    if (document.readyState !== 'loading') {
        applyToDOM();
    } else {
        document.addEventListener('DOMContentLoaded', applyToDOM);
    }

    // Asynchron von API refreshen
    fetch('/api/branding.php')
        .then(function (r) { return r.json(); })
        .then(function (data) {
            data._ts = Date.now();
            try { sessionStorage.setItem(CACHE_KEY, JSON.stringify(data)); } catch (e) {}
            branding = Object.assign({}, defaults, data);
            window.AS_BRANDING = Object.assign({}, branding, {
                applyToDOM: applyToDOM,
                share: share
            });
            applyToDOM();
        })
        .catch(function () {});
})();
