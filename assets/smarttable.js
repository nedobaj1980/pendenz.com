// SmartTable – Vollausbau (ohne externe Libraries)
// Features: Sort, Multi-Filter, Inline-Edit (text|number|select|date|datetime|bool),
// Drag&Drop-Reorder, Column Toggle, Resize, CSV-Export, Keyboard nav, Global search.

(() => {
  const $$ = (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel));
  const $  = (sel, ctx=document) => ctx.querySelector(sel);

  // Small helpers
  const normStr = v => (v??'').toString().trim();
  const toBool = v => v===true || v==='1' || v===1 || v==='true' || v==='on';
  const parseDate = v => v && v.length>=10 ? v.slice(0,10) : '';
  const parseDateTimeLocal = v => {
    // Accept "YYYY-MM-DD HH:MM:SS" and "YYYY-MM-DDTHH:MM"
    if (!v) return '';
    if (v.includes('T')) return v.slice(0,16);
    if (v.includes(' ')) return v.slice(0,16).replace(' ','T');
    return v;
  };
  const dtLocalToMySQL = v => v ? v.replace('T',' ') + (v.length===16?':00':'') : null;

  const saveStatus = (wrap, msg, ok=true) => {
    const bar = wrap.querySelector('.sdash-savebar');
    const span = wrap.querySelector('.sdash-save-status');
    if (!bar || !span) return;
    span.textContent = msg;
    bar.classList.remove('ok','err');
    bar.classList.add(ok?'ok':'err');
    bar.style.display = 'block';
    clearTimeout(bar._t);
    bar._t = setTimeout(()=>{ bar.style.display='none'; }, 2000);
  };

  const buildFilterRow = (table, cols) => {
    // insert a <tr> with inputs below the header
    const thead = table.tHead;
    const tr = document.createElement('tr');
    tr.className = 'st-filter-row';
    cols.forEach(col => {
      const th = document.createElement('th');
      if (!col.field) { th.innerHTML = ''; tr.appendChild(th); return; }
      if (col.type==='select' && Array.isArray(col.options)) {
        const sel = document.createElement('select');
        const optAll = document.createElement('option'); optAll.value=''; optAll.textContent='(alle)';
        sel.appendChild(optAll);
        col.options.forEach(o => {
          const opt = document.createElement('option'); opt.value=o; opt.textContent=o; sel.appendChild(opt);
        });
        sel.addEventListener('change', () => applyFilters(table));
        th.appendChild(sel);
      } else if (col.type==='bool') {
        const sel = document.createElement('select');
        sel.innerHTML = `<option value="">(alle)</option><option value="1">Ja</option><option value="0">Nein</option>`;
        sel.addEventListener('change', () => applyFilters(table));
        th.appendChild(sel);
      } else if (col.type==='date' || col.type==='datetime') {
        // Support >= and <= via two inputs
        const from = document.createElement('input'); from.type='text'; from.placeholder='>= YYYY-MM-DD';
        const to   = document.createElement('input'); to.type='text';   to.placeholder='<= YYYY-MM-DD';
        from.className='st-filter st-filter--range'; to.className='st-filter st-filter--range';
        from.addEventListener('input', ()=>applyFilters(table));
        to.addEventListener('input', ()=>applyFilters(table));
        th.append(from, to);
      } else {
        const inp = document.createElement('input');
        inp.type='search'; inp.placeholder='filtern…'; inp.className='st-filter';
        inp.addEventListener('input', () => applyFilters(table));
        th.appendChild(inp);
      }
      tr.appendChild(th);
    });
    thead.appendChild(tr);
  };

  const getColumns = table => {
    const ths = Array.from(table.tHead.rows[0].cells);
    return ths.map(th => ({
      el: th,
      field: th.dataset.field || null,
      type: th.dataset.type || 'text',
      options: (()=>{ try { return JSON.parse(th.dataset.options||'null') } catch{ return null } })(),
      validate: (th.dataset.validate||'').split('|').filter(Boolean),
      sortable: !th.hasAttribute('data-nosort')
    }));
  };

  const compareByType = (a,b,type) => {
    if (type==='number') {
      const na = parseFloat(a.replace(',','.'))||0, nb=parseFloat(b.replace(',','.'))||0;
      return na-nb;
    }
    if (type==='date' || type==='datetime') {
      return (a||'').localeCompare(b||'');
    }
    if (type==='bool') {
      return (toBool(a)?1:0)-(toBool(b)?1:0);
    }
    return normStr(a).localeCompare(normStr(b), undefined, {numeric:true, sensitivity:'base'});
  };

  const sortTable = (table, colIdx, dir) => {
    const tbody = table.tBodies[0];
    const rows = Array.from(tbody.rows);
    const cols = getColumns(table);
    const type = cols[colIdx]?.type || 'text';

    rows.sort((r1,r2) => {
      const a = r1.cells[colIdx]?.textContent?.trim() ?? '';
      const b = r2.cells[colIdx]?.textContent?.trim() ?? '';
      const c = compareByType(a,b,type);
      return dir==='asc' ? c : -c;
    });
    rows.forEach(r => tbody.appendChild(r));

    // update sort UI
    cols.forEach((c,i) => c.el.dataset.sort = (i===colIdx?dir:''));
  };

  const applyFilters = (table) => {
    const wrap = table.closest('.sdash-wrap');
    const globalSel = table.dataset.search;
    const global = globalSel ? $(globalSel) : null;
    const cols = getColumns(table);
    const fRow = table.tHead.querySelector('.st-filter-row');

    $$('tbody tr', table).forEach(tr => {
      let ok = true;
      // global search
      if (ok && global && normStr(global.value)) {
        const needle = normStr(global.value).toLowerCase();
        const hay = tr.textContent.toLowerCase();
        ok = hay.includes(needle);
      }
      // per-col filters
      if (ok && fRow) {
        cols.forEach((col,i) => {
          if (!ok || !col.field) return;
          const cellTxt = normStr(tr.cells[i]?.textContent).toLowerCase();
          const fCell = fRow.cells[i];
          if (!fCell) return;

          if (col.type==='select' || col.type==='bool') {
            const sel = fCell.querySelector('select');
            if (sel && sel.value !== '') {
              if (col.type==='bool') {
                const want = sel.value === '1' ? '1' : '0';
                if (!['1','0','ja','nein','true','false'].includes(cellTxt)) ok=false;
                else {
                  const val = (cellTxt.startsWith('1')||cellTxt.startsWith('j')||cellTxt.startsWith('t'))?'1':'0';
                  if (val !== want) ok=false;
                }
              } else {
                if (cellTxt !== sel.value.toLowerCase()) ok=false;
              }
            }
          } else if (col.type==='date' || col.type==='datetime') {
            const [from,to] = fCell.querySelectorAll('input');
            const val = (col.type==='datetime') ? (tr.cells[i]?.dataset.valueRaw || parseDateTimeLocal(cellTxt)) : parseDate(cellTxt);
            if (from && normStr(from.value)) {
              const f = from.value.replace('>=','').trim();
              if (val < f) ok=false;
            }
            if (ok && to && normStr(to.value)) {
              const t = to.value.replace('<=','').trim();
              if (val > t) ok=false;
            }
          } else {
            const inp = fCell.querySelector('input');
            if (inp && normStr(inp.value)) {
              const n = normStr(inp.value).toLowerCase();
              if (!cellTxt.includes(n)) ok=false;
            }
          }
        });
      }
      tr.style.display = ok ? '' : 'none';
    });
  };

  const validator = (rules, type, raw) => {
    const v = normStr(raw);
    for (const r of rules) {
      if (r==='required' && !v) return 'Pflichtfeld';
      if (r.startsWith('number')) {
        if (isNaN(parseFloat(v.replace(',','.')))) return 'Zahl erwartet';
      }
      if (r.startsWith('min:')) {
        const m = parseFloat(r.split(':')[1]);
        const x = parseFloat(v.replace(',','.'));
        if (isNaN(x) || x < m) return `≥ ${m}`;
      }
      if (r==='date' && !/^\d{4}-\d{2}-\d{2}$/.test(v)) return 'YYYY-MM-DD';
    }
    // type-level
    if (type==='date' && v && !/^\d{4}-\d{2}-\d{2}$/.test(v)) return 'YYYY-MM-DD';
    if (type==='datetime' && v && !/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/.test(v)) return 'YYYY-MM-DDTHH:MM';
    return null;
  };

  const makeEditor = (col, txt) => {
    const t = col.type;
    if (t==='select' && Array.isArray(col.options)) {
      const sel = document.createElement('select');
      col.options.forEach(o => {
        const opt = document.createElement('option');
        opt.value=o; opt.textContent=o;
        if (normStr(txt)===normStr(o)) opt.selected=true;
        sel.appendChild(opt);
      });
      return sel;
    }
    if (t==='bool') {
      const sel = document.createElement('select');
      sel.innerHTML = `<option value="0">Nein</option><option value="1">Ja</option>`;
      const on = ['1','ja','true','on'].includes(normStr(txt).toLowerCase());
      sel.value = on ? '1' : '0';
      return sel;
    }
    if (t==='number') {
      const inp = document.createElement('input'); inp.type='number'; inp.value = txt; return inp;
    }
    if (t==='date') {
      const inp = document.createElement('input'); inp.type='date'; inp.value = parseDate(txt); return inp;
    }
    if (t==='datetime') {
      const inp = document.createElement('input'); inp.type='datetime-local'; inp.value = parseDateTimeLocal(txt); return inp;
    }
    const inp = document.createElement('input'); inp.type='text'; inp.value = txt; return inp;
  };

  const apiCall = async (wrap, payload) => {
    const csrf = wrap?.dataset?.csrf || '';
    const res = await fetch('/pendenz.com/pages/api_smarttable.php', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
      body: JSON.stringify(payload)
    });
    const data = await res.json().catch(()=>({ok:false,error:'Bad JSON'}));
    if (!res.ok || !data.ok) throw new Error(data.error||res.statusText);
    return data;
  };

  const commitCell = async (table, td, col, newVal) => {
    const wrap = table.closest('.sdash-wrap');
    const idField = table.dataset.idField || 'id';
    const id = td.parentElement.dataset.id;
    if (!col.field || !id) return;

    // validation
    const err = validator(col.validate||[], col.type, newVal);
    if (err) { saveStatus(wrap, `❌ ${col.field}: ${err}`, false); throw new Error(err); }

    // build normalized for server
    let sendVal = newVal;
    if (col.type==='bool') sendVal = toBool(newVal) ? 1 : 0;
    if (col.type==='date') sendVal = newVal || null;
    if (col.type==='datetime') sendVal = newVal ? dtLocalToMySQL(newVal) : null;

    // send
    saveStatus(wrap, 'Speichern…', true);
    const data = await apiCall(wrap, {
      action: 'update_cell',
      table: table.dataset.table,
      id_field: idField,
      id, field: col.field, value: sendVal, type: col.type
    });

    // render normalized received value
    const showVal = (()=>{
      if (col.type==='bool') return data.value ? '1' : '0';
      if (col.type==='date') return data.value || '';
      if (col.type==='datetime') {
        td.dataset.valueRaw = data.value||'';
        return data.value ? data.value.replace(' ','T').slice(0,16) : '';
      }
      return data.value ?? newVal;
    })();
    td.textContent = showVal;
    saveStatus(wrap, '✓ Gespeichert', true);
  };

  const enableInlineEdit = (table) => {
    const cols = getColumns(table);
    table.addEventListener('click', ev => {
      // guard links, chips, etc
      if (ev.target.tagName === 'A' || ev.target.classList.contains('status-chip') || ev.target.closest('.star-rating')) return;
      const td = ev.target.closest('td');
      if (!td || td.cellIndex==null) return;
      const col = cols[td.cellIndex]; if (!col || !col.field) return;
      if (td.classList.contains('editing')) return;

      const old = td.textContent;
      td.classList.add('editing');
      const editor = makeEditor(col, old);
      editor.className = 'st-editor';
      td.textContent=''; td.appendChild(editor);
      editor.focus();
      if (editor.select) editor.select();

      const finish = async (accept) => {
        td.classList.remove('editing');
        const newVal = editor.value;
        td.innerHTML='';
        if (!accept || normStr(newVal)===normStr(old)) { td.textContent=old; return; }
        try { await commitCell(table, td, col, newVal); }
        catch { td.textContent = old; }
      };

      editor.addEventListener('keydown', e => {
        if (e.key==='Enter') { e.preventDefault(); finish(true); }
        if (e.key==='Escape') { e.preventDefault(); finish(false); }
      });
      editor.addEventListener('blur', ()=>finish(true));
    });

    // Keyboard nav (arrows to move focusable)
    table.addEventListener('keydown', e => {
      const cell = e.target.closest('td,th');
      if (!cell || cell.tagName==='TH') return;
      const r = cell.parentElement.rowIndex; // includes thead rows
      const c = cell.cellIndex;
      const mv = (nr,nc) => {
        const tr = table.rows[nr]; if (!tr) return;
        const nd = tr.cells[nc]; if (!nd) return;
        nd.focus();
      };
      if (e.key==='ArrowRight' || e.key==='Tab') { 
        if (e.shiftKey && e.key==='Tab') { /* default shift-tab */ }
        else { e.preventDefault(); mv(r, c+1); }
      }
      if (e.key==='ArrowLeft')  { e.preventDefault(); mv(r, c-1); }
      if (e.key==='ArrowUp')    { e.preventDefault(); mv(r-1, c); }
      if (e.key==='ArrowDown')  { e.preventDefault(); mv(r+1, c); }
      if (e.key==='Enter')      { 
        if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'SELECT' && e.target.tagName !== 'TEXTAREA') {
          e.preventDefault(); 
          e.target.click(); 
        }
      }
    });
    // Make cells focusable
    $$('tbody td', table).forEach(td => { if(!td.tabIndex) td.tabIndex=0; });
  };

  const enableSort = (table) => {
    const cols = getColumns(table);
    cols.forEach((col,idx) => {
      if (!col.sortable) return;
      col.el.classList.add('st-sortable');
      col.el.addEventListener('click', () => {
        const cur = col.el.dataset.sort || '';
        const dir = cur==='asc' ? 'desc' : 'asc';
        sortTable(table, idx, dir);
      });
    });
  };

  const enableReorder = (table) => {
    const orderField = table.dataset.orderField;
    if (!orderField) return;
    const wrap = table.closest('.sdash-wrap');

    $$('tbody tr', table).forEach(tr => {
      tr.draggable = true;
      tr.addEventListener('dragstart', e => {
        if (!e.target.querySelector('.reorder-handle') || !e.target.querySelector('.reorder-handle').contains(e.target)) {
          // only start when grabbing the handle (fallback if browser ignores)
        }
        e.dataTransfer.setData('text/plain', tr.dataset.id);
        tr.classList.add('dragging');
      });
      tr.addEventListener('dragend', ()=> tr.classList.remove('dragging'));
    });

    table.tBodies[0].addEventListener('dragover', e => {
      e.preventDefault();
      const dragging = table.querySelector('tr.dragging'); if (!dragging) return;
      const after = getDragAfterRow(table, e.clientY);
      if (after == null) table.tBodies[0].appendChild(dragging);
      else table.tBodies[0].insertBefore(dragging, after);
    });

    const getDragAfterRow = (table, y) => {
      const rows = [...$$('tbody tr:not(.dragging)', table)];
      return rows.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;
        if (offset < 0 && offset > closest.offset) return {offset, element: child};
        else return closest;
      }, {offset: Number.NEGATIVE_INFINITY}).element;
    };

    table.tBodies[0].addEventListener('drop', async () => {
      // persist order
      const ids = $$('tbody tr', table).map((tr, i) => ({id: tr.dataset.id, pos: i+1}));
      try {
        saveStatus(wrap, 'Reihenfolge speichern…', true);
        await apiCall(wrap, {
          action:'reorder_rows',
          table: table.dataset.table,
          id_field: table.dataset.idField || 'id',
          order_field: orderField,
          rows: ids
        });
        saveStatus(wrap, '✓ Reihenfolge gespeichert', true);
      } catch(e) {
        saveStatus(wrap, '❌ Reihenfolge konnte nicht gespeichert werden', false);
      }
    });
  };

  const addToolbarButtons = (table) => {
    const wrap = table.closest('.sdash-wrap');
    const toolbar = wrap.querySelector('.sdash-toolbar');
    if (!toolbar) return;

    // CSV export
    const btnCsv = document.createElement('button');
    btnCsv.type='button'; btnCsv.className='st-btn';
    btnCsv.textContent='CSV exportieren';
    btnCsv.addEventListener('click', ()=> exportCSV(table));
    toolbar.appendChild(btnCsv);

    // Column toggle
    const btnCols = document.createElement('button');
    btnCols.type='button'; btnCols.className='st-btn';
    btnCols.textContent='Spalten';
    const menu = document.createElement('div'); menu.className='st-colmenu';
    const cols = getColumns(table);
    cols.forEach((col, i) => {
      const lab = document.createElement('label');
      const cb = document.createElement('input'); cb.type='checkbox'; cb.checked=true;
      cb.addEventListener('change', ()=> toggleColumn(table, i, cb.checked));
      lab.append(cb, document.createTextNode(' '+(col.el.textContent||col.field||`Spalte ${i+1}`)));
      menu.appendChild(lab);
    });
    const holder = document.createElement('span'); holder.className='st-colmenu-wrap';
    holder.append(btnCols, menu);
    btnCols.addEventListener('click', ()=> menu.classList.toggle('open'));
    toolbar.appendChild(holder);
  };

  const toggleColumn = (table, idx, show) => {
    (table.tHead.rows[0].cells[idx]||{}).style.display = show?'':'none';
    const fRow = table.tHead.querySelector('.st-filter-row');
    if (fRow) (fRow.cells[idx]||{}).style.display = show?'':'none';
    $$('tbody tr', table).forEach(tr=>{
      (tr.cells[idx]||{}).style.display = show?'':'none';
    });
  };

  const exportCSV = (table) => {
    const rows = [];
    const visibleIdx = [];
    // header
    const ths = Array.from(table.tHead.rows[0].cells);
    rows.push(ths.map((th,i) => {
      if (th.style.display==='none') return null;
      visibleIdx.push(i);
      return '"' + (th.textContent||'').replace(/"/g,'""') + '"';
    }).filter(v=>v!=null).join(','));

    // body (only visible rows)
    $$('tbody tr', table).forEach(tr => {
      if (tr.style.display==='none') return;
      const cols = visibleIdx.map(i => {
        const txt = tr.cells[i]?.textContent || '';
        return '"' + txt.replace(/"/g,'""') + '"';
      });
      rows.push(cols.join(','));
    });

    const blob = new Blob([rows.join('\r\n')], {type:'text/csv;charset=utf-8;'});
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = 'export.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  };

  const enableResize = (table) => {
    const ths = Array.from(table.tHead.rows[0].cells);
    ths.forEach(th => {
      const grip = document.createElement('span');
      grip.className='st-resize-grip';
      th.style.position='relative';
      th.appendChild(grip);
      let startX, startW;
      grip.addEventListener('mousedown', e => {
        startX = e.clientX; startW = th.offsetWidth;
        document.body.classList.add('st-resizing');
        const mm = e2 => {
          const dx = e2.clientX - startX;
          th.style.width = Math.max(60, startW + dx) + 'px';
        };
        const up = async () => {
          document.removeEventListener('mousemove', mm);
          document.removeEventListener('mouseup', up);
          document.body.classList.remove('st-resizing');
          
          // NEU: Breite speichern
          const field = th.dataset.field;
          const tableId = table.dataset.table;
          if (field && tableId) {
            try {
              await apiCall(wrap, { action: 'save_width', table: tableId, field: field, width: th.style.width });
            } catch(e) { console.warn('Width save failed', e); }
          }
        };
        document.addEventListener('mousemove', mm);
        document.addEventListener('mouseup', up);
      });
    });
  };

  // Boot
  window.addEventListener('DOMContentLoaded', () => {
    $$('table[data-smarttable]').forEach(table => {
      const cols = getColumns(table);
      buildFilterRow(table, cols);
      enableInlineEdit(table);
      enableSort(table);
      enableReorder(table);
      enableResize(table);
      addToolbarButtons(table);

      // global search hookup
      if (table.dataset.search) {
        const g = $(table.dataset.search);
        if (g) g.addEventListener('input', ()=>applyFilters(table));
      }
      applyFilters(table);
    });
  });
})();
