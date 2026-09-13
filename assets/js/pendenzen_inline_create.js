(function(){
  const form = document.getElementById('form-create');
  if (!form) return;

  const msg  = document.getElementById('fc_msg');
  const zSel = document.getElementById('fc_zust');

  async function loadUsers(){
    try{
      const res = await fetch('/pendenz.com/api/options_users.php', {credentials:'same-origin'});
      const data = await res.json();
      zSel.innerHTML = '<option value="">—</option>' + (data.items||[]).map(u =>
        `<option value="${u.id}">${escapeHtml(u.name)}${u.email ? ' — ' + escapeHtml(u.email) : ''}</option>`
      ).join('');
    }catch(e){ /* ignore */ }
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
  }

  // initial users
  loadUsers();

  form.addEventListener('submit', async (e)=>{
    e.preventDefault();
    msg.textContent = 'Speichere…';

    const payload = {
      projekt_id: form.fc_proj.value ? parseInt(form.fc_proj.value,10) : null,
      titel: form.fc_titel.value.trim(),
      beschreibung: form.fc_beschr.value.trim(),
      status: form.fc_status.value,
      prioritaet: parseInt(form.fc_prio.value || '0', 10),
      zugewiesen_an: form.fc_zust.value ? parseInt(form.fc_zust.value,10) : null,
      send_now: form.fc_send.checked ? 1 : 0
    };

    if (!payload.titel){
      msg.textContent = 'Bitte Titel angeben.'; return;
    }

    try{
      const res = await fetch('/pendenz.com/api/pendenzen_save.php', {
        method:'POST',
        headers:{'Content-Type':'application/json','X-Requested-With':'fetch'},
        credentials:'same-origin',
        body: JSON.stringify(payload)
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.message || 'Fehler beim Speichern');

      msg.textContent = 'Gespeichert.';
      form.reset();

      // Seite neu laden, damit die neue Zeile erscheint (serverseitiges Rendern)
      // Alternativ: Ajax-Reload implementieren
      location.reload();

    }catch(err){
      msg.textContent = 'Fehler: ' + err.message;
    }
  });
})();
