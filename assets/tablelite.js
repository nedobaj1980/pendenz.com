// TableLite: einfache Suche, Sortieren, Paging – Vanilla JS
(function(){
  function enhance(table, {pageSize=20, searchInput=null}={}){
    const tbody = table.tBodies[0]; if(!tbody) return;
    let rows = Array.from(tbody.rows);
    let view = rows.slice();
    let sortIdx = -1, sortDir = 1;
    let page = 1;

    function render(){
      const start = (page-1)*pageSize;
      const slice = view.slice(start, start+pageSize);
      tbody.innerHTML = "";
      slice.forEach(tr => tbody.appendChild(tr));
      pager.textContent = `${slice.length ? start+1 : 0}–${start+slice.length} / ${view.length}`;
      prev.disabled = page===1;
      next.disabled = start+pageSize >= view.length;
    }

    function applySearch(q){
      q = (q||"").toLowerCase();
      view = rows.filter(tr => tr.textContent.toLowerCase().includes(q));
      page = 1;
      if (sortIdx >= 0) applySort(sortIdx, false);
      render();
    }

    function applySort(idx, toggle=true){
      if (toggle) sortDir = (sortIdx===idx) ? -sortDir : 1;
      sortIdx = idx;
      view.sort((a,b)=>{
        const ta = a.cells[idx]?.textContent.trim() ?? "";
        const tb = b.cells[idx]?.textContent.trim() ?? "";
        const na = +ta.replace(/\s/g,''); const nb = +tb.replace(/\s/g,'');
        const isNum = !Number.isNaN(na) && !Number.isNaN(nb);
        const cmp = isNum ? (na-nb) : ta.localeCompare(tb, undefined, {numeric:true});
        return cmp * sortDir;
      });
      page = 1; render();
      // Kopf markieren
      Array.from(table.tHead?.rows[0]?.cells||[]).forEach((th,i)=>{
        th.dataset.sort = (i===idx ? (sortDir>0?'asc':'desc') : '');
      });
    }

    // Kopf-Events
    if (table.tHead) {
      Array.from(table.tHead.rows[0].cells).forEach((th, i)=>{
        th.style.cursor = "pointer";
        th.addEventListener('click', ()=> applySort(i));
      });
    }

    // Search
    if (searchInput) {
      searchInput.addEventListener('input', () => applySearch(searchInput.value));
    }

    // Pager
    const bar = document.createElement('div');
    bar.className = 'tl-bar';
    const prev = Object.assign(document.createElement('button'), {textContent:'‹', className:'tl-btn'});
    const next = Object.assign(document.createElement('button'), {textContent:'›', className:'tl-btn'});
    const pager = Object.assign(document.createElement('span'), {className:'tl-info'});
    prev.addEventListener('click', ()=>{ if(page>1){ page--; render(); }});
    next.addEventListener('click', ()=>{ if(page*pageSize<view.length){ page++; render(); }});
    bar.append(prev, pager, next);
    table.parentElement.insertBefore(bar, table);

    // initial
    applySearch(searchInput?.value||'');
  }

  // Auto-init via data-attribute
  document.addEventListener('DOMContentLoaded', ()=>{
    document.querySelectorAll('table[data-tablelite]').forEach(tbl=>{
      const searchSel = tbl.getAttribute('data-search') || '';
      const search = searchSel ? document.querySelector(searchSel) : null;
      const pageSize = parseInt(tbl.getAttribute('data-pagesize')||'20',10);
      enhance(tbl, {pageSize, searchInput: search});
    });
  });
})();
