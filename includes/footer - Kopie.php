<?php
// includes/footer.php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

$__csrf_token = csrf_token();
$__prefix     = site_prefix();
$__user_id    = (int)($_SESSION['user_id'] ?? 0);
?>
    </main>

    </div>
    
    <!-- gimi AI Global Assistant Sidebar (Co-Pilot) -->
    <div id="ai-copilot-container">
        <div class="ai-copilot-header">
           <h3>
             <span class="status-dot"></span>
             <strong>gimi</strong> <span style="opacity:0.6;font-weight:400;">| Co-Pilot</span>
           </h3>
           <button style="background:none; border:0; color:#fff; cursor:pointer; font-size:18px;" onclick="toggleGimi()">✕</button>
        </div>
        
        <div class="ai-messages" id="gimi-messages">
            <div class="ai-context-chip">📍 <?= h($PAGE_TITLE ?? 'Seiten-Kontext geladen') ?></div>
            <div class="ai-msg bot">Hallo! Ich bin gimi. Ich habe den Kontext dieser Seite analysiert. Wie kann ich dir hier helfen?</div>
        </div>
        
        <div class="ai-copilot-input">
            <div id="gimi-typing" class="ai-typing">gimi denkt nach...</div>
            <div class="ai-input-wrapper">
                <input type="text" id="gimi-input" placeholder="Frag gimi..." autocomplete="off">
                <button id="gimi-send" class="ai-send-btn">🚀</button>
            </div>
        </div>
    </div>
    
    <style>
    /* Ergänzungen für den Nav-Trigger */
    .gimi-glow-icon { font-size: 18px; filter: drop-shadow(0 0 8px #3b82f6); transition: 0.3s; }
    .gimi-trigger:hover .gimi-glow-icon { transform: scale(1.2); filter: drop-shadow(0 0 12px #3b82f6); }
    .gimi-nav-item { border-top: 1px solid rgba(255,255,255,0.05); margin-top: 10px !important; padding-top: 5px !important; }
    .gimi-nav-label { color: #3b82f6 !important; font-weight: 800 !important; }
    </style>

    <!-- Platzhalter-CSS + Widget-Styles -->
    <link rel="stylesheet" href="<?= h(asset_url('chat_widget.css')) ?>">

    <style>
    :root{
      --bg:#0b1220; --fg:#e5e7eb; --muted:#9ca3af; --line:#1f2937; --accent:#3b82f6;
      --chip:#0f1629; --chip-act:#122040; --chip-dot:#ef4444;
      --mine:#1f2a44; --other:#111827; --sys:#0b3d3a;
    }
    #chat-widget { position: fixed; bottom: 20px; right: 20px; font-family: system-ui,sans-serif; z-index: 9999; color:var(--fg); }
    #chat-toggle { background:#0ea5e9; color:#fff; padding:8px 12px; border-radius:50px; cursor:pointer; display:flex; align-items:center; gap:8px; box-shadow:0 4px 10px rgba(0,0,0,.2); user-select:none; outline:none; }
    #chat-arrow { font-size:12px; }
    .badge-dot{ width:8px; height:8px; border-radius:999px; background:var(--chip-dot); display:inline-block; }
    .badge-dot.pulse{ animation: pulse 1.1s ease-out 3; }
    @keyframes pulse{ 0%{ transform:scale(.7); opacity:.7 } 70%{ transform:scale(1.4); opacity:.2 } 100%{ transform:scale(1); opacity:0 } }

    #chat-window { display:none; flex-direction:column; width: 360px; max-height: 580px; background:var(--bg); border:2px solid var(--line); border-radius:12px; box-shadow:0 8px 24px rgba(0,0,0,.35); margin-top:8px; overflow:hidden; }
    #chat-header{ background:#0ea5e9; color:#fff; padding:10px; border-radius:10px 10px 0 0; font-weight:600; display:flex; align-items:center; justify-content:space-between; }
    .btn-unread{ background:transparent; color:#fff; border:1px solid rgba(255,255,255,.6); padding:6px 10px; border-radius:999px; font-size:12px; cursor:pointer; opacity:.9 }
    .btn-unread[disabled]{ opacity:.5; cursor:not-allowed }
    .btn-unread.active{ background:#fff; color:#0ea5e9; border-color:#fff; font-weight:700 }

    #chat-controls{ padding:8px; border-bottom:1px solid var(--line) }
    #chat-controls .row{ display:flex; gap:6px; flex-wrap:wrap; align-items:center }
    #chat-controls label{ font-size:12px; display:flex; gap:6px; align-items:center }
    #chat-controls select{ padding:4px 6px; border:1px solid #374151; border-radius:8px; background:var(--bg); color:var(--fg) }

    .rooms-bar{ margin-top:8px; display:flex; gap:6px; overflow:auto; scrollbar-width:thin; padding-bottom:2px }
    .chip{ background:var(--chip); border:1px solid var(--line); color:var(--fg); padding:6px 10px; font-size:12px; border-radius:999px; white-space:nowrap; display:flex; align-items:center; gap:8px; cursor:pointer; }
    .chip .dot{ width:10px; height:10px; border-radius:999px; background:var(--chip-dot); display:none; flex:0 0 auto; box-shadow:0 0 8px rgba(239,68,68,.7); }
    .chip.unread .dot{ display:inline-block; }
    .chip .jump{ font-size:11px; opacity:.8; border:1px dashed rgba(239,68,68,.6); padding:1px 6px; border-radius:999px; display:none; }
    .chip.unread .jump{ display:inline-block; }
    .chip.active{ background:var(--chip-act); border-color:#1d4ed8; }

    #chat-messages{ flex:1; padding:10px; overflow:auto; font-size:14px; background:#0a0f1c; outline:none }
    .msg{ margin:6px 0; padding:8px 10px; border-radius:10px; max-width:80% }
    .msg.mine{ background:var(--mine); color:var(--fg); margin-left:auto }
    .msg.other{ background:var(--other); color:var(--fg); margin-right:auto }
    .msg.system{ background:var(--sys); color:#fff; max-width:100% }
    .msg .meta{ font-size:12px; color:var(--muted); margin-bottom:2px }
    .msg.new{ box-shadow:0 0 0 2px rgba(59,130,246,.35); animation: flash .9s ease-out 1; }
    @keyframes flash{ from{ transform:translateY(2px); opacity:.7 } to{ transform:none; opacity:1 } }

    .divider{ text-align:center; color:#cbd5e1; font-size:12px; margin:8px 0; position:relative }
    .divider::before,.divider::after{ content:""; position:absolute; top:50%; width:38%; height:1px; background:var(--line) }
    .divider::before{ left:0 } .divider::after{ right:0 }

    #chat-input{ display:flex; border-top:1px solid var(--line); background:#0a0f1c }
    #chat-input input{ flex:1; border:0; padding:12px; font-size:14px; border-radius:0 0 0 12px; background:var(--bg); color:var(--fg) }
    #chat-input button{ border:0; background:var(--accent); color:#fff; padding:0 16px; cursor:pointer; border-radius:0 0 12px 0; font-weight:600 }
    </style>

    <!-- Widget-Logik -->
    <script src="<?= h(asset_url('js/chat_widget.js')) ?>" defer></script>

    <!-- gimi Global Logic -->
    <script>
      let gimiChatId = 0;
      let gimiLoaded = false;

      // URL-Normalisierung für punktgenaue Chat-Zuordnung
      function getCleanUrl() {
          const u = new URL(window.location.href);
          const params = new URLSearchParams();
          
          // Relevante IDs, die den Kontext definieren
          const keep = ['projekt_id', 'id', 'wohnung_id', 'objekt_id'];
          
          // Nur gefüllte und relevante Parameter übernehmen
          u.searchParams.forEach((val, key) => {
              if (keep.includes(key) && val !== '' && val !== '0') {
                  params.append(key, val);
              }
          });
          
          // Parameter sortieren für Konsistenz
          params.sort();
          
          // Pfad bereinigen (Subfolder-kompatibel)
          return u.origin + u.pathname + (params.toString() ? '?' + params.toString() : '');
      }

      function renderMessage(role, text) {
          const log = document.getElementById('gimi-messages');
          if (!log) return;
          const div = document.createElement('div');
          div.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot');
          div.innerHTML = text.replace(/\n/g, '<br>');
          log.appendChild(div);
          log.scrollTop = log.scrollHeight;
      }

      async function toggleGimi(forceOpen = null) {
          const panel = document.getElementById('ai-copilot-container');
          if (!panel) return;
          
          if (forceOpen === true) panel.classList.add('active');
          else if (forceOpen === false) panel.classList.remove('active');
          else panel.classList.toggle('active');

          const isActive = panel.classList.contains('active');
          localStorage.setItem('gimi-sidebar-active', isActive ? '1' : '0');

          if (isActive) {
              document.getElementById('gimi-input')?.focus();
              if (!gimiLoaded) loadGimiHistory();
          }
      }

      async function loadGimiHistory() {
          if (gimiLoaded) return;
          const urlKey = 'gimi_hist_' + btoa(getCleanUrl());
          const cached = sessionStorage.getItem(urlKey);
          
          if (cached) {
              const data = JSON.parse(cached);
              const log = document.getElementById('gimi-messages');
              if (log) {
                  log.innerHTML = '';
                  data.messages.forEach(m => renderMessage(m.role, m.content));
                  gimiChatId = data.chat_id;
                  gimiLoaded = true;
                  return;
              }
          }

          try {
              const res = await fetch('<?= site_prefix() ?>api/ai_query.php', {
                  method: 'POST',
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify({
                      prompt: 'GIMI_LOAD_CONTEXT',
                      context: { url: getCleanUrl() }
                  })
              });
              const data = await res.json();
              if (data.success && data.messages) {
                  const log = document.getElementById('gimi-messages');
                  if (log) log.innerHTML = ''; 
                  gimiChatId = data.chat_id;
                  if (data.messages.length > 0) {
                      data.messages.forEach(m => renderMessage(m.role, m.content));
                      sessionStorage.setItem(urlKey, JSON.stringify({messages: data.messages, chat_id: gimiChatId}));
                  } else {
                      renderMessage('bot', 'Hallo! Ich bin gimi. Ich habe den Kontext dieser Seite analysiert. Wie kann ich dir hier helfen?');
                  }
                  gimiLoaded = true;
              }
          } catch(err) { console.error('History Error:', err); }
      }

      // Restore state on load
      document.addEventListener('DOMContentLoaded', () => {
          if (localStorage.getItem('gimi-sidebar-active') === '1') {
              toggleGimi(true);
          }
      });

      async function handleGimiInput() {
          const input = document.getElementById('gimi-input');
          const log = document.getElementById('gimi-messages');
          const typing = document.getElementById('gimi-typing');
          const text = input.value.trim();
          if (!text) return;
          input.value = '';

          const uDiv = document.createElement('div');
          uDiv.className = 'ai-msg user';
          uDiv.textContent = text;
          log.appendChild(uDiv);
          log.scrollTop = log.scrollHeight;
          
          if (typing) typing.style.display = 'block';

          try {
              const res = await fetch('<?= site_prefix() ?>api/ai_query.php', {
                  method: 'POST',
                  headers: {'Content-Type': 'application/json'},
                  body: JSON.stringify({
                      prompt: text,
                      chat_id: gimiChatId,
                      context: {
                          url: getCleanUrl(),
                          title: document.title,
                          content: document.querySelector('main')?.innerText?.substring(0, 1500) || ''
                      }
                  })
              });
              const data = await res.json();
              if (typing) typing.style.display = 'none';

              if (data.success) {
                  gimiChatId = data.chat_id;
                  const gDiv = document.createElement('div');
                  gDiv.className = 'ai-msg bot';
                  gDiv.innerHTML = data.answer.replace(/\n/g, '<br>');
                  log.appendChild(gDiv);
                  log.scrollTop = log.scrollHeight;
              } else {
                  const eDiv = document.createElement('div');
                  eDiv.className = 'ai-msg bot';
                  eDiv.style.color = '#ef4444';
                  eDiv.textContent = 'Fehler: ' + data.error;
                  log.appendChild(eDiv);
              }
          } catch(err) {
              if (typing) typing.style.display = 'none';
              console.error(err);
          }
      }

      document.getElementById('gimi-send')?.addEventListener('click', handleGimiInput);
      document.getElementById('gimi-input')?.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleGimiInput(); });

      (function(){
        function init(){
          const toggle = document.getElementById('chat-toggle');
          const arrow  = document.getElementById('chat-arrow');
          const wnd    = document.getElementById('chat-window');
          if(!toggle || !arrow || !wnd) return;

          let open = false;
          function setOpen(v){
            open = !!v;
            wnd.style.display = open ? 'flex' : 'none';
            wnd.setAttribute('aria-hidden', open ? 'false' : 'true');
            arrow.textContent = open ? '▼' : '▲';
            if (open) { const msgs = document.getElementById('chat-messages'); msgs && msgs.focus(); }
          }
          toggle.addEventListener('click', () => setOpen(!open));
          toggle.addEventListener('keydown', (e)=>{
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setOpen(!open); }
          });
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
      })();
    </script>

  </body>
</html>
