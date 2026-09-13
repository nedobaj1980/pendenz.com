(function(){
  function basePath(){ return location.pathname.startsWith('/pendenz.com/') ? '/pendenz.com' : ''; }
  const $  = (sel, root=document)=>root.querySelector(sel);
  const $$ = (sel, root=document)=>Array.from(root.querySelectorAll(sel));
  const esc = s => String(s ?? '').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m]));

  const formQuick = $('#formQuick');
  const formEdit  = $('#formEdit');
  const editTable = $('#editTable tbody');
  const hiddenCols   = $('#ss_cols_json');
  const hiddenSearch = $('#ss_search_json');
  const hiddenDeny   = $('#ss_deny_json');

  const btnAdvanced = $('#btnAdvanced');
  const advPanel    = $('#advancedPanel');
  const advContent  = $('#advancedContent');
  const btnSaveCols = $('#btnSaveColumns');

  const btnPreview  = $('#btnPreview');
  const prevPanel   = $('#previewPanel');
  const prevContent = $('#previewContent');

  // ---- Vorlagen für Schnellstart (wie vorher) ----
  const TEMPLATES = {
    maengelliste: { table:'maengelliste', pk:'id', cols:[
      {name:'titel',type:'VARCHAR',len:255,null:false,default:null},
      {name:'beschreibung',type:'TEXT',len:null,null:true,default:null},
      {name:'status',type:'VARCHAR',len:40,null:false,default:'offen'},
      {name:'prioritaet',type:'INT',len:null,null:false,default:1},
      {name:'faellig_am',type:'DATE',len:null,null:true,default:null},
      {name:'zugewiesen_an',type:'INT',len:null,null:true,default:null},
    ]},
    projekte: { table:'projekte', pk:'id', cols:[
      {name:'name',type:'VARCHAR',len:200,null:false,default:null},
      {name:'status',type:'VARCHAR',len:40,null:false,default:'geplant'},
    ]},
    benutzer: { table:'benutzer', pk:'id', cols:[
      {name:'name',type:'VARCHAR',len:200,null:false,default:null},
      {name:'email',type:'VARCHAR',len:200,null:false,default:null},
      {name:'rolle',type:'VARCHAR',len:40,null:false,default:'benutzer'},
      {name:'firma_id',type:'INT',len:null,null:true,default:null},
      {name:'is_active',type:'TINYINT',len:null,null:false,default:1},
    ]}
  };

  if (formQuick) {
    formQuick.addEventListener('click', (e)=>{
      const btn = e.target.closest('button[data-template]');
      if (!btn) return;
      e.preventDefault();
      const tpl = TEMPLATES[btn.getAttribute('data-template')];
      if (!tpl) return;
      $('#ct_table').value = tpl.table;
      $('#ct_pk').value    = tpl.pk;
      $('#ct_cols_json').value = JSON.stringify(tpl.cols);
    });
    formQuick.addEventListener('submit', ()=>{
      if (!$('#ct_cols_json').value) $('#ct_cols_json').value = '[]';
    });
  }

  // ------- Einfacher Editor (wie vorher) -------
  function parseJSONSafe(s, fb){ try { const v=JSON.parse(s); return Array.isArray(v)?v:fb; } catch{ return fb; } }
  function toSet(arr){ return new Set((arr||[]).map(String)); }
  function state(){
    return {
      cols:   parseJSONSafe(hiddenCols.value || '[]', []),
      search: parseJSONSafe(hiddenSearch.value || '[]', []),
      deny:   parseJSONSafe(hiddenDeny.value || '[]', [])
    };
  }
  function syncHidden(cols, searchSet, denySet){
    hiddenCols.value   = JSON.stringify(cols);
    hiddenSearch.value = JSON.stringify(Array.from(searchSet));
    hiddenDeny.value   = JSON.stringify(Array.from(denySet));
  }
  function renderRows(cols, searchSet, denySet){
    editTable.innerHTML = '';
    if (!cols.length) {
      editTable.innerHTML = '<tr><td colspan="4" class="muted">Noch keine Spalten geladen.</td></tr>';
      return;
    }
    cols.forEach((name, idx)=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td class="nowrap">
          <button type="button" class="btn up"    aria-label="nach oben">↑</button>
          <button type="button" class="btn down"  aria-label="nach unten">↓</button>
        </td>
        <td class="mono">${esc(name)}</td>
        <td style="text-align:center;"><input type="checkbox" class="chk-search" ${searchSet.has(String(name))?'checked':''}></td>
        <td style="text-align:center;"><input type="checkbox" class="chk-deny" ${denySet.has(String(name))?'checked':''}></td>
      `;
      editTable.appendChild(tr);

      tr.querySelector('.up').addEventListener('click', ()=>{
        if (idx<=0) return; const tmp=cols[idx-1]; cols[idx-1]=cols[idx]; cols[idx]=tmp;
        syncHidden(cols, searchSet, denySet); renderRows(cols, searchSet, denySet);
      });
      tr.querySelector('.down').addEventListener('click', ()=>{
        if (idx>=cols.length-1) return; const tmp=cols[idx+1]; cols[idx+1]=cols[idx]; cols[idx]=tmp;
        syncHidden(cols, searchSet, denySet); renderRows(cols, searchSet, denySet);
      });
      tr.querySelector('.chk-search').addEventListener('change', e=>{
        if (e.target.checked) searchSet.add(String(name)); else searchSet.delete(String(name));
        syncHidden(cols, searchSet, denySet);
      });
      tr.querySelector('.chk-deny').addEventListener('change', e=>{
        if (e.target.checked) denySet.add(String(name)); else denySet.delete(String(name));
        syncHidden(cols, searchSet, denySet);
      });
    });
  }
  (function initSimple(){
    const st = state(); renderRows(st.cols, toSet(st.search), toSet(st.deny));
  })();

  $('#btnLoadCols')?.addEventListener('click', (e)=>{
    e.preventDefault();
    const tableName = $('#ss_table').value.trim();
    if (!tableName) { alert('Bitte Tabellenname ausfüllen.'); return; }
    const url = new URL(basePath() + '/api/inspect_table.php', location.origin);
    url.searchParams.set('table', tableName);
    fetch(url, {credentials:'same-origin'})
      .then(r=>r.json())
      .then(j=>{
        if (!j.ok && j.error) throw new Error(j.error);
        const cols = (j.columns||[]).map(c=>c.COLUMN_NAME);
        const st = state();
        const search = st.search.length ? st.search : (j.suggest_searchable||[]);
        const deny   = st.deny.length   ? st.deny   : [j.pk||'id','created_at','updated_at','deleted_at'].filter(Boolean);
        syncHidden(cols, new Set(search), new Set(deny));
        renderRows(cols, new Set(search), new Set(deny));
        if ($('#ss_pk').value.trim()==='' && j.pk) $('#ss_pk').value=j.pk;
      })
      .catch(err=>alert('Fehler beim Laden: '+err.message));
  });

  // ------- Erweiterte Spalten (Relationen) -------
  let advModel = null; // {table, columns, all_tables}

  btnAdvanced?.addEventListener('click', ()=>{
    const tableName = $('#ss_table').value.trim();
    if (!tableName) { alert('Bitte zuerst Tabellenname ausfüllen & ggf. Spalten laden/speichern.'); return; }
    const url = new URL(basePath() + '/api/columns_get.php', location.origin);
    url.searchParams.set('table', tableName);
    fetch(url, {credentials:'same-origin'})
      .then(r=>r.json())
      .then(j=>{
        if (!j.ok && j.error) throw new Error(j.error);
        advModel = j;
        renderAdvanced();
        advPanel.classList.remove('hidden');
        prevPanel.classList.add('hidden');
      })
      .catch(err=>alert('Fehler: '+err.message));
  });

  function renderAdvanced(){
    const cols = advModel.columns;
    const all  = advModel.all_tables;

    const tableOptions = all.map(t=>`<option value="${esc(t.table_name)}">${esc(t.table_name)}</option>`).join('');
    const tableLookup  = Object.fromEntries(all.map(t=>[t.table_name, t.columns.map(c=>c.COLUMN_NAME)]));

    const rows = cols.map((c, idx)=>{
      const fields = (tableLookup[c.ref_table] || []);
      const fieldOptions = fields.map(f=>`<option value="${esc(f)}">${esc(f)}</option>`).join('');
      return `
        <tr data-idx="${idx}">
          <td class="mono">${esc(c.field)}</td>
          <td><input class="label" value="${esc(c.label||c.field)}"></td>
          <td>
            <select class="type">
              ${['text','number','date','datetime','bool','select'].map(t=>`<option value="${t}" ${c.type===t?'selected':''}>${t}</option>`).join('')}
            </select>
          </td>
          <td><input class="step" value="${esc(c.step ?? '')}" placeholder="z.B. 1 oder 0.01"></td>
          <td><textarea class="options_text" placeholder="Optionen (je Zeile)">${esc(c.options_text ?? '')}</textarea></td>
          <td><input class="validate" value="${esc(c.validate ?? '')}" placeholder="Regex/Rule"></td>
          <td style="text-align:center;"><input class="visible" type="checkbox" ${c.visible? 'checked':''}></td>
          <td><input class="sort_order" type="number" value="${esc(c.sort_order ?? 100)}" style="width:80px"></td>
          <td>
            <select class="ref_table">
              <option value="">—</option>
              ${tableOptions.replace(`value="${esc(c.ref_table??'')}"`,`value="${esc(c.ref_table??'')}" selected`)}
            </select>
          </td>
          <td>
            <select class="ref_field">
              <option value="">—</option>
              ${fieldOptions.replace(`value="${esc(c.ref_field??'')}"`,`value="${esc(c.ref_field??'')}" selected`)}
            </select>
          </td>
          <td>
            <select class="ref_label_field">
              <option value="">—</option>
              ${fieldOptions.replace(`value="${esc(c.ref_label_field??'')}"`,`value="${esc(c.ref_label_field??'')}" selected`)}
            </select>
          </td>
        </tr>
      `;
    }).join('');

    advContent.innerHTML = `
      <div style="overflow:auto;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr>
              <th>Feld</th><th>Label</th><th>Typ</th><th>Step</th><th>Optionen</th><th>Validate</th><th>Sichtbar</th><th>Sort</th>
              <th>Ref-Tabelle</th><th>Ref-Feld</th><th>Ref-Label</th>
            </tr>
          </thead>
          <tbody>${rows || '<tr><td colspan="11" class="muted">Keine Spalten.</td></tr>'}</tbody>
        </table>
      </div>
    `;

    // Bindings
    $$('#advancedContent tbody tr').forEach(tr=>{
      const i = +tr.dataset.idx; const c = advModel.columns[i];

      tr.querySelector('.label').addEventListener('input', e=> c.label = e.target.value );
      tr.querySelector('.type').addEventListener('change', e=> c.type = e.target.value );
      tr.querySelector('.step').addEventListener('input', e=> c.step = e.target.value );
      tr.querySelector('.options_text').addEventListener('input', e=> c.options_text = e.target.value );
      tr.querySelector('.validate').addEventListener('input', e=> c.validate = e.target.value );
      tr.querySelector('.visible').addEventListener('change', e=> c.visible = e.target.checked?1:0 );
      tr.querySelector('.sort_order').addEventListener('input', e=> c.sort_order = parseInt(e.target.value,10)||100 );

      const refTable = tr.querySelector('.ref_table');
      const refField = tr.querySelector('.ref_field');
      const refLabel = tr.querySelector('.ref_label_field');

      refTable.addEventListener('change', e=>{
        c.ref_table = e.target.value || null;
        const fields = (tableLookup[c.ref_table] || []);
        refField.innerHTML = '<option value="">—</option>' + fields.map(f=>`<option value="${esc(f)}">${esc(f)}</option>`).join('');
        refLabel.innerHTML = '<option value="">—</option>' + fields.map(f=>`<option value="${esc(f)}">${esc(f)}</option>`).join('');
        c.ref_field = null; c.ref_label_field = null;
      });
      refField.addEventListener('change', e=> c.ref_field = e.target.value || null );
      refLabel.addEventListener('change', e=> c.ref_label_field = e.target.value || null );
    });
  }

  btnSaveCols?.addEventListener('click', ()=>{
    if (!advModel) return;
    const payload = { table: advModel.table.table_name, columns: advModel.columns };
    fetch(basePath() + '/api/columns_save.php', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    })
    .then(r=>r.json())
    .then(j=>{
      if (!j.ok && j.error) throw new Error(j.error);
      alert('Spalten-Metadaten gespeichert.');
    })
    .catch(err=>alert('Fehler: '+err.message));
  });

  // ------- Vorschau -------
  btnPreview?.addEventListener('click', ()=>{
    const tableName = $('#ss_table').value.trim();
    if (!tableName) { alert('Bitte Tabellenname ausfüllen.'); return; }
    const cols = state().cols; // sichtbare Reihenfolge laut einfachem Editor
    const url = new URL(basePath() + '/api/preview_table.php', location.origin);
    url.searchParams.set('table', tableName);
    url.searchParams.set('cols', JSON.stringify(cols));
    url.searchParams.set('limit', '10');

    fetch(url, {credentials:'same-origin'})
      .then(r=>r.json())
      .then(j=>{
        if (!j.ok && j.error) throw new Error(j.error);
        renderPreview(j.columns, j.rows);
        prevPanel.classList.remove('hidden');
        advPanel.classList.add('hidden');
      })
      .catch(err=>alert('Fehler: '+err.message));
  });

  function renderPreview(columns, rows){
    if (!rows || !rows.length) {
      prevContent.innerHTML = '<div class="muted">Keine Daten gefunden.</div>'; return;
    }
    let html = '<div style="overflow:auto;"><table style="width:100%;border-collapse:collapse;">';
    html += '<thead><tr>' + columns.map(c=>`<th style="border-bottom:1px solid #e5e7eb;padding:.5rem;text-align:left;">${esc(c)}</th>`).join('') + '</tr></thead>';
    html += '<tbody>';
    rows.forEach(r=>{
      html += '<tr>' + columns.map(c=>`<td style="border-bottom:1px solid #f1f5f9;padding:.5rem;">${esc(r[c] ?? '')}</td>`).join('') + '</tr>';
    });
    html += '</tbody></table></div>';
    prevContent.innerHTML = html;
  }

})();
