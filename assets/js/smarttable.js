(function(){
  function qs(name){ return new URLSearchParams(location.search).get(name); }
  function basePath(){ return (location.pathname.startsWith('/pendenz.com/')) ? '/pendenz.com' : ''; }
  function escapeHtml(s){ return String(s).replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
  function debounce(fn,ms){ let h; return (...a)=>{ clearTimeout(h); h=setTimeout(()=>fn(...a),ms); }; }

  function boot(){
    const elTable = document.getElementById('st-table');
    const elSearch = document.getElementById('st-search');
    const elPageSize = document.getElementById('st-pagesize');
    const elPager = document.getElementById('st-pagination');
    if (!elTable) return;

    const state = {
      table: qs('table') || 'pendenzen',
      table_id: parseInt(qs('table_id')||'0',10) || 0,
      project_id: parseInt(qs('project_id')||'0',10) || 0,
      page: parseInt(qs('page')||'1',10) || 1,
      page_size: parseInt(elPageSize?.value||'20',10) || 20,
      search: '',
      order: [],
      filters: []
    };

    function apiUrl(){
      const url = new URL(basePath() + '/api/smarttable_fetch.php', location.origin);
      if (state.table_id)   url.searchParams.set('table_id', state.table_id);
      if (state.table)      url.searchParams.set('table', state.table);
      if (state.project_id) url.searchParams.set('project_id', state.project_id);
      url.searchParams.set('page', state.page);
      url.searchParams.set('page_size', state.page_size);
      if (state.search) url.searchParams.set('search', state.search);
      url.searchParams.set('order', JSON.stringify(state.order));
      url.searchParams.set('filters', JSON.stringify(state.filters));
      return url;
    }

    function fetchData(){
      const url = apiUrl();
      console.log('[SmartTable] fetch', url.toString());
      return fetch(url, {credentials:'same-origin'}).then(async r=>{
        const txt = await r.text();
        try {
          const js = JSON.parse(txt);
          if (js.error) throw new Error(js.error + (js.message ? ' | '+js.message : ''));
          return js;
        } catch(e){
          // Zeige ersten Teil der Antwort zur Diagnose
          throw new Error('Keine gültige JSON-Antwort:\n' + txt.slice(0,400));
        }
      });
    }

    function render(data){
      const visible = data.columns.filter(c=>c.visible);
      let html = '<div style="overflow:auto;"><table style="width:100%;border-collapse:collapse;">';
      html += '<thead><tr>';
      html += visible.map(c=>`<th style="text-align:left;border-bottom:1px solid #ddd;padding:.5rem;">${escapeHtml(c.label)}</th>`).join('');
      html += '</tr></thead><tbody>';
      if (!data.rows.length) {
        html += `<tr><td colspan="${visible.length}" style="padding:.75rem;color:#666;">Keine Daten</td></tr>`;
      } else {
        for (const row of data.rows) {
          html += '<tr>';
          for (const c of visible) {
            let v = row[c.field]; if (v==null) v='';
            html += `<td style="border-bottom:1px solid #f0f0f0;padding:.5rem;">${escapeHtml(v)}</td>`;
          }
          html += '</tr>';
        }
      }
      html += '</tbody></table></div>';
      elTable.innerHTML = html;

      const pages = Math.max(1, Math.ceil((data.total||0) / data.page_size));
      let phtml = '';
      if (pages > 1) {
        phtml += `<button type="button" data-p="prev" ${state.page<=1?'disabled':''}>&laquo;</button>`;
        phtml += `<span>Seite ${state.page} / ${pages}</span>`;
        phtml += `<button type="button" data-p="next" ${state.page>=pages?'disabled':''}>&raquo;</button>`;
      } else {
        phtml = `<span>${data.total||0} Einträge</span>`;
      }
      elPager.innerHTML = phtml;
    }

    function showErr(err){
      console.error('[SmartTable] Fehler', err);
      document.getElementById('st-pagination').innerHTML = '';
      document.getElementById('st-table').innerHTML =
        `<div style="color:#b00;background:#fee;border:1px solid #f99;padding:.75rem;border-radius:.25rem;white-space:pre-wrap;">
          Fehler: ${escapeHtml(err.message||String(err))}
        </div>`;
    }

    // Events
    elSearch?.addEventListener('input', debounce(()=>{
      state.search = elSearch.value.trim(); state.page=1;
      fetchData().then(render).catch(showErr);
    }, 300));
    elPageSize?.addEventListener('change', ()=>{
      state.page_size = parseInt(elPageSize.value,10)||20; state.page=1;
      fetchData().then(render).catch(showErr);
    });
    elPager?.addEventListener('click', (e)=>{
      const b = e.target.closest('button'); if(!b) return;
      const p = b.getAttribute('data-p');
      if (p==='prev' && state.page>1) state.page--;
      if (p==='next') state.page++;
      fetchData().then(render).catch(showErr);
    });

    // Start
    fetchData().then(render).catch(showErr);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
