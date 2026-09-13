(function(){
  const ctx = window.__ST_SETTINGS_CTX || {};
  function basePath(){ return (location.pathname.startsWith('/pendenz.com/')) ? '/pendenz.com' : ''; }
  function esc(s){ return String(s ?? '').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }

  let model = null; // {table, settings, columns, physical_columns, all_tables}

  function fetchSettings(){
    const url = new URL(basePath() + '/api/smarttable_settings_get.php', location.origin);
    if (ctx.edit)  url.searchParams.set('edit', ctx.edit);
    if (ctx.table) url.searchParams.set('table', ctx.table);
    return fetch(url, {credentials:'same-origin'}).then(r=>r.json());
  }

  function renderMeta(){
    const t = model.table;
    const s = model.settings || {};
    const el = document.getElementById('st-meta-body');
    el.innerHTML = `
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
        <div>
          <label>Interner Name</label>
          <input type="text" value="${esc(t.table_name)}" disabled style="width:100%;padding:.4rem;">
        </div>
        <div>
          <label>Physische Tabelle</label>
          <input type="text" value="${esc(t.physical_table || t.table_name)}" disabled style="width:100%;padding:.4rem;">
        </div>
        <div>
          <label>Default-Search Felder (kommasepariert)</label>
          <input id="st-searchables" type="text" value="${esc(JSON.parse(s.searchable_json||'[]').join(', '))}" style="width:100%;padding:.4rem;">
        </div>
        <div>
          <label>Bearbeiten verbieten (Feldnamen, kommasepariert)</label>
          <input id="st-editdeny" type="text" value="${esc(JSON.parse(s.editdeny_json||'[]').join(', '))}" style="width:100%;padding:.4rem;">
        </div>
        <div>
          <label><input id="st-softdelete" type="checkbox" ${s.soft_delete==1?'checked':''}> Soft-Delete aktiv</label>
        </div>
        <div>
          <label><input id="st-enabled" type="checkbox" ${s.enabled==1?'checked':''}> Tabelle aktiviert</label>
        </div>
      </div>
    `;
  }

  function renderColumns(){
    const cols = model.columns.slice().sort((a,b)=>(a.sort_order??100)-(b.sort_order??100) || (a.id??0)-(b.id??0));
    const tableCols = model.physical_columns;
    const tableList = model.all_tables;

    const rows = cols.map((c, idx)=>`
      <tr data-idx="${idx}">
        <td><button type="button" class="up">▲</button><button type="button" class="down">▼</button></td>
        <td><input class="field" value="${esc(c.field)}" disabled></td>
        <td><input class="label" value="${esc(c.label||c.field)}"></td>
        <td>
          <select class="type">
            ${['text','number','date','datetime','bool','select'].map(t=>`<option value="${t}" ${c.type===t?'selected':''}>${t}</option>`).join('')}
          </select>
        </td>
        <td><input class="step" type="text" value="${esc(c.step ?? '')}" placeholder="z.B. 1 oder 0.01"></td>
        <td><textarea class="options_text" placeholder="Optionen (je Zeile)">${esc(c.options_text ?? '')}</textarea></td>
        <td><input class="validate" type="text" value="${esc(c.validate ?? '')}" placeholder="Regex/Rule"></td>
        <td style="text-align:center;"><input class="visible" type="checkbox" ${c.visible? 'checked':''}></td>
        <td><input class="sort_order" type="number" value="${esc(c.sort_order ?? 100)}" style="width:80px"></td>
        <td>
          <details>
            <summary>Relation</summary>
            <div style="display:grid;gap:.25rem;margin-top:.25rem;">
              <label>Ref-Tabelle
                <select class="ref_table">
                  <option value="">—</option>
                  ${tableList.map(t=>`<option value="${esc(t.table_name)}" ${c.ref_table===t.table_name?'selected':''}>${esc(t.table_name)}</option>`).join('')}
                </select>
              </label>
              <label>Ref-Feld
                <input class="ref_field" list="__ref_field_list_${idx}" value="${esc(c.ref_field ?? '')}">
                <datalist id="__ref_field_list_${idx}">${tableCols.map(fc=>`<option value="${esc(fc)}">`).join('')}</datalist>
              </label>
              <label>Ref-Label-Feld
                <input class="ref_label_field" list="__ref_label_field_list_${idx}" value="${esc(c.ref_label_field ?? '')}">
                <datalist id="__ref_label_field_list_${idx}">${tableCols.map(fc=>`<option value="${esc(fc)}">`).join('')}</datalist>
              </label>
            </div>
          </details>
        </td>
      </tr>
    `).join('');

    const el = document.getElementById('st-columns-table');
    el.innerHTML = `
      <div style="overflow:auto;">
        <table style="width:100%;border-collapse:collapse;">
          <thead>
            <tr>
              <th style="border-bottom:1px solid #ddd;padding:.5rem;">Reihenfolge</th>
              <th style="border-bottom:1px solid #ddd;padding:.5rem;">Feld</th>
              <th style="border-bottom:1px solid #ddd;padding:.5rem;">Label</th>
              <th style="border-bottom:1px solid #ddd;padding:.5rem;">Typ</th>
              <th style="border-bottom:1px solid #ddd;padding:.5rem;">Step</th>
              <th style="border-bottom:1px solid #ddd;padding:.5rem;">Optionen</th>
              <th style="border-bottom:1px solid #ddd;padding:.5rem;">Validate</th>
              <th style="border-bottom:1px solid #ddd;padding:.5rem;">Sichtbar</th>
              <th style="border-bottom:1px solid #ddd;padding:.5rem;">Sort</th>
              <th style="border-bottom:1px solid #ddd;padding:.5rem;">Relation</th>
            </tr>
          </thead>
          <tbody>${rows || '<tr><td colspan="10" style="padding:.75rem;color:#666;">Keine Spalten</td></tr>'}</tbody>
        </table>
      </div>
    `;

    // Buttons up/down
    el.querySelectorAll('button.up').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const tr = btn.closest('tr'); const i = +tr.dataset.idx;
        if (i<=0) return;
        [model.columns[i-1], model.columns[i]] = [model.columns[i], model.columns[i-1]];
        // sort_order neu setzen
        model.columns.forEach((c,ix)=> c.sort_order = (ix+1)*10);
        renderColumns();
      });
    });
    el.querySelectorAll('button.down').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const tr = btn.closest('tr'); const i = +tr.dataset.idx;
        if (i>=model.columns.length-1) return;
        [model.columns[i+1], model.columns[i]] = [model.columns[i], model.columns[i+1]];
        model.columns.forEach((c,ix)=> c.sort_order = (ix+1)*10);
        renderColumns();
      });
    });

    // Bind inputs ↔ model
    el.querySelectorAll('tbody tr').forEach((tr)=>{
      const i = +tr.dataset.idx;
      const c = model.columns[i];
      c.label = tr.querySelector('.label').value;
      tr.querySelector('.label').addEventListener('input', e=>{ c.label = e.target.value; });

      c.type  = tr.querySelector('.type').value;
      tr.querySelector('.type').addEventListener('change', e=>{ c.type = e.target.value; });

      c.step  = tr.querySelector('.step').value;
      tr.querySelector('.step').addEventListener('input', e=>{ c.step = e.target.value; });

      c.options_text = tr.querySelector('.options_text').value;
      tr.querySelector('.options_text').addEventListener('input', e=>{ c.options_text = e.target.value; });

      c.validate = tr.querySelector('.validate').value;
      tr.querySelector('.validate').addEventListener('input', e=>{ c.validate = e.target.value; });

      c.visible = tr.querySelector('.visible').checked;
      tr.querySelector('.visible').addEventListener('change', e=>{ c.visible = e.target.checked ? 1 : 0; });

      c.sort_order = parseInt(tr.querySelector('.sort_order').value,10) || (i+1)*10;
      tr.querySelector('.sort_order').addEventListener('input', e=>{ c.sort_order = parseInt(e.target.value,10)||((i+1)*10); });

      c.ref_table = tr.querySelector('.ref_table').value || null;
      tr.querySelector('.ref_table').addEventListener('change', e=>{ c.ref_table = e.target.value || null; });

      c.ref_field = tr.querySelector('.ref_field').value || null;
      tr.querySelector('.ref_field').addEventListener('input', e=>{ c.ref_field = e.target.value || null; });

      c.ref_label_field = tr.querySelector('.ref_label_field').value || null;
      tr.querySelector('.ref_label_field').addEventListener('input', e=>{ c.ref_label_field = e.target.value || null; });
    });
  }

  function addColumnRow(){
    // Neue Spalte (Metadaten) – field muss existieren oder du vergibst virtuelles Label
    model.columns.push({
      id: 0,
      field: prompt('Feldname (physisch in der Tabelle vorhanden)?') || '',
      label: '',
      type: 'text',
      step: null,
      options_text: '',
      validate: '',
      visible: 1,
      sort_order: (model.columns.length+1)*10,
      ref_table: null,
      ref_field: null,
      ref_label_field: null
    });
    renderColumns();
  }

  function saveAll(){
    const sSearch = document.getElementById('st-searchables').value.trim();
    const sDeny   = document.getElementById('st-editdeny').value.trim();
    const softDel = document.getElementById('st-softdelete').checked ? 1 : 0;
    const enabled = document.getElementById('st-enabled').checked ? 1 : 0;

    const payload = {
      table_id: model.table.id,
      settings: {
        columns_json: model.columns.map(c=>c.field),
        searchable_json: sSearch ? sSearch.split(',').map(x=>x.trim()).filter(Boolean) : [],
        editdeny_json:   sDeny ? sDeny.split(',').map(x=>x.trim()).filter(Boolean) : [],
        soft_delete: softDel,
        enabled: enabled
      },
      columns: model.columns.map(c=>({
        id: c.id||0,
        field: c.field,
        label: c.label||c.field,
        type: c.type||'text',
        step: c.step ?? null,
        options_text: c.options_text ?? null,
        validate: c.validate ?? null,
        visible: c.visible?1:0,
        sort_order: c.sort_order ?? 100,
        ref_table: c.ref_table ?? null,
        ref_field: c.ref_field ?? null,
        ref_label_field: c.ref_label_field ?? null
      }))
    };

    fetch(basePath() + '/api/smarttable_settings_save.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {'Content-Type':'application/json'},
      body: JSON.stringify(payload)
    })
    .then(r=>r.json())
    .then(j=>{
      if (!j.ok) throw new Error(j.error||'save_failed');
      alert('Gespeichert.');
      // Nachladen, um IDs/Sortierung zu aktualisieren
      return fetchSettings().then(data=>{ model=data; renderMeta(); renderColumns(); });
    })
    .catch(err=>alert('Fehler beim Speichern: '+err));
  }

  function boot(){
    fetchSettings().then(data=>{
      model = data;
      renderMeta();
      renderColumns();
      document.getElementById('btn-add-col').addEventListener('click', addColumnRow);
      document.getElementById('btn-save').addEventListener('click', saveAll);
    })
    .catch(err=>{
      document.getElementById('st-meta-body').innerHTML =
        `<div style="color:#b00;background:#fee;border:1px solid #f99;padding:.75rem;border-radius:.25rem;white-space:pre-wrap;">
          Fehler: ${esc(err)}
         </div>`;
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
