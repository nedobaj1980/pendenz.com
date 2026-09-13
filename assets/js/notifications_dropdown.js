// assets/js/notifications_dropdown.js
(function () {
  'use strict';

  const q  = (s, r) => (r || document).querySelector(s);
  const qa = (s, r) => Array.prototype.slice.call((r || document).querySelectorAll(s));
  const esc = s => String(s||'')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');

  async function getJSON(url){
    const res = await fetch(url, { credentials:'same-origin' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  }
  async function postJSON(url, body){
    const res = await fetch(url, {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify(body || {})
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return res.json();
  }

  function ensurePopover(toggle){
    let pop = toggle._notifyPopover;
    if (pop && document.body.contains(pop)) return pop;

    pop = document.createElement('div');
    pop.className = 'notify-popover';
    pop.setAttribute('role','menu');
    pop.setAttribute('aria-hidden','true');
    pop.hidden = true;
    pop.innerHTML = `
      <div class="notify-head">
        <strong>Benachrichtigungen</strong>
        <button class="nbtn nbtn-tiny js-notify-mark-all" type="button">Alle als gelesen</button>
      </div>
      <div class="notify-body"><div class="loading">Lade…</div></div>
      <div class="notify-foot">
        <a class="nbtn nbtn-small w-100 js-notify-all-link" href="#">Alle anzeigen</a>
      </div>`;
    document.body.appendChild(pop);
    toggle._notifyPopover = pop;
    return pop;
  }

  function positionPopover(toggle, pop){
    const r = toggle.getBoundingClientRect();
    const w = 320;
    const h = Math.min(window.innerHeight * 0.6, pop.offsetHeight || 360);
    const margin = 8;

    let left = Math.min(Math.max(r.right - w, margin), window.innerWidth - w - margin);
    let top  = r.bottom + margin;
    if (top + h > window.innerHeight - margin) top = Math.max(r.top - h - margin, margin);

    pop.style.width = w + 'px';
    pop.style.left = Math.round(left) + 'px';
    pop.style.top  = Math.round(top)  + 'px';
    pop.style.maxHeight = Math.floor(window.innerHeight * 0.6) + 'px';
  }

  function renderList(pop, data, pageLink){
    const body = q('.notify-body', pop);
    const items = data.items || [];
    q('.js-notify-all-link', pop).setAttribute('href', pageLink || '#');

    if (!items.length) {
      body.innerHTML = `
        <div class="notify-empty">
          Keine neuen Benachrichtigungen.<br>
          <a class="nbtn nbtn-small" href="${pageLink}">Zur Benachrichtigungs-Seite</a>
        </div>`;
      return;
    }
    body.innerHTML = `
      <ul class="notify-list">
        ${items.map(n=>{
          const A1 = n.link_url ? `<a class="title" href="${n.link_url}">` : `<span class="title">`;
          const A2 = n.link_url ? `</a>` : `</span>`;
          return `
            <li class="notify-item" data-id="${n.id}">
              <div class="icon">🔔</div>
              <div class="content">
                ${A1}${esc(n.message)}${A2}
                <div class="meta">${esc(n.created_at)}</div>
              </div>
              <button class="nbtn nbtn-tiny js-mark-one" type="button" title="Als gelesen markieren">Gelesen</button>
            </li>`;
        }).join('')}
      </ul>`;
  }

  function updateBadge(toggle, unseen){
    const badge = q('.js-notify-badge', toggle);
    if (!badge) return;
    unseen = parseInt(unseen || 0, 10);
    badge.textContent = String(unseen);
    badge.classList.toggle('is-zero', unseen === 0);
  }

  async function load(toggle){
    const pop      = ensurePopover(toggle);
    const listUrl  = toggle.dataset.notifyList;
    const pageLink = toggle.dataset.notifyPage;
    const body     = q('.notify-body', pop);
    body.innerHTML = '<div class="loading">Lade…</div>';

    try {
      const data = await getJSON(listUrl); // { unseen, items[] }
      renderList(pop, data, pageLink);
      updateBadge(toggle, data.unseen);

      qa('.js-mark-one', pop).forEach(btn=>{
        btn.addEventListener('click', async ()=>{
          const id = parseInt(btn.closest('.notify-item').dataset.id, 10);
          try {
            const r = await postJSON(toggle.dataset.notifyMark, { id });
            if (r && r.ok) load(toggle);
          } catch(e){ alert('Fehler: '+e.message); }
        });
      });

      const allBtn = q('.js-notify-mark-all', pop);
      if (allBtn && !allBtn._wired) {
        allBtn._wired = true;
        allBtn.addEventListener('click', async ()=>{
          if (!confirm('Alle Benachrichtigungen als gelesen markieren?')) return;
          try {
            const r = await postJSON(toggle.dataset.notifyMark, { all: true });
            if (r && r.ok) load(toggle);
          } catch(e){ alert('Fehler: '+e.message); }
        });
      }
    } catch (e) {
      body.innerHTML = `<div class="notify-empty">Fehler beim Laden (${esc(e.message)})</div>`;
    }
  }

  function closeOthers(except){
    qa('.notify-popover.open').forEach(p=>{
      if (p !== except) {
        p.classList.remove('open');
        p.setAttribute('aria-hidden','true');
        p.hidden = true;
      }
    });
    qa('.js-notify-toggle[aria-expanded="true"]').forEach(t=>{
      if (!except || t._notifyPopover !== except) t.setAttribute('aria-expanded','false');
      t.removeAttribute('aria-pressed');
      t.classList.remove('is-open');
    });
  }

  function wireToggle(toggle){
    if (toggle._wired) return;
    toggle._wired = true;
    if (!toggle.dataset.notifyList || !toggle.dataset.notifyMark || !toggle.dataset.notifyPage) return;

    const pop = ensurePopover(toggle);

    const open = () => {
      closeOthers(pop);
      load(toggle);
      pop.hidden = false;
      pop.classList.add('open');
      pop.setAttribute('aria-hidden','false');
      toggle.setAttribute('aria-expanded','true');
      toggle.setAttribute('aria-pressed','true');
      toggle.classList.add('is-open');
      requestAnimationFrame(()=> positionPopover(toggle, pop));
    };
    const close = () => {
      pop.classList.remove('open');
      pop.setAttribute('aria-hidden','true');
      pop.hidden = true;
      toggle.setAttribute('aria-expanded','false');
      toggle.removeAttribute('aria-pressed');
      toggle.classList.remove('is-open');
      toggle.focus({ preventScroll: true });
    };

    const onDocClick = (e)=>{
      if (toggle.contains(e.target) || pop.contains(e.target)) return;
      if (pop.classList.contains('open')) close();
    };
    document.addEventListener('click', onDocClick);
    window.addEventListener('keydown', (e)=>{
      if (e.key === 'Escape' && pop.classList.contains('open')) { e.preventDefault(); close(); }
    }, { passive: true });
    window.addEventListener('resize', ()=>{ if (pop.classList.contains('open')) positionPopover(toggle, pop); });
    window.addEventListener('scroll',  ()=>{ if (pop.classList.contains('open')) positionPopover(toggle, pop); }, { passive: true });

    toggle.addEventListener('click', (e)=>{
      if (toggle.tagName === 'A') e.preventDefault();
      pop.classList.contains('open') ? close() : open();
    });
    toggle.addEventListener('keydown', (e)=>{
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pop.classList.contains('open') ? close() : open(); }
    });
  }

  function wireAll(){
    qa('.js-notify-toggle').forEach(t => {
      if (!t.dataset.notifyList || !t.dataset.notifyMark || !t.dataset.notifyPage) return;
      wireToggle(t);
    });
    setInterval(()=>{
      qa('.js-notify-toggle').forEach(async (t)=>{
        if (!t.dataset.notifyList) return;
        try { const d = await getJSON(t.dataset.notifyList); updateBadge(t, d.unseen); } catch(_) {}
      });
    }, 60000);
  }

  document.addEventListener('DOMContentLoaded', wireAll);
})();
