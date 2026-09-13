(function(){
  const base = location.pathname.startsWith('/pendenz.com/') ? '/pendenz.com' : '';
  const $ = (s, r=document)=>r.querySelector(s);
  const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));
  const esc = s => String(s??'').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));

  const selTable    = $('#selTable');
  const listIndex   = $('#listIndex');
  const editor      = $('#editor');
  const edTitle     = $('#edTitle');
  const msg         = $('#msg');

  const form        = $('#formList');
  const f_id        = $('#list_id');
  const f_name      = $('#name');
  const f_shared    = $('#shared');
  const f_is_default = $('#is_default');
  const f_sort_col  = $('#sort_col');
  const f_sort_dir  = $('#sort_dir');
  const f_per_page  = $('#per_page');
  const f_q         = $('#f_q');
  const f_status    = $('#f_status');
  const f_kategorie = $('#f_kategorie');
  const f_projekt   = $('#f_projekt');
  const f_objekt    = $('#f_objekt');
  const f_wohnung   = $('#f_wohnung');
  const f_von       = $('#f_von');
  const f_bis       = $('#f_bis');
  const f_only_open = $('#f_only_open');
  const f_quick_choices = $('#f_quick_choices');

  const available   = $('#availableCols');
  const selected    = $('#selectedCols');

  const btnNew      = $('#btnNew');
  const btnSave     = $('#btnSave');
  const btnCancel   = $('#btnCancel');
  const btnDelete   = $('#btnDelete');
  const btnOpenList = $('#btnOpenList');

  let current = null;       // geladene Liste
  let allCols = [];         // Columns from inspect_table.php
  let lists   = [];         // Index

  function toast(t){ msg.textContent=t; setTimeout(()=>msg.textContent='',2500); }

  function loadIndex(){
    fetch(`${base}/api/listen_index.php?table=${encodeURIComponent(selTable.value)}`, {credentials:'same-origin'})
      .then(r=>r.json()).then(j=>{
        if (!j.ok) throw new Error(j.error||'load_failed');
        lists = j.rows||[];
        renderIndex();
        editor.classList.add('hidden');
      })
      .catch(e=>toast('Fehler: '+e.message));
  }

  function renderIndex(){
    listIndex.innerHTML = '';
    
    if (!lists.length) {
      listIndex.innerHTML = '<li class="muted">Noch keine Listen.</li>';
    }

    // Wir zeigen die Start-Liste immer ganz oben an, falls vorhanden
    const defaultList = lists.find(li => +li.is_default === 1);
    if (defaultList) {
        const dEl = document.createElement('li');
        dEl.style.cssText = 'margin-bottom: 4px; position: relative; display: inline-block;';
        dEl.innerHTML = `
            <a href="#" data-id="${defaultList.id}" class="list-link" style="display:flex;justify-content:space-between;gap:12px;padding:10px 32px 10px 16px; border-radius:12px; background:linear-gradient(135deg, #0ea5e9 0%, #0284c7 100%); color:#fff; font-weight:800; box-shadow:0 4px 6px -1px rgba(14,165,233,0.3); transition:all 0.2s; border:none; position:relative; text-decoration:none;">
              <span style="display:flex; align-items:center; gap:8px;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                START-ANSICHT
              </span>
              <span style="font-size:10px; background:rgba(255,255,255,0.2); padding:2px 8px; border-radius:20px; text-transform:uppercase;">Standard</span>
            </a>
            <button class="quick-delete" data-id="${defaultList.id}" style="position:absolute; right:8px; top:50%; transform:translateY(-50%); border:0; background:none; color:rgba(255,255,255,0.6); cursor:pointer; font-size:14px; padding:4px;" title="Standard-Liste löschen">✕</button>
        `;
        listIndex.appendChild(dEl);
        dEl.querySelector('a').addEventListener('click', (e) => { e.preventDefault(); openList(defaultList.id); });
        dEl.querySelector('.quick-delete').addEventListener('click', (e)=>{
            e.preventDefault(); e.stopPropagation();
            if (confirm(`Standard-Liste "${defaultList.name}" wirklich löschen?`)) {
                deleteList(defaultList.id, defaultList.name);
            }
        });
    }

    lists.forEach(li=>{
      if (+li.is_default === 1) return; // Schon oben als Startliste angezeigt
      const isDef = (+li.is_default === 1);
      const el = document.createElement('li');
      el.style.position = 'relative';
      el.innerHTML = `
        <a href="#" data-id="${li.id}" class="list-link" style="display:flex;justify-content:space-between;gap:12px;padding:8px 30px 8px 16px; position:relative; ${isDef ? 'background:#f0f9ff; border-left:4px solid #0ea5e9; font-weight:bold;' : ''}">
          <span style="display:flex; align-items:center; gap:8px;">
            ${isDef ? '<span style="background:#0ea5e9; color:#fff; font-size:9px; padding:2px 6px; border-radius:4px; text-transform:uppercase; letter-spacing:0.05em;">START</span>' : ''}
            ${esc(li.name)}
          </span>
          <div style="display:flex; gap:6px; align-items:center;">
            ${+li.shared ? '<span class="muted" style="font-size:10px; opacity:0.7;">geteilt</span>' : ''}
          </div>
        </a>
        <button class="quick-delete" data-id="${li.id}" style="position:absolute; right:8px; top:50%; transform:translateY(-50%); border:0; background:none; color:#94a3b8; cursor:pointer; font-size:14px; padding:4px;" title="Liste löschen">✕</button>
      `;
      listIndex.appendChild(el);
      el.querySelector('.list-link').addEventListener('click', (e)=>{
        e.preventDefault(); openList(li.id);
      });
      el.querySelector('.quick-delete').addEventListener('click', (e)=>{
        e.preventDefault(); e.stopPropagation();
        if (confirm(`Liste "${li.name}" wirklich löschen?`)) {
            deleteList(li.id, li.name);
        }
      });
    });

    function deleteList(id, name) {
        const fd = new FormData(); fd.append('id', String(id));
        fetch(`${base}/api/listen_delete.php`, { method:'POST', body:fd, credentials:'same-origin' })
        .then(r=>r.json()).then(j=>{
            if (j.ok) { 
                toast(`Liste "${name}" gelöscht.`); 
                loadIndex(); 
                editor.classList.add('hidden'); 
            } else {
                alert('Fehler beim Löschen: ' + (j.error || 'Unbekannt'));
            }
        }).catch(e=>alert('Netzwerkfehler beim Löschen: ' + e.message));
    }
    
    // Add the "+ Neue Ansicht" button directly into the list
    const plusEl = document.createElement('li');
    plusEl.innerHTML = `
      <a href="#" id="btnNewInList" style="display: inline-flex; align-items: center; padding: 8px 16px; border-radius: 20px; color: #0369a1; text-decoration: none; font-weight: 700; font-size: 13px; border: 2px dashed #7dd3fc; background: #f0f9ff; margin-left:12px; transition:all 0.2s;">
        + Neue Liste erstellen
      </a>`;
    listIndex.appendChild(plusEl);
    plusEl.querySelector('a').addEventListener('click', (e) => {
        e.preventDefault();
        btnNew.click(); // Trigger the existing logic
    });
    // Add hover effect
    plusEl.querySelector('a').onmouseover = function() { this.style.background = '#e0f2fe'; };
    plusEl.querySelector('a').onmouseout = function() { this.style.background = '#f0f9ff'; };
  }

  function loadCols(){
    // vorhandene Spalten aus DB ziehen
    const url = new URL(`${base}/api/inspect_table.php`, location.origin);
    url.searchParams.set('table', selTable.value);
    return fetch(url, {credentials:'same-origin'}).then(r=>r.json()).then(j=>{
      if (!j.ok && j.error) throw new Error(j.error);
      allCols = (j.columns||[]).map(c=>c.COLUMN_NAME);
      // Zusatz-Aliase, die unsere Liste/Seite kennt:
      if (selTable.value==='pendenzen') {
        if (!allCols.includes('projekt_name')) allCols.push('projekt_name');
        if (!allCols.includes('zustaendig_name')) allCols.push('zustaendig_name');
        if (!allCols.includes('vorgangsart_name')) allCols.push('vorgangsart_name');
        if (!allCols.includes('objekt_name')) allCols.push('objekt_name');
        if (!allCols.includes('wohnung_name')) allCols.push('wohnung_name');
        if (!allCols.includes('raum_name')) allCols.push('raum_name');
        if (!allCols.includes('verantwortlicher')) allCols.push('verantwortlicher');
        if (!allCols.includes('erstes_bild')) allCols.push('erstes_bild');
        if (!allCols.includes('cover')) allCols.push('cover');
        if (!allCols.includes('bilder')) allCols.push('bilder');
        if (!allCols.includes('dokumente')) allCols.push('dokumente');
        if (!allCols.includes('pdf')) allCols.push('pdf');
        if (!allCols.includes('id')) allCols.push('id');
        if (!allCols.includes('dauer')) allCols.push('dauer');
        if (!allCols.includes('vorgaenger_id')) allCols.push('vorgaenger_id');
        if (!allCols.includes('erstellt_von')) allCols.push('erstellt_von');
      }

      renderAvailable();
      renderSortCols();
    });
  }

  const translations = {
    'titel': 'Titel / Aufgabe',
    'erstes_bild': '🖼️ Bilder-Vorschau',
    'cover': '🖼️ Haupt-Liegenschaftsbild (Cover)',
    'bilder': '📸 Alle Bilder / Dokumente',
    'dokumente': '📂 Dokumente (alle Dateien)',
    'pdf': '📕 Nur PDF-Dokumente',
    'status': 'Status (offen/erledigt)',
    'wichtigkeit': 'Prioritäts-Sterne (1-5)',
    'sichtbarkeit': 'Sichtbarkeits-Level',
    'projekt_name': 'Projekt-Name',
    'zustaendig_name': 'Zuständige Person',
    'vorgangsart_name': '📑 Vorgangsart (Typ)',
    'startdatum': 'Start-Datum',
    'enddatum': 'Fälligkeits-Datum (Deadline)',
    'uhrzeit': 'Präzise Uhrzeit (Agenda)',
    'tageszeit': 'Tagesabschnitt (Vormittag/etc.)',
    'dauer': '⏱️ Dauer (smart)',
    'vorgaenger_id': '🔗 Vorgänger (ID)',
    'erstellt_am': 'Erstellt am (Datum/Zeit)',
    'geaendert_am': 'Geändert am (Datum/Zeit)',
    'deleted_at': 'Gelöscht am',
    'id': 'ID (Eindeutige Nummer)',
    'mandant_id': 'Mandant (ID)',
    'projekt_id': 'Projekt (ID)',
    'wohnung_id': 'Einheit (ID)',
    'ordner_id': 'Ordner (ID)',
    'fs_rel_path': 'Speicher-Pfad',
    'sort_index': 'Sortier-Reihenfolge',
    'kurzbeschreibung': 'Kurzbeschreibung',
    'langbeschreibung': 'Lange Beschreibung',
    'notiz': 'Interne Notiz',
    'beschreibung': 'Beschreibung (Alt)',
    'send_now': 'Sofort-Versand',
    'vorgaenger_id': 'Vorgänger-ID',
    'erstellt_von': 'Erstellt von (ID)',
    'assignee_can_edit': 'Bearbeiter darf editieren',
    'zustaendig_id': 'Zuständiger (ID)',
    'created_at': 'Erstellungs-Zeitpunkt',
    'updated_at': 'Update-Zeitpunkt',
    'confirmation_required': 'Bestätigung nötig',
    'confirmation_by': 'Bestätigt von (ID)',
    'confirmation_at': 'Bestätigt am',
    'external_can_view': 'Externer darf sehen',
    'external_can_upload': 'Externer darf hochladen',
    'public_enabled': 'Öffentlich freigeben',
    'public_token': 'Öffentlicher Zugriffs-Token',
    'submitted_by': 'Eingereicht von',
    'submitted_at': 'Eingereicht am',
    'reviewed_by': 'Geprüft von',
    'reviewed_at': 'Geprüft am',
    'extra_json': 'Zusatzdaten (Daten-Objekt)',
    'zustaendig_typ': 'Zuständigkeits-Typ',
    'sicht_ref_id': 'Referenz-ID',
    'is_protocol': 'Als Protokoll markieren',
    'protocol_type': 'Protokoll-Typ',
    'objekt_name': '🏢 Objekt Name',
    'wohnung_name': '🚪 Wohnung / Einheit',
    'raum_name': '🚪 Raum',
    'verantwortlicher': '👤 Verantwortlicher',
    'unt_bemerkung': '👷 Info Unternehmer (Rückmeldung)',
    'unt_new_input': '🔔 Unternehmer-Benachrichtigung (Neu)',
    'aktion': '⚙️ Aktion (Buttons)'
  };


  function renderAvailable(filterTerm = ''){
    const groups = {
      'Termine & Planung': ['startdatum', 'enddatum', 'uhrzeit', 'tageszeit', 'dauer', 'vorgaenger_id'],
      'Stammdaten & Bilder': ['vorgangsart_name', 'titel', 'unt_new_input', 'unt_bemerkung', 'erstes_bild', 'cover', 'bilder', 'projekt_name', 'objekt_name', 'wohnung_name', 'raum_name', 'zustaendig_name', 'status', 'wichtigkeit', 'sichtbarkeit', 'kategorie', 'kurzbeschreibung'],
      'Verlauf & Protokoll': ['erstellt_am', 'geaendert_am', 'deleted_at'],
      'Systemfelder & Dokumente': ['id', 'erstellt_von', 'verantwortlicher', 'bearbeitet_von', 'dokumente', 'pdf', 'aktion']

    };
    
    let html = '';
    const categorized = new Set();
    const filter = filterTerm.toLowerCase();

    Object.keys(groups).forEach(gName => {
      let gHtml = '';
      let groupCols = [];

      if (gName === 'SaaS & Systemfelder (Fortgeschritten)') {
         allCols.forEach(c => { if (!categorized.has(c)) groupCols.push(c); });
      } else {
         allCols.forEach(c => { 
            if (groups[gName] && groups[gName].includes(c)) {
               groupCols.push(c);
               categorized.add(c);
            }
         });
      }

      // Filter anwenden
      if (filter) {
          groupCols = groupCols.filter(c => {
              const label = (translations[c] || c).toLowerCase();
              return label.includes(filter) || c.toLowerCase().includes(filter);
          });
      }

      // ALPHABETISCH SORTIEREN nach Label (German)
      groupCols.sort((a,b) => {
          const la = (translations[a] || a).toLowerCase();
          const lb = (translations[b] || b).toLowerCase();
          return la.localeCompare(lb, 'de');
      });

      groupCols.forEach(c => {
         const label = translations[c] || c.replace(/_id/g, ' (ID)').replace(/_/g, ' ');
         const isVirtual = c.startsWith('json:');
         const displayLabel = isVirtual ? c.replace('json:','') : label;
         
         gHtml += `
           <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; padding:4px 0; border-bottom:1px solid #f1f5f9; transition:background 0.2s; cursor:pointer;" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
             <label style="display:flex; gap:10px; align-items:center; flex:1; cursor:pointer;" title="Datenbankfeld: ${esc(c)}">
               <input type="checkbox" class="chk-col" value="${esc(c)}" style="width:16px; height:16px;"> 
               <div style="display:flex; flex-direction:column;">
                  <span style="${isVirtual ? 'color:#3b82f6;' : 'color:#0f172a;'} font-weight:700; font-size:13.5px;">${esc(displayLabel)}</span>
                  <span style="color:#cbd5e1; font-size:9.5px; font-weight:400; font-family:monospace;">${esc(c.replace('json:',''))}</span>
               </div>
             </label>
             ${isVirtual ? `<button type="button" onclick="deleteVirtualField('${esc(c)}')" style="background:none; border:none; color:#cbd5e1; font-size:14px; cursor:pointer;" title="Feld komplett löschen" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#cbd5e1'">🗑️</button>` : ''}
           </div>`;
      });

      if (gHtml) html += `
        <div style="margin-bottom:24px;">
          <strong style="color:#1e293b; font-size:12px; display:block; margin-bottom:12px; border-bottom:2px solid #e2e8f0; padding-bottom:6px; text-transform:uppercase; letter-spacing:0.1em; font-weight:800;">
            ${gName}
          </strong>
          <div>${gHtml}</div>
        </div>`;
    });

    available.innerHTML = html || '<div style="color:#94a3b8; font-size:13px; text-align:center; padding:30px; font-style:italic;">Keine passenden Felder gefunden.</div>';
    
    // pre-check
    const picked = $$('#selectedCols li').map(li=>li.dataset.col);
    $$('.chk-col').forEach(chk=>{
      if (picked.includes(chk.value)) chk.checked = true;
      chk.addEventListener('change', e=>{
        if (e.target.checked) addSelected(e.target.value);
        else removeSelected(e.target.value);
      });
    });
  }

  const f_searchFields = $('#fieldSearch');
  if (f_searchFields) {
      f_searchFields.addEventListener('keyup', (e) => {
          renderAvailable(e.target.value);
      });
  }
  function renderSortCols(){
    f_sort_col.innerHTML = '<option value="">–</option>' + allCols.map(c => {
        const label = translations[c] || c.replace(/_/g, ' ');
        return `<option value="${esc(c)}">${esc(label)} (${esc(c)})</option>`;
    }).join('');
  }
  function addSelected(col){
    if ($(`#selectedCols li[data-col="${CSS.escape(col)}"]`)) return;
    const li = document.createElement('li');
    li.className='col-item';
    li.dataset.col = col;
    li.draggable = true;
    
    // Preserve responsive config if available, otherwise use sensible defaults
    const config = (window.currentColsConfig && window.currentColsConfig[col]) ? window.currentColsConfig[col] : {
        width_desktop: '', width_ipad: '', width_mobile: '',
        visible_desktop: 1, visible_ipad: 1, visible_mobile: 1
    };
    
    li.dataset.w_d = config.width_desktop || '';
    li.dataset.w_i = config.width_ipad || '';
    li.dataset.w_m = config.width_mobile || '';
    li.dataset.v_d = config.visible_desktop !== undefined ? config.visible_desktop : 1;
    li.dataset.v_i = config.visible_ipad !== undefined ? config.visible_ipad : 1;
    li.dataset.v_m = config.visible_mobile !== undefined ? config.visible_mobile : 1;

    const label = translations[col] || col.replace(/_/g, ' ');
    li.innerHTML = `
      <div style="display:flex; justify-content:space-between; align-items:center; width:100%; padding:10px 15px;">
        <div style="display:flex; align-items:center; gap:12px;">
          <span class="drag-handle" style="cursor:grab; font-size:18px; color:#94a3b8;">&#8801;</span>
          <div style="display:flex; flex-direction:column;">
              <span style="font-weight:600; font-size:14px; color:#1e293b;">${esc(label)}</span>
              <span style="color:#94a3b8; font-size:10px; font-family:monospace;">${esc(col)}</span>
          </div>
        </div>
        <div style="display:flex; align-items:center; gap:10px;">
          <button type="button" class="btn-col-settings" style="background:#f1f5f9; border:none; border-radius:4px; padding:4px 8px; cursor:pointer; font-size:14px;" title="Einstellungen">⚙️</button>
          <button type="button" class="remove" style="background:none; border:none; color:#ef4444; cursor:pointer; font-size:18px;" title="Entfernen">&times;</button>
        </div>
      </div>
      <div class="col-settings-panel hidden" style="background:#f8fafc; border-top:1px solid #e2e8f0; padding:12px 15px; font-size:12px;">
        <div style="display:grid; grid-template-columns: 1fr 1fr 1fr; gap:15px; margin-bottom:10px;">
          <div>
            <div style="font-weight:700; color:#64748b; margin-bottom:5px; font-size:10px; text-transform:uppercase;">🖥️ Desktop</div>
            <div style="display:flex; align-items:center; gap:5px;">
              <input type="text" class="inp-w-d" placeholder="px/%" style="width:100%; padding:4px; border:1px solid #cbd5e1; border-radius:4px;" value="${esc(li.dataset.w_d||'')}">
              <label style="display:flex; align-items:center;"><input type="checkbox" class="chk-v-d" ${li.dataset.v_d==='0'?'':'checked'}> 👁️</label>
            </div>
          </div>
          <div>
            <div style="font-weight:700; color:#64748b; margin-bottom:5px; font-size:10px; text-transform:uppercase;">平板 iPad</div>
            <div style="display:flex; align-items:center; gap:5px;">
              <input type="text" class="inp-w-i" placeholder="px/%" style="width:100%; padding:4px; border:1px solid #cbd5e1; border-radius:4px;" value="${esc(li.dataset.w_i||'')}">
              <label style="display:flex; align-items:center;"><input type="checkbox" class="chk-v-i" ${li.dataset.v_i==='0'?'':'checked'}> 👁️</label>
            </div>
          </div>
          <div>
            <div style="font-weight:700; color:#64748b; margin-bottom:5px; font-size:10px; text-transform:uppercase;">📱 Mobile</div>
            <div style="display:flex; align-items:center; gap:5px;">
              <input type="text" class="inp-w-m" placeholder="px/%" style="width:100%; padding:4px; border:1px solid #cbd5e1; border-radius:4px;" value="${esc(li.dataset.w_m||'')}">
              <label style="display:flex; align-items:center;"><input type="checkbox" class="chk-v-m" ${li.dataset.v_m==='0'?'':'checked'}> 👁️</label>
            </div>
          </div>
        </div>
      </div>
    `;
    selected.appendChild(li);
    li.querySelector('.remove').addEventListener('click', ()=>{ removeSelected(col); });
    li.querySelector('.btn-col-settings').addEventListener('click', (e)=>{ 
        const panel = li.querySelector('.col-settings-panel');
        panel.classList.toggle('hidden');
    });
    wireDrag(li);
  }
  function removeSelected(col){
    const li = $(`#selectedCols li[data-col="${CSS.escape(col)}"]`);
    if (li) li.remove();
    const chk = $(`.chk-col[value="${CSS.escape(col)}"]`);
    if (chk) chk.checked = false;
  }
  function clearSelected(){ selected.innerHTML = ''; }

  function wireDrag(li){
    li.addEventListener('dragstart', e=>{ e.dataTransfer.setData('text/plain', li.dataset.col); });
    selected.addEventListener('dragover', e=>{ e.preventDefault(); });
    selected.addEventListener('drop', e=>{
      e.preventDefault();
      const col = e.dataTransfer.getData('text/plain');
      const src = $(`#selectedCols li[data-col="${CSS.escape(col)}"]`);
      const tgt = e.target.closest('li');
      if (src && tgt && src!==tgt) selected.insertBefore(src, tgt);
      refreshPreview();
    });
  }

  function openEditor(title){
    edTitle.textContent = title;
    editor.classList.remove('hidden');
    document.getElementById('previewSection').classList.remove('hidden');
    refreshPreview();
  }

  window.refreshPreview = function() {
    const cols = $$('#selectedCols li').map((li,ix)=>({ col_name: li.dataset.col, sort_order: (ix+1)*10 }));
    fetch(`${base}/api/listen_preview.php`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ columns: cols })
    })
    .then(r=>r.text())
    .then(html=>{
        document.getElementById('livePreviewContainer').innerHTML = html;
    })
    .catch(e=>console.error('Preview Error', e));
  };

  function openList(id){
    Promise.all([loadCols(),
      fetch(`${base}/api/listen_one.php?id=${id}`, {credentials:'same-origin'}).then(r=>r.json())
    ])
    .then(([_, j])=>{
      if (!j.ok) throw new Error(j.error||'load_failed');
      current = j.list;
      f_id.value = j.list.id;
      f_name.value = j.list.name;
      f_shared.checked = +j.list.shared===1;
      f_is_default.checked = +j.list.is_default===1;
      f_per_page.value = j.list.per_page || 25;
      f_sort_dir.value = (j.list.sort_dir==='desc')?'desc':'asc';
      f_sort_col.value = j.list.sort_col || '';

      const filters = j.list.filters_json ? JSON.parse(j.list.filters_json) : {};
      f_q.value       = filters.q || '';
      f_status.value  = filters.status || '';
      f_kategorie.value = filters.kategorie_id || '';
      f_projekt.value = filters.projekt_id || '';
      f_objekt.dataset.preVal = filters.objekt_id || '';
      f_wohnung.dataset.preVal = filters.wohnung_id || '';
      
      if (f_projekt.value) updateProjectContextForFilters(f_projekt.value);
      
      f_von.value     = filters.von || '';
      f_bis.value     = filters.bis || '';
      f_only_open.checked = !!filters.only_open;
      if (f_quick_choices) f_quick_choices.value = filters.quick_choices || '';


      clearSelected();
      window.currentColsConfig = {};
      (j.columns||[]).forEach(c=> {
          window.currentColsConfig[c.col_name] = {
              width_desktop: c.width_desktop,
              width_ipad: c.width_ipad,
              width_mobile: c.width_mobile,
              visible_desktop: c.visible_desktop,
              visible_ipad: c.visible_ipad,
              visible_mobile: c.visible_mobile
          };
          addSelected(c.col_name);
      });

      btnOpenList.href = `${base}/pages/pendenzen_liste.php?list_id=${encodeURIComponent(j.list.id)}`;
      openEditor('Listen-Tabelle konfigurieren');
    })
    .catch(e=>toast('Fehler: '+e.message));
  }

  btnNew?.addEventListener('click', ()=>{
    loadCols().then(()=>{
      current = null;
      f_id.value = '';
      f_name.value = '';
      f_shared.checked = false;
      f_is_default.checked = false;
      f_per_page.value = 25;
      f_sort_dir.value = 'asc';
      f_sort_col.value = '';
      f_q.value=''; f_status.value=''; f_kategorie.value=''; f_projekt.value=''; 
      f_objekt.innerHTML = '<option value="">– Alle Objekte –</option>';
      f_wohnung.innerHTML = '<option value="">– Alle Wohnungen –</option>';
      f_von.value=''; f_bis.value=''; f_only_open.checked=false;
      clearSelected();
      // sinnvolle Defaults
      ['titel','projekt_name','enddatum','status'].forEach(c=>{ if (allCols.includes(c)) addSelected(c); });
      btnOpenList.href = '#';
      openEditor('Neue Listen-Tabelle erstellen');
    });
  });

  btnCancel?.addEventListener('click', ()=> editor.classList.add('hidden'));

  btnSave?.addEventListener('click', (e)=>{
    e.preventDefault();
    const cols = $$('#selectedCols li').map((li,ix)=>({ 
        col_name: li.dataset.col, 
        sort_order: (ix+1)*10,
        width_desktop: li.querySelector('.inp-w-d').value || null,
        width_ipad: li.querySelector('.inp-w-i').value || null,
        width_mobile: li.querySelector('.inp-w-m').value || null,
        visible_desktop: li.querySelector('.chk-v-d').checked ? 1 : 0,
        visible_ipad: li.querySelector('.chk-v-i').checked ? 1 : 0,
        visible_mobile: li.querySelector('.chk-v-m').checked ? 1 : 0
    }));
    const filters = {
      q: f_q.value.trim(),
      status: f_status.value,
      kategorie_id: f_kategorie.value ? parseInt(f_kategorie.value,10) : null,
      projekt_id: f_projekt.value ? parseInt(f_projekt.value,10) : null,
      objekt_id: f_objekt.value ? parseInt(f_objekt.value,10) : null,
      wohnung_id: f_wohnung.value ? parseInt(f_wohnung.value,10) : null,
      von: f_von.value || '',
      bis: f_bis.value || '',
      only_open: f_only_open.checked ? 1 : 0,
      quick_choices: f_quick_choices ? f_quick_choices.value.trim() : ''
    };

    const payload = {
      id: f_id.value ? parseInt(f_id.value,10) : 0,
      name: f_name.value.trim(),
      table_name: selTable.value,
      filters,
      sort_col: f_sort_col.value || '',
      sort_dir: f_sort_dir.value,
      per_page: parseInt(f_per_page.value,10) || 25,
      shared: f_shared.checked ? 1 : 0,
      is_default: f_is_default.checked ? 1 : 0,
      columns: cols
    };
    fetch(`${base}/api/listen_save.php`, {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    })
    .then(r=>r.json())
    .then(j=>{
      if (!j.ok) throw new Error(j.error||'save_failed');
      toast('Gespeichert.');
      loadIndex();
      if (j.id) btnOpenList.href = `${base}/pages/pendenzen_liste.php?list_id=${encodeURIComponent(j.id)}`;
    })
    .catch(e=>toast('Fehler: '+e.message));
  });

  btnDelete?.addEventListener('click', ()=>{
    const id = f_id.value ? parseInt(f_id.value,10) : 0;
    if (!id) return;
    if (!confirm('Diese Liste wirklich löschen?')) return;
    deleteList(id, f_name.value);
  });

  selTable?.addEventListener('change', ()=> loadIndex());
  
  // Expose global function for the inline field builder
  window.addVirtualField = function() {
      const input = document.getElementById('new_field_name');
      const typeSelect = document.getElementById('new_field_type');
      const val = input.value.trim();
      const typeVal = typeSelect ? typeSelect.value : 'text';
      if (!val) { alert('Bitte einen Feldnamen eingeben.'); return; }
      
      fetch(`${base}/api/listen_field_save.php`, {
          method:'POST',
          headers:{'Content-Type': 'application/json'},
          body: JSON.stringify({ name: val, type: typeVal })
      }).then(r=>r.json()).then(j=>{
          if (!j.ok) { alert(j.error); return; }
          
          allCols.push(j.key);
          input.value = '';
          document.getElementById('customFieldBuilder').classList.add('hidden');
          renderAvailable();
          toast('✅ Feld erfolgreich in der Datenbank erstellt! Ziehe es nun rechts in die Liste.');
      }).catch(e => alert('Fehler: ' + e));
  };

  window.deleteVirtualField = function(key) {
      if (!confirm(`Möchtest du das Feld '${key}' wirklich löschen?`)) return;
      
      const fd = new FormData();
      fd.append('key', key);
      
      fetch(`${base}/api/listen_field_delete.php`, {
          method: 'POST',
          body: fd
      }).then(r=>r.json()).then(j=>{
          if (!j.ok) { alert('Löschen fehlgeschlagen: ' + j.error); return; }
          
          // Remove from allCols in JS
          allCols = allCols.filter(c => c !== key);
          // And if it is currently selected, remove it from there too
          removeSelected(key);
          renderAvailable();
          refreshPreview();
          toast('🗑️ Feld erfolgreich entfernt.');
      }).catch(e => alert('Fehler beim Löschen: ' + e));
  };

  window.updateProjectContextForFilters = function(pid) {
      if (!pid) {
          f_objekt.innerHTML = '<option value="">– Alle Objekte –</option>';
          f_wohnung.innerHTML = '<option value="">– Alle Wohnungen –</option>';
          return;
      }
      fetch(`${base}/pages/pendenzen.php?action=context&projekt_id=${pid}`)
          .then(r=>r.json()).then(j=>{
              if (!j.ok) return;
              f_objekt.innerHTML = '<option value="">– Alle Objekte –</option>' + 
                  j.objects.map(o => `<option value="${o.id}" ${parseInt(f_objekt.dataset.preVal)===o.id?'selected':''}>${esc(o.name)}</option>`).join('');
              
              const pW = parseInt(f_wohnung.dataset.preVal);
              f_wohnung.innerHTML = '<option value="">– Alle Wohnungen –</option>' + 
                  j.apartments.map(w => `<option value="${w.id}" ${pW===w.id?'selected':''}>${esc(w.name)}</option>`).join('');
              
              f_objekt.dataset.preVal = '';
              f_wohnung.dataset.preVal = '';
          });
  };

  window.updateApartmentsForFilters = function(oid) {
      const pid = f_projekt.value;
      if (!pid) return;
      fetch(`${base}/pages/pendenzen.php?action=context&projekt_id=${pid}`)
          .then(r=>r.json()).then(j=>{
              if (!j.ok) return;
              // If we have an object filter, we should ideally restrict apartments in pendenzen.php context, 
              // but here we can just show apartments for that project and later filter them by object in SQL.
              // For simplicity, we just keep the apartments list from the project.
          });
  };

  // boot
  loadIndex();
})();
