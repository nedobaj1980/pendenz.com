// C:\xampp\htdocs\pendenz.com\assets\js\app.js

(function() {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  ready(function() {
    // Bestätigungsdialoge für Links/Buttons mit data-confirm
    document.body.addEventListener('click', function(e) {
      const el = e.target.closest('[data-confirm]');
      if (!el) return;

      const msg = el.getAttribute('data-confirm') || 'Bist du sicher?';
      if (!window.confirm(msg)) {
        e.preventDefault();
        e.stopPropagation();
      }
    });

    // Beispiel: zukünftige Buttons mit data-action könnten hier zentral behandelt werden
    // document.body.addEventListener('click', function(e) {
    //   const btn = e.target.closest('[data-action]');
    //   if (!btn) return;
    //   const action = btn.getAttribute('data-action');
    //   switch (action) {
    //     case 'invite-user': // ...
    //       break;
    //   }
    // });

  });
})();
