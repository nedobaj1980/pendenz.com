(function () {
  'use strict';

  const q  = (s, r) => (r || document).querySelector(s);
  const qa = (s, r) => Array.prototype.slice.call((r || document).querySelectorAll(s));
  const esc = s => String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');

  function parseLocal(ymdhm) {
    // API liefert 'Y-m-d H:i'
    if (!ymdhm) return null;
    // Kompatibel parsen: 'YYYY-MM-DD HH:mm' -> 'YYYY-MM-DDTHH:mm:00'
    const iso = ymdhm.replace(' ', 'T') + ':00';
    const d = new Date(iso);
    return isNaN(d.getTime()) ? null : d;
  }

  function dayBucket(d) {
    if (!d) return 'Älter';
    const today = new Date(); today.setHours(0,0,0,0);
    const theDay = new Date(d); theDay.setHours(0,0,0,0);
    const diff = (today - theDay) / 86400000;
    if (diff === 0) return 'Heute';
    if (diff === 1) return 'Gestern';
    return theDay.toLocaleDateString();
  }

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

  function render(app, data, filter) {
    const items = (data.items || []).slice();
    const term = (q('#notifySearch').value || '').trim().toLowerCase();

    const filtered = items.filter(n => {
      // simple text filter
      const hay = `${n.message||''} ${n.created_at||''}`.toLowerCase();
      if (term && !hay.includes(term)) return false;

      if (filter === 'link')   return !!n.link_url;
      if (filter === 'nolink') return !n.link_url;
      return true;
    });

    if (!filtered.length) {
      app.innerHTML = `
        <div class="empty">
          <p>Keine Benachrichtigungen gefunden.</p>
          <p class="muted">Passe die Suche oder Ansicht an.</p>
          <p><a class="btn btn-small" href="${app.dataset.endpointList}">API prüfen</a></p>
        </div>`;
      return;
    }

    // Gruppierung nach Tag (Heute / Gestern / Datum)
    const groups = {};
    filtered.forEach(n => {
      const d = parseLocal(n.created_at);
      const k = dayBucket(d);
      (groups[k] = groups[k] || []).push(n);
    });

    const order = Object.keys(groups).sort((a,b) => {
      // Versuche, echte Daten (nicht Heute/Gestern) korrekt zu sortieren
      const map = { 'Heute': 0, 'Gestern': 1 };
      const av = a in map ? map[a] : 2;
      const bv = b in map ? map[b] : 2;
      if (av !== bv) return av - bv;
      // Fallback: neuere Daten zuerst
      return 0;
    });

    app.innerHTML = order.map(g => {
      const rows = groups[g].map(n => {
        const linkOpen  = n.link_url ? `<a class="title" href="${n.link_url}">` : `<span class="title">`;
        const linkClose = n.link_url ? `</a>` : `</span>`;
        return `
          <article class="notify-item" data-id="${n.id}">
            <div class="icon">🔔</div>
            <div class="content">
              ${linkOpen}${esc(n.message)}${linkClose}
              <div class="meta">${esc(n.created_at)}</div>
            </div>
            <button class="btn btn-small js-mark-one" type="button" title="Als gelesen markieren">Gelesen</button>
          </article>`;
      }).join('');
      return `
        <h2 class="group-head">${esc(g)}</h2>
        <div class="notify-group">${rows}</div>`;
    }).join('');

    // Row-Buttons binden
    qa('.js-mark-one', app).forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = parseInt(btn.closest('.notify-item').dataset.id, 10);
        try {
          const r = await postJSON(app.dataset.endpointMark, { id });
          if (r && r.ok) refresh();
        } catch (e) {
          alert('Konnte nicht markieren: ' + e.message);
        }
      });
    });
  }

  let app, viewFilter = 'all', cache = null, loading = false;

  async function refresh() {
    if (loading) return;
    loading = true;
    app.innerHTML = '<div class="loading">Lade…</div>';
    try {
      const data = await getJSON(app.dataset.endpointList); // { unseen, items[] }
      cache = data;
      render(app, data, viewFilter);
    } catch (e) {
      app.innerHTML = `<div class="error">Fehler beim Laden (${esc(e.message)})</div>`;
    } finally {
      loading = false;
    }
  }

  function wire() {
    app = q('#notifications-app');
    if (!app) return;

    // Refresh & Mark-All Buttons
    const btnRefresh = q('.js-refresh');
    const btnAll = q('.js-mark-all');
    if (btnRefresh) btnRefresh.addEventListener('click', refresh);
    if (btnAll) btnAll.addEventListener('click', async () => {
      if (!confirm('Alle Benachrichtigungen als gelesen markieren?')) return;
      try {
        const r = await postJSON(app.dataset.endpointMark, { all: true });
        if (r && r.ok) refresh();
      } catch (e) { alert('Konnte nicht markieren: ' + e.message); }
    });

    // Suche live filtern (client-seitig)
    const search = q('#notifySearch');
    if (search) search.addEventListener('input', () => cache && render(app, cache, viewFilter));

    // View-Toggles
    qa('.notify-toolbar [data-view]').forEach(btn => {
      btn.addEventListener('click', () => {
        qa('.notify-toolbar [data-view]').forEach(b => b.classList.remove('is-active'));
        btn.classList.add('is-active');
        viewFilter = btn.dataset.view || 'all';
        cache && render(app, cache, viewFilter);
      });
    });

    // Tastenkürzel: R = Refresh
    document.addEventListener('keydown', (e) => {
      if (e.key.toLowerCase() === 'r' && !e.metaKey && !e.ctrlKey && !e.altKey) {
        e.preventDefault();
        refresh();
      }
    });

    refresh();
  }

  document.addEventListener('DOMContentLoaded', wire);
})();
