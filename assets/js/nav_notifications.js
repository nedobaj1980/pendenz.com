(function(){
  const wrap = document.getElementById('navNotify');
  if (!wrap) return;

  const btn   = wrap.querySelector('.notify-btn');
  const menu  = document.getElementById('notifyMenu');
  const list  = document.getElementById('notifyList');
  const badge = document.getElementById('notifyBadge');
  const markAllBtn = document.getElementById('notifyMarkAll');

  const END_LIST = wrap.dataset.endpointList;
  const END_MARK = wrap.dataset.endpointMark;

  function setBadge(n){
    if (n > 0) {
      badge.textContent = String(n);
      badge.hidden = false;
      badge.classList.add('blink');
      btn.setAttribute('aria-label', `Benachrichtigungen (${n} neu)`);
    } else {
      badge.hidden = true;
      badge.classList.remove('blink');
      btn.setAttribute('aria-label', 'Benachrichtigungen');
    }
  }

  async function fetchList(){
    try{
      const res = await fetch(END_LIST, {credentials:'same-origin'});
      const data = await res.json();
      setBadge(data.unseen || 0);
      const items = Array.isArray(data.items) ? data.items : [];
      if (!items.length) {
        list.innerHTML = '<li class="empty">Keine neuen Benachrichtigungen.</li>';
      } else {
        list.innerHTML = items.map(it => {
          const link = it.link_url ? `<a href="${it.link_url}">${escapeHtml(it.message)}</a>` : `<span>${escapeHtml(it.message)}</span>`;
          const time = it.created_at ? `<span class="time">${escapeHtml(it.created_at)}</span>` : '';
          return `<li data-id="${it.id}">${link}${time}</li>`;
        }).join('');
      }
    } catch(e){
      list.innerHTML = '<li class="muted">Fehler beim Laden.</li>';
    }
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  }

  function toggleMenu(show){
    const willShow = (show===true) ? true : (show===false ? false : menu.hidden);
    if (willShow){
      menu.hidden = false;
      btn.setAttribute('aria-expanded','true');
      fetchList();
    } else {
      menu.hidden = true;
      btn.setAttribute('aria-expanded','false');
    }
  }

  btn.addEventListener('click', () => toggleMenu(menu.hidden));
  document.addEventListener('click', (e)=>{
    if (!wrap.contains(e.target)) toggleMenu(false);
  });

  markAllBtn.addEventListener('click', async ()=>{
    try{
      await fetch(END_MARK, {
        method:'POST',
        headers:{
          'Content-Type':'application/json',
          'X-Requested-With':'fetch'
        },
        credentials:'same-origin',
        body: JSON.stringify({ all:true })
      });
      setBadge(0);
      list.innerHTML = '<li class="empty">Keine neuen Benachrichtigungen.</li>';
    }catch(e){}
  });

  // Optional: alle 60s refresher
  setInterval(fetchList, 60000);
})();
