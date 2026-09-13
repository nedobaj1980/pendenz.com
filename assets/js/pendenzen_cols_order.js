(function(){
  const list = document.getElementById('colOrder');
  const payload = document.getElementById('cols_order_payload');
  if (!list || !payload) return;

  let dragEl = null;

  const rebuildHidden = () => {
    const keys = Array.from(list.querySelectorAll('li.col-item')).map(li => li.dataset.col);
    const checked = new Set(Array.from(document.querySelectorAll('input[name="cols[]"]:checked')).map(i => i.value));
    const filtered = keys.filter(k => checked.has(k));
    payload.value = filtered.join(',');
  };

  list.addEventListener('dragstart', e => {
    const li = e.target.closest('li.col-item');
    if (!li) return;
    dragEl = li;
    e.dataTransfer.effectAllowed = 'move';
  });
  list.addEventListener('dragover', e => {
    e.preventDefault();
    const li = e.target.closest('li.col-item');
    if (!li || li === dragEl) return;
    const rect = li.getBoundingClientRect();
    const before = (e.clientY - rect.top) < (rect.height / 2);
    list.insertBefore(dragEl, before ? li : li.nextSibling);
  });
  list.addEventListener('dragend', rebuildHidden);

  document.querySelectorAll('input[name="cols[]"]').forEach(cb => {
    cb.addEventListener('change', () => {
      const key = cb.value;
      const existing = list.querySelector('li.col-item[data-col="'+CSS.escape(key)+'"]');
      if (cb.checked && !existing){
        const li = document.createElement('li');
        li.className = 'col-item';
        li.draggable = true;
        li.dataset.col = key;
        li.textContent = cb.parentElement.textContent.trim();
        li.style.padding = '8px 10px';
        li.style.borderBottom = '1px solid #eee';
        li.style.cursor = 'move';
        li.style.background = '#fafafa';
        list.appendChild(li);
      } else if (!cb.checked && existing) {
        existing.remove();
      }
      rebuildHidden();
    });
  });

  rebuildHidden();
})();
