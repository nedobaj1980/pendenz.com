<?php
// C:\xampp\htdocs\pendenz.com\pages\chat.php
// Vollständige Seite mit: Raumliste, Chat, „Neue Unterhaltung“-Modal (DM/Projekt/Team/Firma),
// listbasierter Auswahl, CSRF und "Raum löschen".

if (session_status() === PHP_SESSION_NONE) session_start();

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/authz.php';
require_once __DIR__ . '/../includes/audit.php';
require_login();

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';

$PREFIX    = site_prefix(); // z. B. "/pendenz.com/"
$userId    = (int)($_SESSION['user_id'] ?? 0);
$projectId = isset($_GET['projekt_id']) ? (int)$_GET['projekt_id'] : 0;
$roomId    = isset($_GET['room_id'])    ? (int)$_GET['room_id']    : 0;

// Hilfsfunktion: Projekt-Raum sicherstellen/erzeugen
function ensure_project_room(mysqli $db, int $projectId, int $creatorUserId): ?array {
  if ($projectId <= 0) return null;

  $stmt = $db->prepare("SELECT * FROM chat_rooms WHERE room_type='project' AND project_id=? LIMIT 1");
  $stmt->bind_param("i", $projectId);
  $stmt->execute();
  $room = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($room) return $room;

  $projName = "Projekt #".$projectId;
  if ($ps = $db->prepare("SELECT name FROM projekte WHERE id=?")) {
    $ps->bind_param("i", $projectId);
    $ps->execute();
    if ($r = $ps->get_result()->fetch_assoc()) $projName = $r['name'];
    $ps->close();
  }

  $stmt = $db->prepare("INSERT INTO chat_rooms (room_type, project_id, name, created_by) VALUES ('project', ?, ?, ?)");
  $stmt->bind_param("isi", $projectId, $projName, $creatorUserId);
  $stmt->execute();
  $rid = $db->insert_id;
  $stmt->close();

  $stmt = $db->prepare("INSERT IGNORE INTO chat_members (room_id, user_id, role) VALUES (?,?, 'admin')");
  $stmt->bind_param("ii", $rid, $creatorUserId);
  $stmt->execute();
  $stmt->close();

  $stmt = $db->prepare("SELECT * FROM chat_rooms WHERE id=?");
  $stmt->bind_param("i", $rid);
  $stmt->execute();
  $room = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $room;
}

