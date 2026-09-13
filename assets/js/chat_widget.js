// assets/js/chat_widget.js
(function(){
  // ==== Helpers ====
  const $ = (s, r=document) => r.querySelector(s);
  const h = s => String(s||'').replace(/[&<>"']/g, c=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[c]));
  const ensureSlash = p => p.endsWith('/') ? p : p + '/';

  const root = $('#chat-widget');
  if (!root) return;

  const prefix = ensureSlash(root.dataset.prefix || '/');
  const csrf   = root.dataset.csrf || '';
  const userId = Number(root.dataset.userId || 0);

  const wnd       = $('#chat-window', root);
  const selMode   = $('#chat-mode', root);
  const wrapProj  = $('#chat-project-wrap', root);
  const selProj   = $('#chat-project', root);
  const selRoom   = $('#chat-room', root);
  const roomsBar  = $('#rooms-bar', root);
  const list      = $('#chat-messages', root);
  const input     = $('#chat-text', root);
  const btnSend   = $('#chat-send', root);
  const badge     = $('#chat-badge', root);
  const btnUnread = $('#btn-unread', root);

  // ==== API ====
  async function apiGET(path, params = {}) {
    const u = new URL(prefix + path, window.location.origin);
    Object.entries(params).forEach(([k,v])=>{ if(v!==undefined && v!==null && v!=='') u.searchParams.set(k,String(v)) });
    const r = await fetch(u.toString(), { credentials:'same-origin' });
    return await r.json().catch(()=>null);
  }
  async function apiPOST(path, data = {}) {
    const fd = new FormData();
    if (csrf) fd.set('csrf', csrf);
    for (const [k,v] of Object.entries(data)) fd.set(k, String(v));
    const r = await fetch(prefix + path, {
      method:'POST', credentials:'same-origin', body:fd,
      headers: csrf ? { 'X-CSRF-Token': csrf } : {}
    });
    return await r.json().catch(()=>null);
  }
  const API = {
    listRooms:   () => apiGET('api/chat/list_rooms.php'),
    messages:    (roomId, sinceId=0, limit=200) => apiGET('api/chat/messages.php', { room_id:roomId, since_id:sinceId, limit }),
    send:        (roomId, text) => apiPOST('api/chat/messages.php', { room_id:roomId, message:text }),
    markRead:    (roomId, lastId) => apiPOST('api/chat/read.php', { room_id:roomId, last_id:lastId }),
    typing:      (roomId) => apiPOST('api/chat/typing.php', { room_id:roomId }),
  };

  // ==== State ====
  let rooms = [];                 // vom Server
  let metaByRoom = new Map();     // room_id -> { last_read_id, unread_count, last_msg_id, room_type, name, project_id }
  let filtered = [];
  let activeRoomId = 0;
  let sinceId = 0;
  let pollTimer = null;
  let pendingScrollUnreadFor = 0;
  let showUnreadOnly = false;     // <-- Toggle Zustand

  // ==== UI helpers ====
  function scrollBottom(){ list.scrollTop = list.scrollHeight; }
  function clearList(){ list.innerHTML=''; sinceId = 0; }
  function showBadgePulse(totalUnread){
    if (!badge) return;
    if (totalUnread>0){
      badge.hidden = false;
      badge.classList.remove('pulse'); void badge.offsetWidth; badge.classList.add('pulse');
    } else { badge.hidden = true; badge.classList.remove('pulse'); }
  }
  function titleForRoom(r){
    switch(r.room_type){
      case 'dm': return 'DM: ' + (r.name || ('#'+r.id));
      case 'team': return r.name || 'Team';
      case 'project': return r.name || 'Projekt';
      case 'company': return r.name || 'Firma';
      default: return r.name || (r.room_type+' #'+r.id);
    }
  }
  function computeUnread(r){
    const meta = metaByRoom.get(r.id) || {};
    if (typeof meta.unread_count === 'number') return meta.unread_count;
    const lm = Number(meta.last_msg_id||0), lr = Number(meta.last_read_id||0);
    return lm>lr ? (lm-lr) : 0;
  }
  function currentMeta(){ return metaByRoom.get(activeRoomId) || { last_read_id:0, unread_count:0, last_msg_id:0 }; }

  function updateUnreadButton(){
    const meta = currentMeta();
    const unread = Number(meta.unread_count ?? Math.max(0, (meta.last_msg_id||0) - (meta.last_read_id||0)));
    if (!activeRoomId){ btnUnread.disabled = true; btnUnread.classList.remove('active'); btnUnread.textContent = 'Ungelesene (0)'; return; }
    if (showUnreadOnly){
      btnUnread.disabled = false;
      btnUnread.classList.add('active');
      btnUnread.textContent = 'Alle Nachrichten';
      return;
    }
    btnUnread.classList.remove('active');
    btnUnread.textContent = `Ungelesene (${unread})`;
    btnUnread.disabled = unread <= 0;
  }

  // ==== Rendering ====
  function renderProjects(){
    const unique=[], seen=new Set();
    rooms.filter(r => r.room_type==='project' && r.project_id).forEach(r=>{
      const k = r.project_id;
      if (k && !seen.has(k)) { seen.add(k); unique.push({ id:r.project_id, name:r.name }); }
    });
    selProj.innerHTML = '';
    const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='– alle Projekte –'; selProj.appendChild(opt0);
    unique.forEach(p=>{
      const o = document.createElement('option');
      o.value = String(p.id);
      o.textContent = p.name || ('Projekt #'+p.id);
      selProj.appendChild(o);
    });
  }
  function filterRooms(){
    const mode = selMode.value; // 'alle'|'dm'|'team'|'project'|'company'
    let arr = rooms.slice();
    if (mode !== 'alle') {
      if (mode === 'project') {
        const pid = Number(selProj.value || 0);
        arr = arr.filter(r => r.room_type === 'project' && (!pid || Number(r.project_id) === pid));
      } else {
        arr = arr.filter(r => r.room_type === mode);
      }
    }
    return arr;
  }
  function renderRooms(){
    filtered = filterRooms();

    // verstecktes Select
    selRoom.innerHTML = '';
    const opt0 = document.createElement('option'); opt0.value=''; opt0.textContent='– wählen –'; selRoom.appendChild(opt0);
    filtered.forEach(r=>{
      const o = document.createElement('option');
      o.value = String(r.id);
      o.textContent = titleForRoom(r);
      selRoom.appendChild(o);
    });

    // Chips
    roomsBar.innerHTML = '';
    filtered.forEach(r=>{
      const unread = computeUnread(r);
      const chip = document.createElement('button');
      chip.type='button';
      chip.className = 'chip' + (r.id===activeRoomId?' active':'') + (unread>0? ' unread':'');
      chip.dataset.roomId = String(r.id);
      chip.innerHTML = `
        <span class="dot" title="Zur ersten ungelesenen Nachricht springen" aria-label="Ungelesene Nachrichten" role="button"></span>
        <span class="label">${h(titleForRoom(r))}</span>
        <span class="jump">neu</span>
      `;
      roomsBar.appendChild(chip);
    });

    const totalUnread = rooms.reduce((s,r)=> s + computeUnread(r), 0);
    showBadgePulse(totalUnread);
    if (!filtered.some(r=>r.id===activeRoomId)) { activeRoomId = 0; clearList(); }
    updateUnreadButton();
  }
  function updateChipUnread(roomId, unread){
    const chip = roomsBar.querySelector(`.chip[data-room-id="${roomId}"]`);
    if (!chip) return;
    chip.classList.toggle('unread', unread>0);
  }

  function appendMessages(items, sinceSnapshot, {highlightNew=true}={}){
    const atBottom = (list.scrollTop + list.clientHeight + 10) >= list.scrollHeight;
    for (const m of items) {
      const mid = Number(m.id)||0;
      if (mid <= sinceSnapshot) continue; // dedupe
      const div = document.createElement('div');
      const isMine = Number(m.user_id) === userId;
      div.className = 'msg ' + (isMine ? 'mine' : 'other') + (highlightNew ? ' new' : '');
      div.innerHTML = `<div class="meta">${h(m.user_name || ('User #'+m.user_id))} · ${h(m.created_at)}</div>
                       <div>${h(m.body)}</div>`;
      list.appendChild(div);
      sinceId = Math.max(sinceId, mid);
    }
    if (sinceId && items.length) API.markRead(activeRoomId, sinceId);
    if (atBottom) scrollBottom();
    if (highlightNew) setTimeout(()=> list.querySelectorAll('.msg.new').forEach(x=>x.classList.remove('new')), 1000);
  }
  function insertUnreadDividerBeforeId(messageId){
    const anchor = document.createElement('div');
    anchor.id = 'first-unread';
    const div = document.createElement('div');
    div.className = 'divider';
    div.textContent = 'Neu';
    list.appendChild(anchor);
    list.appendChild(div);
  }
  function scrollToFirstUnread(){
    const t = $('#first-unread', list);
    if (t) t.scrollIntoView({ behavior:'smooth', block:'center' });
  }

  // ==== Laden / Wechseln ====
  async function loadRooms(){
    const j = await API.listRooms();
    if (!j?.ok) return;
    rooms = Array.isArray(j.rooms) ? j.rooms : [];

    metaByRoom.clear();
    rooms.forEach(r=>{
      metaByRoom.set(r.id, {
        last_read_id: Number(r.last_read_id||0),
        unread_count: (typeof r.unread_count!=='undefined') ? Number(r.unread_count||0) : undefined,
        last_msg_id:  Number(r.last_msg_id||0),
        room_type: r.room_type, name:r.name, project_id: r.project_id ? Number(r.project_id) : null
      });
    });

    renderProjects();
    renderRooms();

    if (!activeRoomId && filtered.length) {
      await selectRoom(filtered[0].id);
    }
  }

  async function renderRoomMessages(unreadOnly=false, jumpUnread=false){
    const meta = currentMeta();
    const lastRead = Number(meta.last_read_id||0);

    clearList();
    const since = unreadOnly ? lastRead : 0;
    const res = await API.messages(activeRoomId, since, 200);
    const msgs = (res?.ok && Array.isArray(res.messages)) ? res.messages : [];

    let dividerSetForId = null;
    if (!unreadOnly && lastRead>0){
      const firstNew = msgs.find(m => Number(m.id) > lastRead);
      dividerSetForId = firstNew ? Number(firstNew.id) : null;
    }

    const atBottom = (list.scrollTop + list.clientHeight + 10) >= list.scrollHeight;
    for (const m of msgs) {
      const mid = Number(m.id)||0;
      if (!unreadOnly && dividerSetForId && mid === dividerSetForId) {
        insertUnreadDividerBeforeId(mid);
        dividerSetForId = null;
      }
      const isMine = Number(m.user_id)===userId;
      const div = document.createElement('div');
      div.className = 'msg ' + (isMine?'mine':'other');
      div.innerHTML = `<div class="meta">${h(m.user_name || ('User #'+m.user_id))} · ${h(m.created_at)}</div><div>${h(m.body)}</div>`;
      list.appendChild(div);
      sinceId = Math.max(sinceId, mid);
    }
    if (sinceId && msgs.length) {
      await API.markRead(activeRoomId, sinceId);
      // Unread -> 0
      const m = currentMeta();
      metaByRoom.set(activeRoomId, {...m, last_read_id: sinceId, unread_count: 0, last_msg_id: sinceId});
      updateChipUnread(activeRoomId, 0);
      // Toggle-Badge neu
      const totalUnread = rooms.reduce((s,r)=> s + computeUnread(r), 0);
      showBadgePulse(totalUnread);
      updateUnreadButton();
    }
    if (atBottom) scrollBottom();

    if (jumpUnread) setTimeout(scrollToFirstUnread, 30);
  }

  async function selectRoom(roomId, opts={}){
    const {scrollUnread=false} = opts;
    activeRoomId = Number(roomId||0);
    // Chips aktiv
    roomsBar.querySelectorAll('.chip').forEach(c=> c.classList.toggle('active', Number(c.dataset.roomId)===activeRoomId));
    selRoom.value = activeRoomId ? String(activeRoomId) : '';
    if (!activeRoomId) { clearList(); updateUnreadButton(); return; }

    await renderRoomMessages(showUnreadOnly, !showUnreadOnly && scrollUnread);
    startPoll();
  }

  async function loadMessagesTick(){
    if (!activeRoomId) return;
    const sinceSnapshot = sinceId;
    const res = await API.messages(activeRoomId, sinceSnapshot, 200);
    const msgs = (res?.ok && Array.isArray(res.messages)) ? res.messages : [];
    if (msgs.length){
      // In Unread-only und All-Ansicht verhalten wir uns gleich: neue ans Ende, dann als gelesen markieren
      appendMessages(msgs, sinceSnapshot, {highlightNew:true});
      const m = currentMeta();
      metaByRoom.set(activeRoomId, {...m, last_read_id: sinceId, unread_count: 0, last_msg_id: sinceId});
      updateChipUnread(activeRoomId, 0);
      updateUnreadButton();
    }

    // Andere Räume: Unread aktualisieren
    loadRooms._tick = (loadRooms._tick||0)+1;
    if (loadRooms._tick % 3 === 0) {
      const j = await API.listRooms();
      if (j?.ok && Array.isArray(j.rooms)) {
        rooms = j.rooms.map(r=>r);
        j.rooms.forEach(r=>{
          const meta = {
            last_read_id: Number(r.last_read_id||0),
            unread_count: (typeof r.unread_count!=='undefined') ? Number(r.unread_count||0) : undefined,
            last_msg_id:  Number(r.last_msg_id||0),
            room_type: r.room_type, name:r.name, project_id: r.project_id ? Number(r.project_id) : null
          };
          metaByRoom.set(r.id, meta);
          if (r.id !== activeRoomId) updateChipUnread(r.id, computeUnread(r));
        });
        const totalUnread = rooms.reduce((s,r)=> s + computeUnread(r), 0);
        showBadgePulse(totalUnread);
        // Chips neu zeichnen (Sortierung/Labels), aktiven Status behalten
        const current = activeRoomId;
        renderRooms();
        roomsBar.querySelectorAll('.chip').forEach(c=> c.classList.toggle('active', Number(c.dataset.roomId)===current));
      }
    }
  }

  function startPoll(){ stopPoll(); pollTimer = setInterval(loadMessagesTick, 3000); }
  function stopPoll(){ if (pollTimer) { clearInterval(pollTimer); pollTimer=null; } }

  // ==== Events ====
  selMode.addEventListener('change', ()=>{ wrapProj.style.display = (selMode.value === 'project') ? '' : 'none'; renderRooms(); });
  selProj.addEventListener('change', ()=> renderRooms());

  selRoom.addEventListener('change', ()=> selectRoom(Number(selRoom.value||0)));

  roomsBar.addEventListener('click', (e)=>{
    const dot = e.target.closest('.dot');
    const chip = e.target.closest('.chip');
    if (!chip) return;
    const roomId = Number(chip.dataset.roomId||0);
    if (!roomId) return;

    if (dot) {
      e.stopPropagation();
      pendingScrollUnreadFor = roomId;
      showUnreadOnly = false; // in „Alle“-Ansicht springen & scrollen
      btnUnread.classList.remove('active');
      updateUnreadButton();
      selectRoom(roomId, {scrollUnread:true});
      return;
    }
    selectRoom(roomId);
  });

  btnSend.addEventListener('click', async ()=>{
    const txt = (input.value||'').trim();
    if (!txt || !activeRoomId) return;
    const r = await API.send(activeRoomId, txt);
    if (r?.ok && r.message) {
      const isMine = Number(r.message.user_id)===userId;
      const div = document.createElement('div');
      div.className = 'msg ' + (isMine ? 'mine':'other');
      div.innerHTML = `<div class="meta">${h(r.message.user_name||('User #'+r.message.user_id))} · ${h(r.message.created_at)}</div><div>${h(r.message.body)}</div>`;
      list.appendChild(div);
      sinceId = Math.max(sinceId, Number(r.message.id)||0);
      API.markRead(activeRoomId, sinceId);
      input.value=''; scrollBottom();
    }
  });
  input.addEventListener('keydown', (e)=>{ if (e.key==='Enter' && !e.shiftKey){ e.preventDefault(); btnSend.click(); }});

  // Toggle „Ungelesene Nachrichten“
  btnUnread.addEventListener('click', async ()=>{
    if (!activeRoomId) return;
    showUnreadOnly = !showUnreadOnly;
    updateUnreadButton();
    await renderRoomMessages(showUnreadOnly, false);
  });

  // Swipe / Keyboard / Wheel
  function currentOrder(){ return filtered.map(r=>r.id); }
  function switchRoom(dir){
    if (!filtered.length) return;
    const order = currentOrder();
    let idx = Math.max(0, order.indexOf(activeRoomId));
    idx = (idx + (dir>0?1:-1) + order.length) % order.length;
    selectRoom(order[idx]);
  }
  let tX=null, tY=null, tT=0; const SWIPE_X=60, SWIPE_Y=40, SWIPE_MS=600;
  function ts(e){ const t=e.changedTouches?.[0]; if(!t) return; tX=t.clientX; tY=t.clientY; tT=Date.now(); }
  function te(e){ const t=e.changedTouches?.[0]; if(!t||tX==null) return; const dx=t.clientX-tX, dy=t.clientY-tY, dt=Date.now()-tT; tX=tY=null; if (Math.abs(dx)>=SWIPE_X && Math.abs(dy)<=SWIPE_Y && dt<=SWIPE_MS){ switchRoom(dx<0?+1:-1); } }
  list.addEventListener('touchstart', ts, {passive:true}); list.addEventListener('touchend', te, {passive:true});
  wnd.addEventListener('touchstart', ts, {passive:true});  wnd.addEventListener('touchend', te, {passive:true});
  list.addEventListener('keydown', (e)=>{ if (e.key==='ArrowLeft'||e.key==='q'||e.key==='Q'){ e.preventDefault(); switchRoom(-1); } if (e.key==='ArrowRight'||e.key==='e'||e.key==='E'){ e.preventDefault(); switchRoom(+1); } });
  roomsBar.addEventListener('wheel', (e)=>{ if (Math.abs(e.deltaX)>Math.abs(e.deltaY)) e.preventDefault(); roomsBar.scrollLeft += (e.deltaX||e.deltaY); }, {passive:false});

  // Init
  function isOpen(){ return wnd && wnd.style.display !== 'none'; }
  async function ensureInit(){ if (!rooms.length) await loadRooms(); if (isOpen()) startPoll(); else stopPoll(); }
  const mo = new MutationObserver(()=> ensureInit());
  if (wnd) mo.observe(wnd, {attributes:true, attributeFilter:['style']});
  ensureInit();
})();
