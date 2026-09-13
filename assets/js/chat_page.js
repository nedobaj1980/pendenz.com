// assets/js/chat_page.js
(() => {
  'use strict';
  const $ = sel => document.querySelector(sel);
  const app = $('#chat-app');
  if (!app) return;

  const initialRoomId = Number(app.dataset.initialRoomId || 0);

  const elRooms = $('#rooms');
  const elRoomsCount = $('#rooms-count');
  const elMsgs = $('#messages');
  const elComposer = $('#composer');
  const elComposerText = $('#composer-text');
  const elTitle = $('#room-title');
  const btnRename = $('#btn-rename');
  const btnAddMember = $('#btn-add-member');

  const btnNewTeam = $('#btn-new-team');
  const btnNewDirect = $('#btn-new-direct');

  let currentRoomId = initialRoomId || 0;
  let unmountChat = null; // disposer von TeamChat.mountChat
  let roomsCache = [];

  function escapeHtml(s){return s.replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}

  async function loadRooms() {
    const res = await TeamChat.API.roomsList();
    if (!res.ok) { elRooms.innerHTML = '<div class="member">Fehler beim Laden.</div>'; return; }
    roomsCache = res.data || [];
    elRooms.innerHTML = '';
    roomsCache.forEach(r => {
      const div = document.createElement('div');
      div.className = 'chat-room' + (r.id == currentRoomId ? ' active' : '');
      div.dataset.roomId = r.id;
      const badge = r.room_type === 'team' ? 'Team' : (r.room_type === 'project' ? 'Projekt' : 'Direkt');
      div.innerHTML = `
        <div style="width:28px;text-align:center;">${badge === 'Direkt' ? '👤' : (badge==='Team'?'👥':'🏗️')}</div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">${escapeHtml(r.name || '(ohne Titel)')}</div>
          <div style="font-size:12px; color:#6b728b;">${badge} • ${r.member_count ?? '-'}</div>
        </div>
      `;
      div.addEventListener('click', () => selectRoom(Number(r.id)));
      elRooms.appendChild(div);
    });
    elRoomsCount.textContent = String(roomsCache.length);
    // falls noch kein Raum gewählt, ersten wählen
    if (!currentRoomId && roomsCache.length) {
      selectRoom(Number(roomsCache[0].id));
    } else {
      highlightActive();
    }
  }

  function highlightActive() {
    [...elRooms.querySelectorAll('.chat-room')].forEach(x=>{
      x.classList.toggle('active', Number(x.dataset.roomId) === currentRoomId);
    });
  }

  async function loadMembers(roomId) {
    const res = await TeamChat.API.membersList(roomId);
    const box = $('#members');
    box.innerHTML = '';
    if (!res.ok) { box.innerHTML = '<div class="member">Fehler…</div>'; return; }
    res.data.forEach(m => {
      const row = document.createElement('div');
      row.className = 'member';
      row.innerHTML = `
        <div>
          <div style="font-weight:600">${escapeHtml(m.name || ('User #'+m.user_id))}</div>
          <div class="role">${escapeHtml(m.role)}</div>
        </div>
        <button class="btn-ghost" data-remove="${m.user_id}">Entfernen</button>
      `;
      row.querySelector('button[data-remove]').addEventListener('click', async () => {
        if (!confirm('Mitglied wirklich entfernen?')) return;
        const r = await TeamChat.API.memberRemove(roomId, Number(m.user_id));
        if (r.ok) loadMembers(roomId);
      });
      box.appendChild(row);
    });
  }

  async function selectRoom(roomId) {
    if (!roomId) return;
    currentRoomId = roomId;
    elTitle.textContent = (roomsCache.find(r => Number(r.id) === roomId)?.name || 'Chat');
    btnRename.disabled = false;
    btnAddMember.disabled = false;

    // Nachrichten-Widget mounten
    if (unmountChat) try { unmountChat(); } catch(_) {}
    elMsgs.innerHTML = ''; // der Mount füllt selbst
    if (window.TeamChat?.mountChat) {
      unmountChat = await TeamChat.mountChat(
        // wir ersetzen den mittleren Body nur für Messages:
        // mountChat erwartet ein Container, wir erzeugen dynamisch einen
        (() => {
          const tmp = document.createElement('div');
          tmp.style.height = '100%';
          elMsgs.replaceWith(tmp);
          // elMsgs-Zeiger aktualisieren:
          // mountChat erzeugt alles (Header braucht es nicht, wir zeigen nur Messages/Composer)
          return tmp;
        })(),
        { roomId: currentRoomId, pollMs: 2500 }
      );
    }
    // Members
    await loadMembers(roomId);
    // URL aktualisieren (ohne reload)
    const url = new URL(location.href);
    url.searchParams.set('room_id', String(roomId));
    history.replaceState(null, '', url);
    highlightActive();
  }

  // Composer: wir lassen TeamChat.mountChat den echten Composer bedienen.
  // Die lokalen Buttons steuern nur Räume & Mitglieder.
  btnRename.addEventListener('click', async () => {
    if (!currentRoomId) return;
    const current = roomsCache.find(r=>Number(r.id)===currentRoomId)?.name || '';
    const n = prompt('Neuer Raum-Name:', current);
    if (n && n.trim()) {
      const r = await fetch('/api/chat_rooms.php?action=rename', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({room_id: currentRoomId, name: n.trim()})
      }).then(x=>x.json());
      if (r.ok) { await loadRooms(); elTitle.textContent = n.trim(); }
      else alert(r.error || 'Fehler beim Umbenennen.');
    }
  });

  btnAddMember.addEventListener('click', async () => {
    if (!currentRoomId) return;
    const id = prompt('Benutzer-ID zum Hinzufügen:');
    const uid = Number(id);
    if (uid>0) {
      const r = await TeamChat.API.memberAdd(currentRoomId, uid);
      if (r.ok) loadMembers(currentRoomId);
      else alert(r.error || 'Fehler beim Hinzufügen.');
    }
  });

  btnNewTeam.addEventListener('click', async () => {
    const name = prompt('Team-Raum Name:');
    if (!name) return;
    const res = await TeamChat.API.roomCreate({room_type:'team', name});
    if (res.ok) { await loadRooms(); selectRoom(res.data.id); }
    else alert(res.error || 'Fehler beim Erstellen.');
  });

  btnNewDirect.addEventListener('click', async () => {
    const id = prompt('Direktchat mit Benutzer-ID:');
    const uid = Number(id);
    if (!uid) return;
    const res = await TeamChat.API.roomCreate({room_type:'direct', member_ids:[uid]});
    if (res.ok) { await loadRooms(); selectRoom(res.data.id); }
    else alert(res.error || 'Fehler beim Erstellen.');
  });

  // Boot
  (async () => {
    await loadRooms();
    if (initialRoomId) selectRoom(initialRoomId);
  })();
})();
