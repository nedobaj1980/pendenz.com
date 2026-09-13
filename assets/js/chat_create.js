// assets/js/chat_create.js
import { ChatAPI } from './chat_core.js';

(function(){
  const root = document.getElementById('chat-app');
  if(!root) return;

  const btnOpen  = document.getElementById('btn-new-chat');
  const modal    = document.getElementById('new-chat-modal');
  const closeBtns= modal?.querySelectorAll('.modal-close');
  const form     = modal?.querySelector('#new-chat-form');

  const typeSel  = form?.querySelector('#nc-type');
  const rowDM    = form?.querySelector('#nc-row-dm');
  const rowProj  = form?.querySelector('#nc-row-project');
  const rowTeam  = form?.querySelector('#nc-row-team');
  const rowComp  = form?.querySelector('#nc-row-company');

  const selUser  = form?.querySelector('#nc-user-select');
  const selProj  = form?.querySelector('#nc-project-select');
  const selComp  = form?.querySelector('#nc-company-select');

  // Team: bestehend auswählen ODER neues Team-Name
  const teamWrap = rowTeam;
  let selTeam, inpTeam;
  // Erzeuge Select + Input dynamisch (falls noch nicht vorhanden)
  if (teamWrap) {
    teamWrap.innerHTML = `
      <label>Team</label>
      <select id="nc-team-select"></select>
      <div id="nc-team-new" style="margin-top:8px; display:none">
        <label>Neues Team anlegen – Name</label>
        <input type="text" id="nc-team-name" placeholder="z. B. Bauleitung Ost">
      </div>
    `;
  }
  selTeam = teamWrap?.querySelector('#nc-team-select');
  inpTeam = teamWrap?.querySelector('#nc-team-name');
  const newTeamBox = teamWrap?.querySelector('#nc-team-new');

  // optionale zusätzliche Mitglieder
  const membersRow  = form?.querySelector('#nc-members-row');
  const selMembers  = form?.querySelector('#nc-members-select');

  const csrf   = root.dataset.csrf || (document.querySelector('meta[name="csrf-token"]')?.content || '');
  const prefix = root.dataset.prefix || '/';
  const api    = new ChatAPI({prefix, csrf});
  const channel= new BroadcastChannel('chat-sync');

  let cacheUsers=null, cacheProjects=null, cacheCompanies=null, cacheTeams=null;

  function fillSelect(selectEl, items, placeholder='', multiple=false) {
    if (!selectEl) return;
    selectEl.innerHTML = '';
    if (!multiple) {
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = placeholder || '— wählen —';
      selectEl.appendChild(opt);
    }
    for (const it of items) {
      const opt = document.createElement('option');
      opt.value = String(it.value);
      opt.textContent = it.label;
      selectEl.appendChild(opt);
    }
  }

  async function loadLists(){
    // Benutzer
    if (!cacheUsers) {
      const u = new URL(prefix + 'api/chat/search_users.php', location.origin);
      u.searchParams.set('limit','1000');
      u.searchParams.set('exclude_self','1');
      const r = await fetch(u, { credentials:'same-origin' });
      const j = await r.json().catch(()=>null);
      cacheUsers = (j?.ok && Array.isArray(j.results)) ? j.results : [];
    }
    // Projekte
    if (!cacheProjects) {
      const u = new URL(prefix + 'api/chat/search_projects.php', location.origin);
      u.searchParams.set('limit','1000');
      const r = await fetch(u, { credentials:'same-origin' });
      const j = await r.json().catch(()=>null);
      cacheProjects = (j?.ok && Array.isArray(j.results)) ? j.results : [];
    }
    // Firmen
    if (!cacheCompanies) {
      const u = new URL(prefix + 'api/chat/search_companies.php', location.origin);
      u.searchParams.set('limit','1000');
      const r = await fetch(u, { credentials:'same-origin' });
      const j = await r.json().catch(()=>null);
      cacheCompanies = (j?.ok && Array.isArray(j.results)) ? j.results : [];
    }
    // Teams (bestehende)
    if (!cacheTeams) {
      const u = new URL(prefix + 'api/chat/list_team_rooms.php', location.origin);
      const r = await fetch(u, { credentials:'same-origin' });
      const j = await r.json().catch(()=>null);
      cacheTeams = (j?.ok && Array.isArray(j.results)) ? j.results : [];
    }

    fillSelect(selUser, cacheUsers.map(u=>({value:u.id, label:u.name || u.email || ('#'+u.id)})), '— Benutzer wählen —');
    fillSelect(selProj, cacheProjects.map(p=>({value:p.id, label:p.name || ('Projekt #'+p.id)})), '— Projekt wählen —');
    fillSelect(selComp, cacheCompanies.map(c=>({value:c.id, label:c.name || ('Firma #'+c.id)})), '— Firma wählen —');

    // Team-Select: zuerst Option „Neues Team anlegen …“
    const teamItems = [{ value:'__new__', label:'— Neues Team anlegen … —' }].concat(
      cacheTeams.map(t=>({ value:t.id, label:t.name || ('Team #'+t.id) }))
    );
    fillSelect(selTeam, teamItems, '— wählen —');

    // Mitglieder (alle Nutzer, Mehrfachauswahl)
    fillSelect(selMembers, cacheUsers.map(u=>({value:u.id, label:u.name || u.email || ('#'+u.id)})), '', true);
    if (selMembers) { selMembers.size = Math.min(10, Math.max(6, cacheUsers.length)); }
  }

  function toggleBoxes(){
    const t = typeSel.value;
    rowDM.style.display   = (t==='dm')      ? 'block' : 'none';
    rowProj.style.display = (t==='project') ? 'block' : 'none';
    rowTeam.style.display = (t==='team')    ? 'block' : 'none';
    rowComp.style.display = (t==='company') ? 'block' : 'none';
    membersRow.style.display = (t==='dm') ? 'none' : 'block';
  }

  function open(){ modal.style.display='block'; loadLists(); }
  function close(){ modal.style.display='none'; form.reset(); }

  btnOpen?.addEventListener('click', open);
  closeBtns?.forEach(b => b.addEventListener('click', close));
  modal?.addEventListener('click', e=>{ if(e.target===modal) close(); });
  typeSel?.addEventListener('change', toggleBoxes);
  toggleBoxes();

  // Team: „Neues Team anlegen …“ steuert das Name-Input
  selTeam?.addEventListener('change', () => {
    const v = selTeam.value;
    newTeamBox.style.display = (v === '__new__' || v === '') ? 'block' : 'none';
  });

  form?.addEventListener('submit', async (e)=>{
    e.preventDefault();
    const type = typeSel.value;

    // Bei Team: wenn ein bestehendes Team gewählt ist -> einfach dorthin springen
    if (type === 'team' && selTeam.value && selTeam.value !== '__new__') {
      const rid = Number(selTeam.value);
      if (rid) {
        const channel = new BroadcastChannel('chat-sync');
        channel.postMessage({type:'roomChanged', roomId: rid});
        const url = new URL(window.location.href);
        url.searchParams.set('room_id', String(rid));
        history.replaceState(null, '', url.toString());
        window.dispatchEvent(new CustomEvent('chat:openRoom', {detail:{roomId: rid}}));
        close();
        return;
      }
    }

    const fd = new FormData();
    fd.set('csrf', csrf);
    fd.set('type', type);

    if (type==='dm') {
      const to = selUser.value;
      if (!to) { alert('Bitte einen Benutzer auswählen.'); return; }
      fd.set('to_user_id', to);
    }

    if (type==='project') {
      const pid = selProj.value;
      if (!pid) { alert('Bitte ein Projekt auswählen.'); return; }
      fd.set('project_id', pid);
    }

    if (type==='company') {
      const cid = selComp.value;
      if (!cid) { alert('Bitte eine Firma auswählen.'); return; }
      fd.set('company_id', cid);
      const display = (form.querySelector('#nc-company-name')?.value || '').trim();
      if (display) fd.set('name', display);
    }

    if (type==='team') {
      const v = selTeam.value;
      if (v === '__new__' || v === '') {
        const name = (inpTeam?.value || '').trim();
        if (!name) { alert('Bitte Team-Name angeben.'); return; }
        fd.set('name', name);
      } else {
        // sollte schon oben gefasst sein
        alert('Bitte auf „Erstellen“ nur bei neuem Team klicken oder oben Team direkt auswählen.');
        return;
      }
    }

    if (type!=='dm') {
      const ids = [...(selMembers?.selectedOptions || [])].map(o=>o.value).filter(Boolean);
      if (ids.length) fd.set('member_ids', ids.join(','));
    }

    const r = await fetch(prefix + 'api/chat/create_room.php', {
      method:'POST',
      credentials:'same-origin',
      body: fd,
      headers: csrf ? { 'X-CSRF-Token': csrf } : {},
    });
    let j=null, txt='';
    try { j = await r.clone().json(); } catch { txt = await r.text().catch(()=> ''); }
    if (!r.ok || !j?.ok) {
      alert('Anlegen fehlgeschlagen: ' + (j?.message || j?.error || txt || ('HTTP '+r.status)));
      return;
    }

    const newId = Number(j.room_id || 0);
    if (newId) {
      channel.postMessage({type:'roomChanged', roomId: newId});
      const url = new URL(window.location.href);
      url.searchParams.set('room_id', String(newId));
      history.replaceState(null, '', url.toString());
      window.dispatchEvent(new CustomEvent('chat:openRoom', {detail:{roomId: newId}}));
    }
    close();
  });
})();
