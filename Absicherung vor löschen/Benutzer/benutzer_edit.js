(function () {
  'use strict';

  const ORDER = ['private','internal','project','public'];
  const ICONS = {'private':'🔒','internal':'🏢','project':'👥','public':'🌍'};
  const LABELS= {'private':'Nur ich','internal':'Intern','project':'Projekt','public':'Öffentlich'};

  function nextLevel(curr){
    const idx = ORDER.indexOf(curr);
    return ORDER[(idx + 1) % ORDER.length];
  }

  function initIconToggles() {
    document.querySelectorAll('.icon-toggle').forEach(btn => {
      btn.addEventListener('click', () => {
        const key = btn.getAttribute('data-for');      // z.B. "vorname", "strasse", "profilbild"
        const hidden = document.getElementById('vis_' + key);
        if (!hidden) return;
        const curr = hidden.value || 'private';
        const nxt  = nextLevel(curr);
        hidden.value = nxt;
        btn.textContent = ICONS[nxt];
        btn.title = LABELS[nxt];
        btn.setAttribute('aria-label', LABELS[nxt]);
      });
    });
  }

  function bindRangeNumber(rangeId, numberId, outId, min, max, suffix) {
    const r = document.getElementById(rangeId);
    const n = document.getElementById(numberId);
    const o = outId ? document.getElementById(outId) : null;
    if (!r || !n) return;

    const clamp = v => {
      const num = parseInt(v, 10);
      if (isNaN(num)) return min;
      return Math.min(max, Math.max(min, num));
    };
    const setAll = (v) => {
      const val = clamp(v);
      r.value = val;
      n.value = val;
      if (o) o.textContent = val + (suffix || '');
    };

    setAll(n.value || r.value || min);
    r.addEventListener('input', () => setAll(r.value));
    n.addEventListener('input', () => setAll(n.value));
    n.addEventListener('change', () => setAll(n.value));
  }

  initIconToggles();
  bindRangeNumber('profilbild_max_size',      'profilbild_max_size_num',  null, 80, 400, 'px');
  bindRangeNumber('firmenlogo_max_size',      'firmenlogo_max_size_num',  null, 80, 400, 'px');
  bindRangeNumber('titelbild_max_height',     'titelbild_max_height_num', null, 100, 800, 'px');
})();
