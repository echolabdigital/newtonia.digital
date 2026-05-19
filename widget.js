/**
 * Newton IA — Widget de Chat Embeddable
 * Uso: <script src="https://newtonia.digital/widget.js?agent=SEU_TOKEN"></script>
 * Opcional: data-position="bottom-left" data-color="#ff0000" na tag script
 */
(function () {
  'use strict';

  const script  = document.currentScript || document.querySelector('script[src*="widget.js"]');
  const token   = new URL(script.src).searchParams.get('agent');
  const BASE    = new URL(script.src).origin;
  const API     = BASE + '/api/chat.php?agent=' + encodeURIComponent(token);

  if (!token) return console.error('[Newton IA] Token de agente não informado.');

  // Gera session ID persistente por aba
  const SESSION = sessionStorage.getItem('_nwt_sid') || Math.random().toString(36).slice(2);
  sessionStorage.setItem('_nwt_sid', SESSION);

  let agentName = 'Assistente';
  let color     = script.dataset.color || '#0ea5e9';
  let position  = script.dataset.position || 'bottom-right';
  let greeting  = 'Olá! Como posso ajudar?';
  let history   = [];
  let open      = false;

  // ── Injetar CSS ─────────────────────────────────────────────────────────────
  const style = document.createElement('style');
  style.textContent = `
    #nwt-wrap * { box-sizing: border-box; font-family: -apple-system, 'Segoe UI', sans-serif; }
    #nwt-wrap { position: fixed; z-index: 2147483647; ${position.includes('right') ? 'right:20px' : 'left:20px'}; bottom: 20px; display: flex; flex-direction: column; align-items: ${position.includes('right') ? 'flex-end' : 'flex-start'}; gap: 12px; }

    #nwt-btn { width: 56px; height: 56px; border-radius: 50%; background: var(--nwt-c); border: none; cursor: pointer; box-shadow: 0 4px 20px rgba(0,0,0,.18); display: flex; align-items: center; justify-content: center; transition: transform .2s, box-shadow .2s; flex-shrink: 0; }
    #nwt-btn:hover { transform: scale(1.08); box-shadow: 0 6px 28px rgba(0,0,0,.22); }
    #nwt-btn svg { width: 26px; height: 26px; color: #fff; }

    #nwt-box { width: 360px; max-width: calc(100vw - 32px); background: #fff; border-radius: 20px; box-shadow: 0 8px 48px rgba(0,0,0,.16); overflow: hidden; display: flex; flex-direction: column; opacity: 0; transform: translateY(16px) scale(.95); transition: opacity .25s, transform .25s; pointer-events: none; max-height: 560px; }
    #nwt-box.open { opacity: 1; transform: none; pointer-events: all; }

    #nwt-head { padding: 16px 18px; background: var(--nwt-c); display: flex; align-items: center; gap: 10px; }
    #nwt-head-av { width: 36px; height: 36px; border-radius: 10px; background: rgba(255,255,255,.2); display: flex; align-items: center; justify-content: center; }
    #nwt-head-av svg { width: 18px; height: 18px; color: #fff; }
    #nwt-head-name { flex: 1; font-size: 14px; font-weight: 700; color: #fff; }
    #nwt-head-sub { font-size: 11px; color: rgba(255,255,255,.75); margin-top: 1px; }
    #nwt-head-close { background: none; border: none; cursor: pointer; color: rgba(255,255,255,.8); padding: 4px; border-radius: 6px; display: flex; }
    #nwt-head-close:hover { color: #fff; background: rgba(255,255,255,.15); }

    #nwt-msgs { flex: 1; overflow-y: auto; padding: 16px; display: flex; flex-direction: column; gap: 10px; background: #f8fafc; }
    #nwt-msgs::-webkit-scrollbar { width: 4px; }
    #nwt-msgs::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 99px; }

    .nwt-msg { max-width: 82%; padding: 10px 14px; border-radius: 16px; font-size: 13.5px; line-height: 1.5; word-break: break-word; }
    .nwt-msg-out { align-self: flex-end; background: var(--nwt-c); color: #fff; border-radius: 16px 16px 4px 16px; }
    .nwt-msg-in  { align-self: flex-start; background: #fff; color: #18181b; border: 1px solid #e7e5e0; border-radius: 16px 16px 16px 4px; }
    .nwt-msg-typing { align-self: flex-start; padding: 10px 16px; background: #fff; border: 1px solid #e7e5e0; border-radius: 16px 16px 16px 4px; color: #94a3b8; font-size: 13px; font-style: italic; }

    #nwt-footer { padding: 12px 14px; background: #fff; border-top: 1px solid #f0f0f0; display: flex; gap: 8px; }
    #nwt-input { flex: 1; padding: 10px 14px; border: 1.5px solid #e7e5e0; border-radius: 12px; font-size: 13.5px; outline: none; resize: none; font-family: inherit; line-height: 1.4; max-height: 80px; transition: border-color .15s; }
    #nwt-input:focus { border-color: var(--nwt-c); }
    #nwt-send { width: 40px; height: 40px; border-radius: 10px; background: var(--nwt-c); border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; flex-shrink: 0; transition: background .15s; align-self: flex-end; }
    #nwt-send:hover { filter: brightness(1.1); }
    #nwt-send:disabled { opacity: .5; cursor: not-allowed; }
    #nwt-send svg { width: 18px; height: 18px; color: #fff; }

    #nwt-brand { text-align: center; padding: 6px; font-size: 10px; color: #c0bdb7; }
    #nwt-brand a { color: inherit; text-decoration: none; }
    #nwt-brand a:hover { color: #8b8a93; }
    @media(max-width:400px) { #nwt-box { width: calc(100vw - 24px); } }
  `;
  document.head.appendChild(style);

  // ── HTML do widget ───────────────────────────────────────────────────────────
  const wrap = document.createElement('div');
  wrap.id = 'nwt-wrap';
  wrap.innerHTML = `
    <div id="nwt-box">
      <div id="nwt-head">
        <div id="nwt-head-av">
          <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
        </div>
        <div>
          <div id="nwt-head-name">Carregando...</div>
          <div id="nwt-head-sub">Online agora</div>
        </div>
        <button id="nwt-head-close" aria-label="Fechar">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
        </button>
      </div>
      <div id="nwt-msgs"></div>
      <div id="nwt-footer">
        <textarea id="nwt-input" rows="1" placeholder="Digite sua mensagem..."></textarea>
        <button id="nwt-send" aria-label="Enviar">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
        </button>
      </div>
      <div id="nwt-brand">Powered by <a href="https://newtonia.digital" target="_blank">Newton IA</a></div>
    </div>
    <button id="nwt-btn" aria-label="Abrir chat">
      <svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>
    </button>
  `;
  document.body.appendChild(wrap);

  // Aplica cor
  function applyColor(c) {
    color = c;
    wrap.style.setProperty('--nwt-c', c);
  }
  applyColor(color);

  // ── Referências DOM ──────────────────────────────────────────────────────────
  const box      = wrap.querySelector('#nwt-box');
  const btn      = wrap.querySelector('#nwt-btn');
  const closeBtn = wrap.querySelector('#nwt-head-close');
  const msgs     = wrap.querySelector('#nwt-msgs');
  const input    = wrap.querySelector('#nwt-input');
  const send     = wrap.querySelector('#nwt-send');
  const headName = wrap.querySelector('#nwt-head-name');

  // ── Fetch info do agente ─────────────────────────────────────────────────────
  fetch(API).then(r => r.json()).then(d => {
    agentName = d.name || 'Assistente';
    greeting  = d.greeting || greeting;
    headName.textContent = agentName;
    if (d.color) applyColor(d.color);
    if (d.position) {
      position = d.position;
      wrap.style[position.includes('right') ? 'right' : 'left'] = '20px';
      wrap.style[position.includes('right') ? 'left' : 'right']  = '';
      wrap.style.alignItems = position.includes('right') ? 'flex-end' : 'flex-start';
    }
    addMessage(greeting, 'in');
  }).catch(() => {
    headName.textContent = 'Newton IA';
    addMessage('Olá! Como posso ajudar?', 'in');
  });

  // ── Toggle chat ──────────────────────────────────────────────────────────────
  btn.addEventListener('click', () => toggleChat());
  closeBtn.addEventListener('click', () => toggleChat(false));

  function toggleChat(force) {
    open = force !== undefined ? force : !open;
    box.classList.toggle('open', open);
    btn.innerHTML = open
      ? `<svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" width="24" height="24"><path d="M18 6L6 18M6 6l12 12"/></svg>`
      : `<svg fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" width="26" height="26"><path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/></svg>`;
    if (open) { input.focus(); scrollBottom(); }
  }

  // ── Mensagens ────────────────────────────────────────────────────────────────
  function addMessage(text, dir) {
    const div = document.createElement('div');
    div.className = 'nwt-msg nwt-msg-' + dir;
    div.textContent = text;
    msgs.appendChild(div);
    scrollBottom();
    return div;
  }

  function scrollBottom() { msgs.scrollTop = msgs.scrollHeight; }

  // ── Enviar ───────────────────────────────────────────────────────────────────
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMsg(); }
  });
  input.addEventListener('input', () => {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 80) + 'px';
  });
  send.addEventListener('click', sendMsg);

  async function sendMsg() {
    const text = input.value.trim();
    if (!text || send.disabled) return;
    input.value = '';
    input.style.height = 'auto';
    send.disabled = true;

    addMessage(text, 'out');
    history.push({ role: 'user', content: text });

    const typing = document.createElement('div');
    typing.className = 'nwt-msg-typing';
    typing.textContent = agentName + ' está digitando...';
    msgs.appendChild(typing);
    scrollBottom();

    try {
      const res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ message: text, history: history.slice(0, -1), session: SESSION }),
      });
      const data = await res.json();
      typing.remove();
      const reply = data.reply || 'Não foi possível processar sua mensagem.';
      addMessage(reply, 'in');
      history.push({ role: 'assistant', content: reply });
    } catch (e) {
      typing.remove();
      addMessage('Erro de conexão. Tente novamente.', 'in');
    }

    send.disabled = false;
    input.focus();
  }
})();
