// public/js/chat.js
// AS26 KI-Messe-Assistent – Chat UI

(function () {
  'use strict';

  // ── Konfiguration ──────────────────────────────────────────────────
  const API_URL     = '/api/chat.php';
  const STORAGE_KEY = 'as26_chat';      // history + sessionId (localStorage, für KI-Kontext)
  const PROFILE_KEY = 'as26_chat_profile';
  const MSG_KEY     = 'as26_chat_msgs'; // gerenderte Nachrichten (sessionStorage, Browser-Session)
  const FAV_KEY     = 'asa_favorites';  // Workshop-Favoriten (localStorage, geteilt mit programm.js)
  const MAX_HISTORY = 20;
  const GREETING    = 'Hey! Ich bin dein persönlicher Messe-Guide für die AS26. 👋\nSag mir, was dich interessiert – ich finde die passenden Veranstaltungen und Aussteller für dich!';

  // ── State ──────────────────────────────────────────────────────────
  let isOpen    = false;
  let isLoading = false;
  let _msgHistory = []; // In-Memory-Spiegel der sessionStorage-Nachrichten

  function loadState() {
    try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch { return {}; }
  }
  function saveState(state) {
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); } catch {}
  }
  function loadProfile() {
    try { return JSON.parse(localStorage.getItem(PROFILE_KEY) || '{}'); } catch { return {}; }
  }
  function saveProfile(update) {
    const p = { ...loadProfile(), ...update };
    if (!p.tage?.length)  delete p.tage;
    if (!p.fahrzeug)      delete p.fahrzeug;
    if (!p.level)         delete p.level;
    try { localStorage.setItem(PROFILE_KEY, JSON.stringify(p)); } catch {}
  }

  // Nachrichten für diese Browser-Session speichern (sessionStorage)
  function loadSessionMsgs() {
    try { return JSON.parse(sessionStorage.getItem(MSG_KEY) || '[]'); } catch { return []; }
  }
  function saveSessionMsgs() {
    try { sessionStorage.setItem(MSG_KEY, JSON.stringify(_msgHistory.slice(-30))); } catch {}
  }

  // ── Favoriten (geteilt mit programm.js via asa_favorites) ─────────
  function getFavorites() {
    try { return JSON.parse(localStorage.getItem(FAV_KEY) || '[]'); } catch { return []; }
  }
  function toggleFavorite(id) {
    const favs = getFavorites();
    const i = favs.indexOf(id);
    if (i >= 0) favs.splice(i, 1); else favs.push(id);
    try { localStorage.setItem(FAV_KEY, JSON.stringify(favs)); } catch {}
    return i < 0; // true = hinzugefügt, false = entfernt
  }
  function updateFavBtn() {
    const btn = document.getElementById('asc-fav-btn');
    if (!btn) return;
    const count = getFavorites().length;
    const span = document.getElementById('asc-fav-count');
    if (span) span.textContent = count;
    btn.classList.toggle('hidden', count === 0);
  }

  // ── JSON-Daten prefetchen (für Card-Anreicherung) ──────────────────
  // Promise wird global gespeichert – sendMessage awaitet ihn
  let _dataReadyPromise = null;

  function prefetchData() {
    _dataReadyPromise = Promise.all([
      fetch('/api/workshops.json').then(r => r.json()),
      fetch('/api/aussteller.json').then(r => r.json()),
      fetch('/api/experten.json').then(r => r.json()),
    ]).then(([ws, aus, exp]) => {
      window._as26Workshops  = ws.workshops   || [];
      window._as26Aussteller = aus.aussteller  || [];
      window._as26Experten   = exp.experten    || [];
    }).catch(() => {
      // silently fail – Cards zeigen dann nur IDs
    });
    return _dataReadyPromise;
  }

  // ── DOM aufbauen ───────────────────────────────────────────────────
  function buildUI() {
    const css = document.createElement('style');
    css.textContent = `
      /* ── Floating Button ── */
      #as-chat-btn {
        position: fixed; bottom: 1.5rem; right: 1.5rem; z-index: 9000;
        width: 56px; height: 56px; border-radius: 50%;
        background: var(--as-rot, #CF3628); color: #fff; border: none;
        box-shadow: 0 4px 16px rgba(207,54,40,.45);
        cursor: pointer; display: flex; align-items: center; justify-content: center;
        transition: transform .2s, box-shadow .2s;
      }
      #as-chat-btn:hover { transform: scale(1.08); box-shadow: 0 6px 20px rgba(207,54,40,.55); }
      #as-chat-btn svg { width: 26px; height: 26px; }
      #as-chat-badge {
        position: absolute; top: -3px; right: -3px;
        background: var(--as-orange, #F18864); color: #fff;
        border-radius: 50%; width: 18px; height: 18px;
        font-size: .7rem; font-weight: 700; display: none;
        align-items: center; justify-content: center;
      }

      /* ── Panel ── */
      #as-chat-panel {
        position: fixed; bottom: 0; right: 0; z-index: 8999;
        width: 100%; max-width: 420px; height: 85dvh; max-height: 680px;
        background: #fff; border-radius: 16px 16px 0 0;
        box-shadow: 0 -4px 32px rgba(0,0,0,.18);
        display: flex; flex-direction: column;
        transform: translateY(105%); transition: transform .3s cubic-bezier(.4,0,.2,1);
        font-family: 'PT Sans', sans-serif;
      }
      #as-chat-panel.open { transform: translateY(0); }

      /* ── Header ── */
      .asc-header {
        background: var(--as-braun-dark, #372F2C); color: #fff;
        padding: .9rem 1rem; border-radius: 16px 16px 0 0;
        display: flex; align-items: center; gap: .75rem; flex-shrink: 0;
      }
      .asc-header-avatar {
        width: 36px; height: 36px; border-radius: 50%;
        background: var(--as-rot, #CF3628);
        display: flex; align-items: center; justify-content: center; flex-shrink: 0;
      }
      .asc-header-avatar svg { width: 20px; height: 20px; color: #fff; }
      .asc-header-info { flex: 1; }
      .asc-header-title { font-weight: 700; font-size: 1rem; }
      .asc-header-sub { font-size: .78rem; opacity: .75; }
      .asc-fav-btn {
        background: rgba(255,255,255,.18); border: none; color: #fff;
        border-radius: 8px; padding: .3rem .6rem; font-size: .82rem;
        font-weight: 700; cursor: pointer; display: flex; align-items: center;
        gap: .2rem; transition: background .15s; white-space: nowrap; flex-shrink: 0;
      }
      .asc-fav-btn:hover { background: rgba(255,255,255,.3); }
      .asc-fav-btn.hidden { display: none; }
      .asc-header-close {
        background: none; border: none; color: #fff; cursor: pointer;
        padding: .25rem; border-radius: 6px; display: flex;
        opacity: .8; transition: opacity .15s;
      }
      .asc-header-close:hover { opacity: 1; }
      .asc-header-close svg { width: 20px; height: 20px; }

      /* ── Messages ── */
      .asc-messages {
        flex: 1; overflow-y: auto; padding: 1rem;
        display: flex; flex-direction: column; gap: .75rem;
        scroll-behavior: smooth;
      }

      /* ── Bubble ── */
      .asc-bubble {
        max-width: 88%; padding: .7rem .9rem; border-radius: 14px;
        font-size: .92rem; line-height: 1.5; word-break: break-word;
      }
      .asc-bubble.bot {
        background: var(--as-sand, #F5F0EB); color: #2a2522;
        border-radius: 4px 14px 14px 14px; align-self: flex-start;
      }
      .asc-bubble.user {
        background: var(--as-rot, #CF3628); color: #fff;
        border-radius: 14px 4px 14px 14px; align-self: flex-end;
      }
      .asc-bubble p { margin: 0; }
      .asc-bubble ul { margin: .3rem 0 .3rem 1.1rem; padding: 0; }
      .asc-bubble li { margin: .1rem 0; }
      .asc-bubble strong { font-weight: 700; }

      /* ── Cards ── */
      .asc-cards { display: flex; flex-direction: column; gap: .4rem; margin-top: .5rem; }
      .asc-section-label {
        font-size: .72rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .05em; color: var(--as-warmgrau, #6E6159);
        padding: .4rem 0 .1rem; margin-top: .1rem;
      }
      .asc-card-wrap { position: relative; }
      .asc-card {
        background: #fff; border: 1.5px solid var(--as-hellbeige, #D7D2CB);
        border-radius: 10px; padding: .6rem 2rem .6rem .8rem;
        text-decoration: none; color: inherit; display: block;
        transition: border-color .15s, box-shadow .15s; cursor: pointer;
      }
      .asc-card-wrap .asc-card { padding-right: 2.2rem; }
      .asc-card-plain {
        background: #fff; border: 1.5px solid var(--as-hellbeige, #D7D2CB);
        border-radius: 10px; padding: .6rem .8rem;
        text-decoration: none; color: inherit; display: block;
        transition: border-color .15s, box-shadow .15s; cursor: pointer;
      }
      .asc-card:hover, .asc-card-plain:hover {
        border-color: var(--as-rot, #CF3628); box-shadow: 0 2px 8px rgba(207,54,40,.12);
      }
      .asc-card-fav {
        position: absolute; top: .35rem; right: .35rem;
        background: none; border: none; cursor: pointer;
        font-size: 1rem; line-height: 1; padding: .2rem;
        opacity: .6; transition: opacity .15s, transform .15s;
      }
      .asc-card-fav:hover { opacity: 1; transform: scale(1.25); }
      .asc-card-fav.active { opacity: 1; }
      .asc-card-tag {
        display: inline-block; font-size: .7rem; font-weight: 700;
        padding: .1rem .4rem; border-radius: 4px; margin-bottom: .25rem;
        background: var(--as-pfirsich, #FAC8B1); color: var(--as-braun, #652D23);
      }
      .asc-card-title { font-weight: 700; font-size: .88rem; color: var(--as-braun-dark, #372F2C); }
      .asc-card-meta { font-size: .77rem; color: var(--as-warmgrau, #6E6159); margin-top: .15rem; }

      /* ── Quick Replies ── */
      .asc-quick-replies { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .5rem; }
      .asc-qr-btn {
        background: #fff; border: 1.5px solid var(--as-rot, #CF3628);
        color: var(--as-rot, #CF3628); border-radius: 20px;
        padding: .35rem .85rem; font-size: .83rem; font-weight: 700;
        cursor: pointer; font-family: inherit; transition: all .15s;
        white-space: nowrap;
      }
      .asc-qr-btn:hover { background: var(--as-rot, #CF3628); color: #fff; }

      /* ── Day Picker ── */
      .asc-day-picker { margin-top: .6rem; }
      .asc-day-checkboxes { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .5rem; }
      .asc-day-label {
        display: flex; align-items: center; gap: .35rem;
        background: #fff; border: 1.5px solid #d0c9c2; border-radius: 20px;
        padding: .35rem .85rem; font-size: .83rem; font-weight: 700;
        cursor: pointer; transition: all .15s; user-select: none;
      }
      .asc-day-label:has(input:checked) {
        border-color: var(--as-rot, #CF3628); background: #fdf1f0; color: var(--as-rot, #CF3628);
      }
      .asc-day-label input { display: none; }
      .asc-day-confirm {
        background: var(--as-rot, #CF3628); color: #fff; border: none;
        border-radius: 20px; padding: .4rem 1.1rem; font-size: .83rem;
        font-weight: 700; cursor: pointer; font-family: inherit; transition: opacity .15s;
      }
      .asc-day-confirm:disabled { opacity: .35; cursor: not-allowed; }

      /* ── Typing Indicator ── */
      .asc-typing { display: flex; flex-direction: column; gap: .35rem; padding: .3rem 0; }
      .asc-typing-dots { display: flex; gap: 5px; align-items: center; }
      .asc-typing-dots span {
        width: 7px; height: 7px; border-radius: 50%;
        background: var(--as-warmgrau, #6E6159); display: block;
        animation: asc-bounce .9s infinite;
      }
      .asc-typing-dots span:nth-child(2) { animation-delay: .2s; }
      .asc-typing-dots span:nth-child(3) { animation-delay: .4s; }
      .asc-typing-status {
        font-size: .76rem; color: var(--as-warmgrau, #6E6159);
        font-style: italic; min-height: 1em;
        animation: asc-fade-in .3s ease;
      }
      @keyframes asc-bounce {
        0%,80%,100% { transform: translateY(0); opacity:.5; }
        40%          { transform: translateY(-6px); opacity:1; }
      }
      @keyframes asc-fade-in {
        from { opacity: 0; } to { opacity: 1; }
      }

      /* ── Input ── */
      .asc-input-area {
        padding: .75rem 1rem; border-top: 1px solid var(--as-hellbeige, #D7D2CB);
        display: flex; gap: .5rem; align-items: flex-end; flex-shrink: 0;
        background: #fff;
      }
      .asc-textarea {
        flex: 1; border: 1.5px solid var(--as-beige, #BFB7AF); border-radius: 10px;
        padding: .55rem .75rem; font-family: inherit; font-size: .92rem;
        resize: none; min-height: 40px; max-height: 120px; outline: none;
        transition: border-color .15s; line-height: 1.4;
        color: var(--text, #2a2522); background: var(--as-sand, #F5F0EB);
      }
      .asc-textarea:focus { border-color: var(--as-rot, #CF3628); }
      .asc-send-btn {
        width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
        background: var(--as-rot, #CF3628); border: none; cursor: pointer;
        display: flex; align-items: center; justify-content: center;
        transition: background .15s; color: #fff;
      }
      .asc-send-btn:hover:not(:disabled) { background: var(--as-dunkelrot, #9E3323); }
      .asc-send-btn:disabled { background: var(--as-beige, #BFB7AF); cursor: not-allowed; }
      .asc-send-btn svg { width: 18px; height: 18px; }

      /* ── Mobile ── */
      @media (max-width: 440px) {
        #as-chat-panel { max-width: 100%; border-radius: 16px 16px 0 0; }
      }
    `;
    document.head.appendChild(css);

    // Floating Button
    const btn = document.createElement('button');
    btn.id = 'as-chat-btn';
    btn.setAttribute('aria-label', 'Messe-Assistent öffnen');
    btn.innerHTML = `
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      <span id="as-chat-badge"></span>
    `;
    document.body.appendChild(btn);

    // Panel
    const panel = document.createElement('div');
    panel.id = 'as-chat-panel';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-label', 'AS26 Messe-Assistent');
    panel.innerHTML = `
      <div class="asc-header">
        <div class="asc-header-avatar">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="10"/>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/>
            <line x1="12" y1="17" x2="12.01" y2="17"/>
          </svg>
        </div>
        <div class="asc-header-info">
          <div class="asc-header-title">AS26-Assistent</div>
          <div class="asc-header-sub">Adventure Southside 2026</div>
        </div>
        <button class="asc-fav-btn hidden" id="asc-fav-btn" aria-label="Mein Programm anzeigen">
          ❤️ <span id="asc-fav-count">0</span>
        </button>
        <button class="asc-header-close" id="asc-close-btn" aria-label="Schließen">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round">
            <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
          </svg>
        </button>
      </div>
      <div class="asc-messages" id="asc-messages"></div>
      <div class="asc-input-area">
        <textarea class="asc-textarea" id="asc-input" placeholder="Frag mich etwas…" rows="1" aria-label="Nachricht eingeben"></textarea>
        <button class="asc-send-btn" id="asc-send-btn" aria-label="Senden" disabled>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
          </svg>
        </button>
      </div>
    `;
    document.body.appendChild(panel);

    return { btn, panel };
  }

  // ── Einfaches Markdown-Rendering ──────────────────────────────────
  function renderMarkdown(text) {
    // 1. HTML escapen
    let s = String(text)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');

    // 2. Bold: **text**
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');

    // 3. Bullet-Listen: Zeilen mit "- " oder "• "
    const lines = s.split('\n');
    let inList = false;
    const out = [];
    for (const line of lines) {
      const m = line.match(/^[-•]\s+(.+)/);
      if (m) {
        if (!inList) { out.push('<ul>'); inList = true; }
        out.push(`<li>${m[1]}</li>`);
      } else {
        if (inList) { out.push('</ul>'); inList = false; }
        out.push(line);
      }
    }
    if (inList) out.push('</ul>');

    // 4. Zeilenumbrüche (außer vor/nach Block-Tags)
    return out.join('\n')
      .replace(/\n(?!<\/?[uo]l>|<li>)/g, '<br>')
      .replace(/<br>(?=<\/?[uo]l>|<li>)/g, '');
  }

  // ── Nachricht rendern ──────────────────────────────────────────────
  // persist=false beim Wiederherstellen aus sessionStorage
  function renderMessage(role, text, cards, quickReplies, persist) {
    if (persist === undefined) persist = true;
    const msgs = document.getElementById('asc-messages');

    const bubble = document.createElement('div');
    bubble.className = `asc-bubble ${role}`;

    const p = document.createElement('p');
    p.innerHTML = renderMarkdown(text);
    bubble.appendChild(p);

    // Cards – gruppiert nach Typ, mit Abschnittsüberschriften und Fav-Buttons
    if (cards && cards.length) {
      const cardWrap = document.createElement('div');
      cardWrap.className = 'asc-cards';

      // Typen ermitteln und Reihenfolge festlegen
      const ORDER = ['workshop', 'aussteller', 'experte'];
      const LABELS = { workshop: 'Veranstaltungen', aussteller: 'Aussteller', experte: 'Experten' };
      const groups = {};
      cards.forEach(c => {
        const t = c.type || 'workshop';
        if (!groups[t]) groups[t] = [];
        groups[t].push(c);
      });
      const activeTypes = ORDER.filter(t => groups[t]?.length);
      const showLabels  = activeTypes.length > 1;

      activeTypes.forEach(type => {
        if (showLabels) {
          const lbl = document.createElement('div');
          lbl.className = 'asc-section-label';
          lbl.textContent = LABELS[type];
          cardWrap.appendChild(lbl);
        }

        groups[type].forEach(c => {
          const isFav    = getFavorites().includes(c.id);
          const showFav  = type === 'workshop';

          // Wrapper für Fav-Button (nur bei Veranstaltungen)
          const container = showFav ? document.createElement('div') : null;
          if (container) container.className = 'asc-card-wrap';

          const a = document.createElement('a');
          a.className = showFav ? 'asc-card' : 'asc-card-plain';
          a.href = c.url;

          if (c.tag) {
            const tag = document.createElement('div');
            tag.className = 'asc-card-tag';
            tag.textContent = c.tag;
            a.appendChild(tag);
          }
          const title = document.createElement('div');
          title.className = 'asc-card-title';
          title.textContent = c.title;
          a.appendChild(title);
          if (c.meta) {
            const meta = document.createElement('div');
            meta.className = 'asc-card-meta';
            meta.textContent = c.meta;
            a.appendChild(meta);
          }

          if (showFav) {
            const favBtn = document.createElement('button');
            favBtn.className = 'asc-card-fav' + (isFav ? ' active' : '');
            favBtn.setAttribute('aria-label', isFav ? 'Aus Favoriten entfernen' : 'Zu Favoriten hinzufügen');
            favBtn.textContent = isFav ? '❤️' : '🤍';
            favBtn.addEventListener('click', e => {
              e.preventDefault();
              e.stopPropagation();
              const nowFav = toggleFavorite(c.id);
              favBtn.textContent = nowFav ? '❤️' : '🤍';
              favBtn.classList.toggle('active', nowFav);
              favBtn.setAttribute('aria-label', nowFav ? 'Aus Favoriten entfernen' : 'Zu Favoriten hinzufügen');
              updateFavBtn();
            });
            container.appendChild(a);
            container.appendChild(favBtn);
            cardWrap.appendChild(container);
          } else {
            cardWrap.appendChild(a);
          }
        });
      });

      bubble.appendChild(cardWrap);
    }

    // Quick Replies – bei Tagesauswahl als Checkboxen, sonst als Buttons
    if (quickReplies && quickReplies.length) {
      const DAYS = ['Freitag', 'Samstag', 'Sonntag'];
      const dayHits = quickReplies.filter(qr =>
        DAYS.some(d => qr.toLowerCase().includes(d.toLowerCase()))
      ).length;

      if (dayHits >= 2) {
        const dp = document.createElement('div');
        dp.className = 'asc-day-picker';
        const boxes = document.createElement('div');
        boxes.className = 'asc-day-checkboxes';
        DAYS.forEach(day => {
          const lbl = document.createElement('label');
          lbl.className = 'asc-day-label';
          const cb = document.createElement('input');
          cb.type = 'checkbox'; cb.value = day;
          lbl.appendChild(cb);
          lbl.appendChild(document.createTextNode(day));
          boxes.appendChild(lbl);
        });
        const confirm = document.createElement('button');
        confirm.className = 'asc-day-confirm';
        confirm.textContent = 'Bestätigen';
        confirm.disabled = true;
        boxes.addEventListener('change', () => {
          confirm.disabled = !boxes.querySelector('input:checked');
        });
        confirm.addEventListener('click', () => {
          const sel = [...boxes.querySelectorAll('input:checked')].map(c => c.value);
          dp.remove();
          const msg = sel.length === 3
            ? 'Ich komme alle drei Tage (Freitag, Samstag und Sonntag).'
            : 'Ich komme am ' + sel.join(' und ') + '.';
          sendMessage(msg);
        });
        dp.appendChild(boxes);
        dp.appendChild(confirm);
        bubble.appendChild(dp);
      } else {
        const qrWrap = document.createElement('div');
        qrWrap.className = 'asc-quick-replies';
        quickReplies.forEach(label => {
          const b = document.createElement('button');
          b.className = 'asc-qr-btn';
          b.textContent = label;
          b.addEventListener('click', () => {
            qrWrap.remove();
            sendMessage(label);
          });
          qrWrap.appendChild(b);
        });
        bubble.appendChild(qrWrap);
      }
    }

    msgs.appendChild(bubble);
    msgs.scrollTop = msgs.scrollHeight;

    // In Session-History speichern (ohne quickReplies – die sind einmalige Aktionen)
    if (persist) {
      _msgHistory.push({ role, text, cards: cards || null });
      saveSessionMsgs();
    }

    return bubble;
  }

  const TYPING_MSGS = [
    'Suche passende Veranstaltungen…',
    'Analysiere das Programm…',
    'Finde passende Aussteller…',
    'Schaue nach Experten…',
    'Fast fertig…',
  ];

  function renderTyping() {
    const msgs = document.getElementById('asc-messages');
    const div = document.createElement('div');
    div.className = 'asc-bubble bot';
    div.innerHTML = `
      <div class="asc-typing">
        <div class="asc-typing-dots"><span></span><span></span><span></span></div>
        <div class="asc-typing-status">${TYPING_MSGS[0]}</div>
      </div>`;
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;

    // Nachrichten rotieren
    let i = 1;
    const statusEl = div.querySelector('.asc-typing-status');
    const interval = setInterval(() => {
      if (!div.isConnected) { clearInterval(interval); return; }
      statusEl.style.animation = 'none';
      statusEl.offsetHeight; // reflow für Animation-Reset
      statusEl.style.animation = '';
      statusEl.textContent = TYPING_MSGS[Math.min(i, TYPING_MSGS.length - 1)];
      i++;
    }, 2500);

    div._stopTyping = () => clearInterval(interval);
    return div;
  }

  // ── Mein Programm anzeigen ────────────────────────────────────────
  function showMyProgram() {
    const favIds = getFavorites();
    if (!favIds.length) {
      renderMessage('bot', 'Du hast noch keine Favoriten gespeichert.\nKlick auf das 🤍 bei einer Veranstaltung!', null, null, false);
      return;
    }
    // Nur Workshops (programm.js speichert nur Workshop-IDs)
    const all = window._as26Workshops || [];
    const ids  = favIds.filter(id => all.some(w => w.id === id));
    if (!ids.length) {
      renderMessage('bot', 'Deine gespeicherten Favoriten konnten nicht geladen werden.', null, null, false);
      return;
    }
    const favCards = buildCards(ids, 'workshop');
    renderMessage('bot', `**Dein Programm** (${favCards.length} Veranstaltung${favCards.length !== 1 ? 'en' : ''})`, favCards, null, false);
  }

  // ── Nachricht senden ───────────────────────────────────────────────
  async function sendMessage(text) {
    if (isLoading || !text.trim()) return;

    const input   = document.getElementById('asc-input');
    const sendBtn = document.getElementById('asc-send-btn');

    text = text.trim();
    if (input) { input.value = ''; input.style.height = 'auto'; }
    if (sendBtn) sendBtn.disabled = true;

    renderMessage('user', text);

    const state   = loadState();
    const profile = loadProfile();
    const history = (state.history || []).slice(-(MAX_HISTORY - 2));

    isLoading = true;
    const typingEl = renderTyping();

    try {
      const res = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          message:   text,
          sessionId: state.sessionId || '',
          history,
          profile,
        }),
      });

      const [data] = await Promise.all([res.json(), _dataReadyPromise]);
      typingEl._stopTyping?.();
      typingEl.remove();

      if (!res.ok || !data.ok) {
        renderMessage('bot', data.error || 'Es gab ein Problem. Bitte versuche es nochmal.');
        return;
      }

      // Profil aktualisieren
      if (data.profile_update && Object.keys(data.profile_update).length) {
        saveProfile(data.profile_update);
      }

      // KI-Kontext in localStorage
      saveState({
        sessionId: data.sessionId || state.sessionId,
        history: [
          ...history,
          { role: 'user',      content: text         },
          { role: 'assistant', content: data.message },
        ].slice(-MAX_HISTORY),
      });

      // Cards anreichern
      const wsCards  = buildCards(data.workshops,  'workshop');
      const ausCards = buildCards(data.aussteller, 'aussteller');
      const expCards = buildCards(data.experten,   'experte');
      const allCards = [...wsCards, ...ausCards, ...expCards];

      renderMessage('bot', data.message, allCards, data.quick_replies);

    } catch (_) {
      typingEl._stopTyping?.();
      typingEl.remove();
      renderMessage('bot', 'Verbindungsproblem. Bitte überprüfe deine Internetverbindung.');
    } finally {
      isLoading = false;
      if (sendBtn) sendBtn.disabled = false;
      if (input) input.focus();
    }
  }

  // ── Cards mit Titeln anreichern ────────────────────────────────────
  function buildCards(items, type) {
    if (!items || !items.length) return [];
    return items.map(item => {
      const id  = item.id  || item;
      // programm.html nutzt ?focus=id (nicht #hash)
      const url = type === 'workshop'
        ? `/programm.html?focus=${id}`
        : type === 'aussteller' ? `/aussteller.html#${id}` : `/experte.html#${id}`;

      let title = '', meta = '', tag = '';

      if (type === 'workshop' && window._as26Workshops) {
        const w = window._as26Workshops.find(x => x.id === id);
        if (w) {
          title = w.title;
          meta  = [w.tag, w.zeit, w.ort].filter(Boolean).join(' · ');
          tag   = w.typ || '';
        }
      }
      if (type === 'aussteller' && window._as26Aussteller) {
        const a = window._as26Aussteller.find(x => x.id === id);
        if (a) { title = a.firma; meta = a.stand ? `Stand ${a.stand}` : ''; tag = 'Aussteller'; }
      }
      if (type === 'experte' && window._as26Experten) {
        const e = window._as26Experten.find(x => x.id === id);
        if (e) {
          title = e.name || [e.vorname, e.nachname].filter(Boolean).join(' ');
          meta  = e.funktion || '';
          tag   = 'Experte';
        }
      }

      // Fallback: ID als Titel wenn Daten noch nicht geladen
      if (!title) title = id;

      return { id, url, title, meta, tag, type };
    });
  }

  // ── Panel öffnen (mit History-Restore) ────────────────────────────
  function openPanel(input) {
    isOpen = true;
    const panel = document.getElementById('as-chat-panel');
    const btn   = document.getElementById('as-chat-btn');
    panel.classList.add('open');
    btn.style.display = 'none';
    document.getElementById('as-chat-badge').style.display = 'none';

    // Fav-Button aktualisieren (Favoriten können auf programm.html geändert worden sein)
    updateFavBtn();

    const msgs = document.getElementById('asc-messages');
    if (msgs.children.length === 0) {
      // Vorherige Nachrichten aus sessionStorage wiederherstellen
      const saved = loadSessionMsgs();
      if (saved.length > 0) {
        _msgHistory = saved;
        saved.forEach(m => renderMessage(m.role, m.text, m.cards || null, null, false));
      } else {
        // Begrüßung (kein Quick-Start)
        renderMessage('bot', GREETING);
      }
    }

    setTimeout(() => input?.focus(), 350);
  }

  // ── Textarea auto-resize ───────────────────────────────────────────
  function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  }

  // ── Init ───────────────────────────────────────────────────────────
  function init() {
    const { btn, panel } = buildUI();

    const input    = document.getElementById('asc-input');
    const sendBtn  = document.getElementById('asc-send-btn');
    const closeBtn = document.getElementById('asc-close-btn');
    const favBtn   = document.getElementById('asc-fav-btn');

    // Daten im Hintergrund laden (sofort, nicht erst beim Öffnen)
    prefetchData();

    // Fav-Button initialisieren
    updateFavBtn();
    if (favBtn) favBtn.addEventListener('click', showMyProgram);

    // Panel öffnen/schließen
    btn.addEventListener('click', () => openPanel(input));
    closeBtn.addEventListener('click', () => {
      isOpen = false;
      panel.classList.remove('open');
      btn.style.display = '';
    });

    // Input
    input.addEventListener('input', () => {
      autoResize(input);
      sendBtn.disabled = !input.value.trim() || isLoading;
    });
    input.addEventListener('keydown', e => {
      if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        if (!sendBtn.disabled) sendMessage(input.value);
      }
    });
    sendBtn.addEventListener('click', () => sendMessage(input.value));

    // Deeplink: ?frage=...
    const prefill = new URLSearchParams(location.search).get('frage');
    if (prefill) {
      openPanel(input);
      setTimeout(() => sendMessage(decodeURIComponent(prefill)), 800);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Öffentliche API
  window.AS26Chat = {
    open: (prefill) => {
      openPanel(document.getElementById('asc-input'));
      if (prefill) setTimeout(() => sendMessage(prefill), 400);
    },
    prefill: (text) => {
      const input = document.getElementById('asc-input');
      if (input) { input.value = text; input.dispatchEvent(new Event('input')); }
    },
  };

})();
