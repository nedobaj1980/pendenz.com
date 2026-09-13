(function(){
  const $ = (s, r=document)=>r.querySelector(s);
  const $$ = (s, r=document)=>Array.from(r.querySelectorAll(s));

  const tableBody = $('#pendenzen-table tbody');
  const searchInp = $('#pendenzen-search');
  const onlyOpen  = $('#pendenzen-only-open');
  const pagerPrev = $('#pendenzen-prev');
  const pagerNext = $('#pendenzen-next');

  let limit = 20, offset = 0, q = '', openOnly = 0;

  function basePath(){ return location.pathname.startsWith('/pendenz.com/') ? '/pendenz.com' : ''; }

  function load(){
    const url = new URL(basePath() + '/api/pendenzen_list.php', location.origin);
    url.searchParams.set('limit', limit);
    url.searchParams.set('offset', offset);
    if (q) url.searchParams.set('q', q);
    if (openOnly) url.searchParams.set('only_open', '1');

    fetch(url, {credentials:'same-origin'})
      .then(r=>r.json())
      .then(j=>{
        if (!j.ok) throw new Error(j.error||'load_failed');
        render(j.rows);
        pagerPrev.disabled = (offset<=0);
        pagerNext.disabled = (offset + limit >= j.total);
      })
      .catch(err=>{ tableBody.innerHTML = `<tr><td colspan="8" class="muted">Fehler: ${String(err)}</td></tr>`; });
  }

  function esc(s){ return String(s ?? '').replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[m])); }
  function render(rows){
    if (!rows.length) { tableBody.innerHTML = '<tr><td colspan="8" class="muted">Keine Einträge</td></tr>'; return; }
    tableBody.innerHTML = rows.map(r=>`
      <tr data-id="${r.id}">
        <td>${esc(r.id)}</td>
        <td>${esc(r.titel)}</td>
        <td>${esc(r.status)}</td>
        <td>${esc(r.prioritaet ?? '')}</td>
        <td>${esc(r.faellig_am ?? '')}</td>
        <td>${esc(r.zustaendig_name ?? '')}</td>
        <td>${esc(r.projekt_name ?? '')}</td>
        <td style="white-space:nowrap">
          <button class="btn btn-xs edit">Bearb.</button>
          <button class="btn btn-xs del">Löschen</button>
        </td>
      </tr>
    `).join('');

    $$('#pendenzen-table tbody .edit').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const id = btn.closest('tr').dataset.id;
        location.href = `pendenzen.php?id=${encodeURIComponent(id)}`; // deine Detailseite
      });
    });
    $$('#pendenzen-table tbody .del').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const tr = btn.closest('tr'); const id = +tr.dataset.id;
        if (!confirm(`Eintrag #${id} wirklich löschen?`)) return;
        fetch(basePath() + '/api/pendenzen_delete.php', {
          method:'POST', credentials:'same-origin',
          headers:{'Content-Type':'application/json'},
          body: JSON.stringify({id})
        })
        .then(r=>r.json()).then(j=>{
          if (!j.ok) throw new Error(j.error||'delete_failed');
          tr.remove();
        })
        .catch(err=>alert('Fehler: '+err));
      });
    });
  }

  // UI events
  searchInp?.addEventListener('input', ()=>{
    q = searchInp.value.trim(); offset = 0; load();
  });
  onlyOpen?.addEventListener('change', ()=>{
    openOnly = onlyOpen.checked ? 1 : 0; offset = 0; load();
  });
  pagerPrev?.addEventListener('click', ()=>{ offset = Math.max(0, offset - limit); load(); });
  pagerNext?.addEventListener('click', ()=>{ offset = offset + limit; load(); });

  if (tableBody) load();
})();
