// public/js/chat.js
// AS26 KI-Messe-Assistent – Chat UI

(function () {
  'use strict';

  // ── Konfiguration ──────────────────────────────────────────────────
  const API_URL        = '/api/chat.php';
  const STORAGE_KEY    = 'as26_chat';   // history + sessionId im localStorage
  const PROFILE_KEY    = 'as26_chat_profile';
  const MAX_HISTORY    = 20;            // max. Nachrichten im localStorage
  const GREETING       = 'Hallo! Ich bin dein AS26-Assistent. 👋\nWas interessiert dich auf der Adventure Southside? Erzähl mir etwas über dich und ich helfe dir, das passende Programm zu finden!';
  const QUICK_START    = ['Solar & Elektrik', 'Innenausbau', 'Heizung & Klima', 'Alle Themen zeigen'];

  // ── State ──────────────────────────────────────────────────────────
  let isOpen    = false;
  let isLoading = false;

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
    // Leere Werte nicht speichern
    if (!p.tage?.length)  delete p.tage;
    if (!p.fahrzeug)      delete p.fahrzeug;
    if (!p.level)         delete p.level;
    try { localStorage.setItem(PROFILE_KEY, JSON.stringify(p)); } catch {}
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

      /* ── Cards ── */
      .asc-cards { display: flex; flex-direction: column; gap: .4rem; margin-top: .4rem; }
      .asc-card {
        background: #fff; border: 1.5px solid var(--as-hellbeige, #D7D2CB);
        border-radius: 10px; padding: .6rem .8rem;
        text-decoration: none; color: inherit; display: block;
        transition: border-color .15s, box-shadow .15s;
      }
      .asc-card:hover { border-color: var(--as-rot, #CF3628); box-shadow: 0 2px 8px rgba(207,54,40,.12); }
      .asc-card-title { font-weight: 700; font-size: .88rem; color: var(--as-braun-dark, #372F2C); }
      .asc-card-meta { font-size: .78rem; color: var(--as-warmgrau, #6E6159); margin-top: .15rem; }
      .asc-card-tag {
        display: inline-block; font-size: .72rem; font-weight: 700;
        padding: .15rem .45rem; border-radius: 4px; margin-right: .3rem;
        background: var(--as-pfirsich, #FAC8B1); color: var(--as-braun, #652D23);
      }

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

      /* ── Typing Indicator ── */
      .asc-typing { display: flex; gap: 5px; align-items: center; padding: .5rem 0; }
      .asc-typing span {
        width: 8px; height: 8px; border-radius: 50%;
        background: var(--as-warmgrau, #6E6159); display: block;
        animation: asc-bounce .9s infinite;
      }
      .asc-typing span:nth-child(2) { animation-delay: .2s; }
      .asc-typing span:nth-child(3) { animation-delay: .4s; }
      @keyframes asc-bounce {
        0%,80%,100% { transform: translateY(0); opacity:.5; }
        40%         { transform: translateY(-6px); opacity:1; }
      }

      /* ── Input ── */
      .asc-input-area {
        padding: .75rem 1rem; border-top: 1px solid var(--as-hellbeige, #D7D2CB);
        display: flex; gap: .5rem; align-items: flex-end; flex-shrink: 0;
        background: #fff; border-radius: 0 0 0 0;
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

  // ── Nachricht rendern ──────────────────────────────────────────────
  function renderMessage(role, text, cards, quickReplies) {
    const msgs = document.getElementById('asc-messages');

    const bubble = document.createElement('div');
    bubble.className = `asc-bubble ${role}`;

    // Text (einfaches Whitespace-zu-<br>)
    const p = document.createElement('p');
    p.style.margin = '0';
    p.innerHTML = escapeHtml(text).replace(/\n/g, '<br>');
    bubble.appendChild(p);

    // Workshop/Aussteller/Experten-Cards
    if (cards && cards.length) {
      const cardWrap = document.createElement('div');
      cardWrap.className = 'asc-cards';
      cards.forEach(c => {
        const a = document.createElement('a');
        a.className = 'asc-card';
        a.href = c.url;
        a.innerHTML = `
          <div class="asc-card-title">${escapeHtml(c.title || c.firma || c.name || c.id)}</div>
          ${c.meta ? `<div class="asc-card-meta">${escapeHtml(c.meta)}</div>` : ''}
        `;
        cardWrap.appendChild(a);
      });
      bubble.appendChild(cardWrap);
    }

    // Quick Replies
    if (quickReplies && quickReplies.length) {
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

    msgs.appendChild(bubble);
    msgs.scrollTop = msgs.scrollHeight;
    return bubble;
  }

  function renderTyping() {
    const msgs = document.getElementById('asc-messages');
    const div = document.createElement('div');
    div.className = 'asc-bubble bot';
    div.innerHTML = '<div class="asc-typing"><span></span><span></span><span></span></div>';
    msgs.appendChild(div);
    msgs.scrollTop = msgs.scrollHeight;
    return div;
  }

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
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

      const data = await res.json();
      typingEl.remove();

      if (!res.ok || !data.ok) {
        renderMessage('bot', data.error || 'Es gab ein Problem. Bitte versuche es nochmal.');
        return;
      }

      // Profil aktualisieren wenn Bot neue Infos erkannt hat
      if (data.profile_update && Object.keys(data.profile_update).length) {
        saveProfile(data.profile_update);
      }

      // Session-ID merken
      const newState = {
        sessionId: data.sessionId || state.sessionId,
        history: [
          ...history,
          { role: 'user',      content: text           },
          { role: 'assistant', content: data.message   },
        ].slice(-MAX_HISTORY),
      };
      saveState(newState);

      // Daten für Cards aufbereiten
      // IDs mit Titeln aus den gecachten JSON-Daten anreichern (falls verfügbar)
      const wsCards  = buildCards(data.workshops,  'workshop');
      const ausCards = buildCards(data.aussteller, 'aussteller');
      const expCards = buildCards(data.experten,   'experte');
      const allCards = [...wsCards, ...ausCards, ...expCards];

      renderMessage('bot', data.message, allCards, data.quick_replies);

    } catch (err) {
      typingEl.remove();
      renderMessage('bot', 'Verbindungsproblem. Bitte überprüfe deine Internetverbindung.');
    } finally {
      isLoading = false;
      if (sendBtn) sendBtn.disabled = false;
      if (input) input.focus();
    }
  }

  // ── Cards mit Titeln anreichern ────────────────────────────────────
  // Liest aus den global gecachten JSON-Daten (falls im Window-Scope verfügbar)
  function buildCards(items, type) {
    if (!items || !items.length) return [];

    return items.map(item => {
      const id  = item.id  || item;
      const url = item.url || `/${type === 'workshop' ? 'programm' : type === 'aussteller' ? 'aussteller' : 'experte'}.html#${id}`;

      // Versuche Titel aus gecachten Daten zu holen
      let title = id, meta = '';

      if (type === 'workshop' && window._as26Workshops) {
        const w = window._as26Workshops.find(x => x.id === id);
        if (w) { title = w.title; meta = `${w.tag} · ${w.zeit} · ${w.ort}`; }
      }
      if (type === 'aussteller' && window._as26Aussteller) {
        const a = window._as26Aussteller.find(x => x.id === id);
        if (a) { title = a.firma; meta = `Stand ${a.stand}`; }
      }
      if (type === 'experte' && window._as26Experten) {
        const e = window._as26Experten.find(x => x.id === id);
        if (e) { title = e.name || e.vorname + ' ' + e.nachname; meta = e.funktion || ''; }
      }

      return { id, url, title, meta };
    });
  }

  // ── Begrüßung beim ersten Öffnen ───────────────────────────────────
  function showGreeting() {
    const msgs = document.getElementById('asc-messages');
    if (msgs && msgs.children.length === 0) {
      renderMessage('bot', GREETING, null, QUICK_START);
    }
  }

  // ── Textarea auto-resize ───────────────────────────────────────────
  function autoResize(el) {
    el.style.height = 'auto';
    el.style.height = Math.min(el.scrollHeight, 120) + 'px';
  }

  // ── Init ───────────────────────────────────────────────────────────
  function init() {
    const { btn, panel } = buildUI();

    const input   = document.getElementById('asc-input');
    const sendBtn = document.getElementById('asc-send-btn');
    const closeBtn = document.getElementById('asc-close-btn');

    // Panel öffnen/schließen
    btn.addEventListener('click', () => {
      isOpen = !isOpen;
      panel.classList.toggle('open', isOpen);
      btn.style.display = isOpen ? 'none' : '';
      document.getElementById('as-chat-badge').style.display = 'none';
      if (isOpen) {
        showGreeting();
        setTimeout(() => input?.focus(), 350);
      }
    });
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

    // Wenn von einer Seite mit ?frage=... aufgerufen wird (Deeplink)
    const urlParams = new URLSearchParams(location.search);
    const prefill = urlParams.get('frage');
    if (prefill) {
      isOpen = true;
      panel.classList.add('open');
      btn.style.display = 'none';
      showGreeting();
      setTimeout(() => sendMessage(decodeURIComponent(prefill)), 800);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Öffentliche API für Kontext-Links
  window.AS26Chat = {
    open:    (prefill) => {
      const btn = document.getElementById('as-chat-btn');
      if (btn) btn.click();
      if (prefill) setTimeout(() => sendMessage(prefill), 400);
    },
    prefill: (text) => {
      const input = document.getElementById('asc-input');
      if (input) { input.value = text; input.dispatchEvent(new Event('input')); }
    },
  };

})();