// Aktuellen Raum bestimmen
$currentRoom = null;
if ($roomId > 0) {
  $stmt = $mysqli->prepare("SELECT r.* FROM chat_rooms r
    JOIN chat_members m ON m.room_id=r.id AND m.user_id=?
    WHERE r.id=? LIMIT 1");
  $stmt->bind_param("ii", $userId, $roomId);
  $stmt->execute();
  $currentRoom = $stmt->get_result()->fetch_assoc();
  $stmt->close();
} elseif ($projectId > 0) {
  $currentRoom = ensure_project_room($mysqli, $projectId, $userId);
  if ($currentRoom) {
    $stmt = $mysqli->prepare("INSERT IGNORE INTO chat_members (room_id, user_id, role) VALUES (?, ?, 'member')");
    $stmt->bind_param("ii", $currentRoom['id'], $userId);
    $stmt->execute();
    $stmt->close();
  }
}
if (!$currentRoom) {
  $stmt = $mysqli->prepare("SELECT r.*
    FROM chat_rooms r
    JOIN chat_members m ON m.room_id=r.id AND m.user_id=?
    ORDER BY CASE r.room_type WHEN 'project' THEN 0 WHEN 'team' THEN 1 ELSE 2 END,
             r.updated_at DESC, r.id DESC
    LIMIT 1");
  $stmt->bind_param("i", $userId);
  $stmt->execute();
  $currentRoom = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

// Räume für Sidebar
$stmt = $mysqli->prepare("SELECT r.id, r.name, r.room_type, r.project_id
  FROM chat_rooms r
  JOIN chat_members m ON m.room_id=r.id AND m.user_id=?
  ORDER BY r.updated_at DESC, r.id DESC");
$stmt->bind_param("i", $userId);
$stmt->execute();
$userRooms = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$activeRoomId = (int)($currentRoom['id'] ?? 0);

// Layout-Header / Navigation
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>
<style>
:root{
  --card-bg:#0b1220; --card-fg:#e5e7eb; --muted:#9ca3af; --accent:#3b82f6;
  --bubble-mine:#1f2a44; --bubble-other:#111827;
}
.container { color: var(--card-fg); }
.chat-wrap{display:grid;grid-template-columns:300px 1fr;gap:12px;align-items:stretch}
.chat-rooms{background:var(--card-bg);border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,.35);padding:12px}
.chat-rooms h3{margin:0 0 10px 0;color:#cbd5e1;display:flex;align-items:center;justify-content:space-between}
#btn-new-chat{background:#10b981;border:0;color:#062814;padding:8px 12px;border-radius:10px;font-weight:700;cursor:pointer}
.chat-room-link{display:flex;justify-content:space-between;gap:8px;padding:10px;border-radius:10px;text-decoration:none;color:#e5e7eb;transition:.15s}
.chat-room-link:hover{background:#0f1629}
.chat-room-link.active{background:#122040}
.chat-rooms .badge{background:#0f172a;color:#93c5fd;border:1px solid #1d4ed8;padding:2px 8px;border-radius:999px;font-size:12px;margin-left:6px}
.chat-main{display:flex;flex-direction:column;background:var(--card-bg);border-radius:14px;box-shadow:0 8px 24px rgba(0,0,0,.35);overflow:hidden}
.chat-head{padding:12px 14px;border-bottom:1px solid #1f2937;display:flex;align-items:center;justify-content:space-between;gap:8px}
.chat-head strong{font-weight:600}
.chat-head .head-actions{display:flex;align-items:center;gap:8px}
.chat-head .danger{background:#dc2626}
.chat-log{flex:1;overflow:auto;padding:16px;background:#0a0f1c}
.msg{margin-bottom:12px;max-width:min(78%,720px)}
.msg .meta{font-size:12px;color:var(--muted);margin-bottom:4px}
.msg.mine{background:var(--bubble-mine);border-radius:12px;padding:10px 12px;margin-left:auto}
.msg.other{background:var(--bubble-other);border-radius:12px;padding:10px 12px;margin-right:auto}
.msg.system{background:#0b3d3a;color:#fff;border-radius:12px;padding:10px 12px;margin:8px 0;max-width:100%}
.chat-input{border-top:1px solid #1f2937;padding:12px;display:flex;gap:10px;background:#0a0f1c}
.chat-input textarea{flex:1;min-height:44px;max-height:140px;padding:10px;border:1px solid #1f2937;border-radius:10px;resize:vertical;background:#0b1220;color:#e5e7eb}
.btn{padding:10px 14px;border:0;border-radius:10px;background:var(--accent);color:#fff;cursor:pointer;font-weight:600}
.btn.secondary{background:#374151}
.btn.danger{background:#dc2626}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;background:#eef2ff;color:#1e40af;font-size:12px}

/* Modal */
#new-chat-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9998}
#new-chat-modal .panel{background:#0b1220;color:#e5e7eb;width:min(680px,90vw);margin:6vh auto;border-radius:14px;box-shadow:0 10px 28px rgba(0,0,0,.45);padding:16px}
.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.modal-close{background:transparent;border:0;color:#e5e7eb;font-size:20px;cursor:pointer}
.row{margin-bottom:10px}
.row label{display:block;font-size:13px;color:#9ca3af;margin-bottom:4px}
.row input, .row select{width:100%;padding:10px;border-radius:10px;border:1px solid #1f2937;background:#0a0f1c;color:#e5e7eb}
.modal-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
.modal-actions .btn-secondary{background:#374151}
</style>

<div class="container">
  <header class="hero" style="display:flex;justify-content:space-between;align-items:center;">
    <h1 style="margin:0;">Chat</h1>
    <?php if ($projectId>0): ?><span class="badge">Projekt #<?= (int)$projectId ?></span><?php endif; ?>
  </header>

  <div class="chat-wrap" id="chat-app"
       data-prefix="<?= h($PREFIX) ?>"
       data-user-id="<?= (int)$userId ?>"
       data-csrf="<?= h(csrf_token()) ?>"
       data-room-id="<?= (int)$activeRoomId ?>">
    <!-- Sidebar: Räume -->
    <aside class="chat-rooms" id="chat-sidebar">
      <h3>
        <span>Meine Räume</span>
        <button id="btn-new-chat" type="button">+ Neue Unterhaltung</button>
      </h3>
      <?php foreach ($userRooms as $r):
        $href   = 'chat.php?room_id='.(int)$r['id'].($r['project_id']? '&projekt_id='.(int)$r['project_id'] : '');
        $active = ((int)$r['id'] === $activeRoomId) ? 'active' : '';
        $label  = htmlspecialchars($r['name'] ?: ($r['room_type'].' #'.$r['id']));
      ?>
        <a class="chat-room-link <?= $active ?>"
           href="<?= htmlspecialchars($href) ?>"
           data-room-id="<?= (int)$r['id'] ?>">
          <span><?= $label ?></span>
          <?php if ($r['room_type']==='project' && $r['project_id']): ?>
            <span class="badge">Projekt</span>
          <?php elseif ($r['room_type']==='team'): ?>
            <span class="badge">Team</span>
          <?php elseif ($r['room_type']==='dm'): ?>
            <span class="badge">DM</span>
          <?php elseif ($r['room_type']==='company'): ?>
            <span class="badge">Firma</span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </aside>

    <!-- Hauptbereich: Chat -->
    <section class="chat-main">
      <div class="chat-head">
        <div><strong id="chat-room-title"><?= htmlspecialchars($currentRoom['name'] ?? 'Kein Raum') ?></strong></div>
        <div class="head-actions">
          <button id="btn-room-delete" class="btn danger" type="button" <?php if(!$activeRoomId) echo 'disabled'; ?>>Raum löschen</button>
          <?= csrf_input() ?>
        </div>
      </div>
      <div class="chat-log" id="chat-log"></div>
      <form class="chat-input" id="chat-form" autocomplete="off">
        <textarea name="message" id="chat-message" placeholder="Nachricht schreiben…" required></textarea>
        <button class="btn" type="submit">Senden</button>
      </form>
    </section>
  </div>
</div>

<!-- Modal: Neue Unterhaltung (DM/Projekt/Team/Firma – nur Auswahlfelder) -->
<div id="new-chat-modal" aria-hidden="true">
  <div class="panel">
    <div class="modal-head">
      <h3 style="margin:0">Neue Unterhaltung</h3>
      <button type="button" class="modal-close" aria-label="schließen">×</button>
    </div>
    <form id="new-chat-form" onsubmit="return false;">
      <div class="row">
        <label for="nc-type">Typ</label>
        <select id="nc-type">
          <option value="dm">Direktchat</option>
          <option value="project">Projekt-Chat</option>
          <option value="team">Team-Chat</option>
          <option value="company">Firmen-Chat</option>
        </select>
      </div>

      <div id="nc-row-dm" class="row">
        <label>Empfänger (Nutzer)</label>
        <select id="nc-user-select"></select>
      </div>

      <div id="nc-row-project" class="row" style="display:none">
        <label>Projekt</label>
        <select id="nc-project-select"></select>
      </div>

      <div id="nc-row-team" class="row" style="display:none">
        <!-- Inhalt (Select bestehende Teams + optional neues Team) wird von chat_create.js dynamisch gesetzt -->
      </div>

      <div id="nc-row-company" class="row" style="display:none">
        <label>Firma</label>
        <select id="nc-company-select"></select>
        <label style="margin-top:8px">Anzeigename (optional)</label>
        <input type="text" id="nc-company-name" placeholder="z. B. Muster AG – Projektchat">
      </div>

      <div id="nc-members-row" class="row">
        <label>Zusätzliche Mitglieder (optional)</label>
        <select id="nc-members-select" multiple size="8"></select>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-secondary modal-close">Abbrechen</button>
        <button type="submit" class="btn">Erstellen</button>
      </div>
    </form>
  </div>
</div>

<script type="module">
  import {ChatAPI, ChatPoller} from '<?= h(asset_url('js/chat_core.js')) ?>';
  const channel = new BroadcastChannel('chat-sync');
  const lsKey   = 'chat.activeRoom';

  const app      = document.getElementById('chat-app');
  const sidebar  = document.getElementById('chat-sidebar');
  const prefix   = app.dataset.prefix || '<?= h(site_prefix()) ?>';
  const userId   = Number(app.dataset.userId || 0);
  const csrf     = app.dataset.csrf || (document.querySelector('meta[name="csrf-token"]')?.content || '');
  const pageRoom = Number(app.dataset.roomId || 0);

  const api   = new ChatAPI({prefix, csrf});
  const log   = document.getElementById('chat-log');
  const form  = document.getElementById('chat-form');
  const ta    = document.getElementById('chat-message');
  const title = document.getElementById('chat-room-title');
  const btnDelete = document.getElementById('btn-room-delete');

  function escapeHtml(s){ return s.replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[c])); }
  function showInfo(t,kind='info'){ const d=document.createElement('div'); d.className='msg system'; if(kind==='error') d.style.background='#7f1d1d'; d.textContent=t; log.appendChild(d); log.scrollTop=log.scrollHeight; }

  let activeRoomId = pageRoom || Number(localStorage.getItem(lsKey)||0) || 0;
  if (!activeRoomId) {
    const first = sidebar?.querySelector('.chat-room-link[data-room-id]');
    if (first) activeRoomId = Number(first.dataset.roomId||0);
  }
  (function syncUrl(){
    if (!activeRoomId) return;
    const url = new URL(window.location.href);
    if (url.searchParams.get('room_id') !== String(activeRoomId)) {
      url.searchParams.set('room_id', String(activeRoomId));
      history.replaceState(null, '', url.toString());
    }
  })();

  function markActiveSidebar(id){
    sidebar?.querySelectorAll('.chat-room-link').forEach(a=>{
      a.classList.toggle('active', String(a.dataset.roomId)===String(id));
    });
  }
  function append(list){
    const atBottom = (log.scrollTop + log.clientHeight + 10) >= log.scrollHeight;
    for(const m of list){
      const div = document.createElement('div');
      div.className='msg '+(Number(m.user_id)===userId?'mine':'other');
      div.innerHTML = `<div class="meta">${m.user_name||('User #'+m.user_id)} • ${m.created_at}</div><div>${escapeHtml(m.body)}</div>`;
      log.appendChild(div);
    }
    if(list.length){ api.markRead(activeRoomId, list[list.length-1].id); }
    if(atBottom) log.scrollTop = log.scrollHeight;
  }

  let poller=null;
  async function loadRoom(roomId,{broadcast=true}={}) {
    if(!roomId){ log.innerHTML=''; showInfo('Kein Chat-Raum aktiv.','info'); btnDelete.disabled = true; return; }
    activeRoomId = roomId;
    localStorage.setItem(lsKey, String(roomId));
    if (broadcast) channel.postMessage({type:'roomChanged', roomId});
    markActiveSidebar(roomId);
    const a = sidebar?.querySelector(`.chat-room-link[data-room-id="${roomId}"] span`);
    if (a) title.textContent = a.textContent;

    log.innerHTML='';
    if(poller) poller.destroy();
    poller = new ChatPoller(api, {roomId, onNewMessages: append});

    const init = await api.messages(roomId, 0, 120);
    if(init?.ok) append(init.messages||[]);
    else showInfo('Laden fehlgeschlagen: '+(init?.error||'unbekannt'),'error');

    poller.start();
    btnDelete.disabled = false;
  }

  await loadRoom(activeRoomId||0,{broadcast:true});

  channel.addEventListener('message',(ev)=>{
    if(ev.data?.type==='roomChanged'){
      const rid = Number(ev.data.roomId||0);
      if(rid && rid!==activeRoomId) loadRoom(rid,{broadcast:false});
    }
  });

  window.addEventListener('chat:openRoom', (e)=>{
    const rid = Number(e.detail?.roomId||0);
    if (rid) loadRoom(rid, {broadcast:true});
  });

  sidebar?.addEventListener('click',(e)=>{
    const a = e.target.closest('.chat-room-link'); if(!a) return;
    e.preventDefault();
    const rid = Number(a.dataset.roomId||0); if(!rid) return;
    const url = new URL(window.location.href);
    url.searchParams.set('room_id', String(rid));
    history.replaceState(null, '', url.toString());
    loadRoom(rid,{broadcast:true});
  });

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const txt = ta.value.trim(); if(!txt) return;
    if(!activeRoomId){ showInfo('Kein Chat-Raum aktiv.','error'); return; }

    const btn = form.querySelector('button[type="submit"]'); if(btn) btn.disabled=true;
    const res = await api.send(activeRoomId, txt);
    if(btn) btn.disabled=false;

    if(res?.ok){
      // Sofort anzeigen:
      append([res.message]);
      // Wichtig: Poller auf den neuesten Stand setzen, damit der nächste Poll die Nachricht NICHT nochmal holt
      if (poller && typeof poller.bumpSince === 'function') {
        poller.bumpSince(res.message.id);
      }
      ta.value=''; ta.focus();
    } else {
      showInfo('Senden fehlgeschlagen: '+(res?.error||'unbekannt'),'error');
    }
  });

  ta.addEventListener('input', ()=> { if(activeRoomId) api.typing(activeRoomId); });

  // ==== Raum löschen ====
  btnDelete?.addEventListener('click', async ()=>{
    if(!activeRoomId) return;
    const name = title.textContent || 'dieser Raum';
    if(!confirm(`„${name}“ wirklich löschen? Das kann nicht rückgängig gemacht werden.`)) return;

    const fd = new FormData();
    fd.set('csrf', csrf);
    fd.set('room_id', String(activeRoomId));
    const r = await fetch(prefix+'api/chat/delete_room.php', {
      method:'POST', credentials:'same-origin', body:fd,
      headers: csrf ? {'X-CSRF-Token': csrf} : {}
    });
    let j=null, txt=''; try{ j=await r.clone().json(); } catch { txt=await r.text().catch(()=> ''); }
    if(!r.ok || !j?.ok){
      alert('Löschen fehlgeschlagen: ' + (j?.message || j?.error || txt || ('HTTP '+r.status)));
      return;
    }

    // Sidebar-Eintrag entfernen
    const link = sidebar?.querySelector(`.chat-room-link[data-room-id="${activeRoomId}"]`);
    if (link) link.remove();

    // Zu einem anderen Raum wechseln oder leeren Zustand anzeigen
    const next = sidebar?.querySelector('.chat-room-link[data-room-id]')?.dataset.roomId;
    if (next) {
      const url = new URL(window.location.href);
      url.searchParams.set('room_id', String(next));
      history.replaceState(null, '', url.toString());
      loadRoom(Number(next), {broadcast:true});
    } else {
      // Keine Räume mehr -> Seite „leer“ halten
      activeRoomId = 0;
      const url = new URL(window.location.href);
      url.searchParams.delete('room_id');
      history.replaceState(null, '', url.toString());
      log.innerHTML = '';
      title.textContent = 'Kein Raum';
      btnDelete.disabled = true;
      showInfo('Raum gelöscht. Erstelle eine neue Unterhaltung.', 'info');
    }
  });
</script>

<script type="module">
  import '<?= h(asset_url('js/chat_create.js')) ?>';
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
