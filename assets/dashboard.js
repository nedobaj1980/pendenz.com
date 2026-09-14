/* =========================================================================
   Superadmin Dashboard – Premium JS
   Features: Suche, Zahlenanimation, State-Management, Toasts
   =======================================================================*/

(function () {
  const $ = (sel, ctx = document) => ctx.querySelector(sel);
  const $$ = (sel, ctx = document) => Array.from(ctx.querySelectorAll(sel));

  const escapeHtml = (s) => s == null ? "" : String(s).replace(/[&<>"']/g, (m) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" }[m]));

  /* ===== Dynamic Endpoint Resolver ===== */
  const getEndpoint = (dataAttr, fallbackRel) => {
    const root = $('#sdash-root');
    if (root && root.dataset[dataAttr]) return root.dataset[dataAttr];
    const prefix = window.location.pathname.startsWith('/pendenz.com/') ? '/pendenz.com/' : '/';
    return prefix + fallbackRel.replace(/^\//, '');
  };

  /* ===== Notifications (Toast) ===== */
  const showToast = (msg, type = 'info') => {
    let container = $('.sd-toast-container');
    if (!container) {
      container = document.createElement('div');
      container.className = 'sd-toast-container';
      document.body.appendChild(container);

      const style = document.createElement('style');
      style.textContent = `
        .sd-toast-container { position: fixed; bottom: 24px; right: 24px; z-index: 9999; display: flex; flex-direction: column; gap: 8px; pointer-events: none; }
        .sd-toast { background: #1e293b; color: #fff; padding: 12px 20px; border-radius: 8px; box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.1); font-size: 14px; font-weight: 500; min-width: 200px; transform: translateY(20px); opacity: 0; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); pointer-events: auto; display: flex; align-items: center; gap: 10px; border: 1px solid rgba(255,255,255,0.1); }
        .sd-toast--show { transform: translateY(0); opacity: 1; }
        .sd-toast--success { border-left: 4px solid #10b981; }
        .sd-toast--error { border-left: 4px solid #ef4444; }
      `;
      document.head.appendChild(style);
    }

    const toast = document.createElement('div');
    toast.className = `sd-toast sd-toast--${type}`;
    toast.innerHTML = `<span>${msg}</span>`;
    container.appendChild(toast);

    setTimeout(() => toast.classList.add('sd-toast--show'), 10);
    setTimeout(() => {
      toast.classList.remove('sd-toast--show');
      setTimeout(() => toast.remove(), 300);
    }, 3000);
  };

  /* ===== Number Animation ===== */
  (function animateNumbers() {
    $$(".sdash-card__num[data-count]").forEach(el => {
      const target = parseInt(el.dataset.count, 10) || 0;
      let current = 0;
      const step = () => {
        const diff = target - current;
        if (Math.abs(diff) < 1) {
          el.textContent = target;
          return;
        }
        current += diff / 10;
        el.textContent = Math.round(current);
        requestAnimationFrame(step);
      };
      requestAnimationFrame(step);
    });
  })();

  /* ===== View Toggle ===== */
  $$(".sdash-view-toggle .sdash-chip").forEach(btn => {
    btn.addEventListener("click", () => {
      $$(".sdash-view-toggle .sdash-chip").forEach(b => b.classList.toggle("sdash-chip--on", b === btn));
      const wrap = $(".sdash-grids");
      if (wrap) wrap.setAttribute("data-view", btn.dataset.view || "tables");
    });
  });

  /* ===== Global Search ===== */
  const searchInput = $("#sdash-search");
  if (searchInput) {
    searchInput.addEventListener("input", () => {
      const needle = searchInput.value.trim().toLowerCase();
      $$("table[data-table]").forEach(tbl => {
        const tbody = tbl.tBodies[0];
        if (!tbody) return;
        let matches = 0;
        $$("tr", tbody).forEach(tr => {
          const show = !needle || tr.textContent.toLowerCase().includes(needle);
          tr.style.display = show ? "" : "none";
          if (show) matches++;
        });
        const panel = tbl.closest(".sdash-panel");
        if (panel) panel.style.display = matches > 0 || !needle ? "" : "none";
      });
    });
  }

  /* ===== Lightbox & Image Upload ===== */
  const lb = $("#sdash-lightbox");
  const lbImg = $("#lightbox-img");

  if (lb) {
    lb.addEventListener("click", () => lb.classList.remove("sdash-lightbox--show"));
  }

  window.openSdashLightbox = (src) => {
    if (lb && lbImg) {
      lbImg.src = src;
      lb.classList.add("sdash-lightbox--show");
      
      const el = lb;
      if (el.requestFullscreen) el.requestFullscreen();
      else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
      else if (el.msRequestFullscreen) el.msRequestFullscreen();
    }
  };

  if (lb) {
    lb.addEventListener("click", () => {
      lb.classList.remove("sdash-lightbox--show");
      if (document.fullscreenElement || document.webkitFullscreenElement) {
        if (document.exitFullscreen) document.exitFullscreen();
        else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
      }
    });
  }

  document.addEventListener("click", async (e) => {
    // 1. Lightbox Trigger (Fallback)
    if (e.target.classList.contains("js-lightbox-trigger") && !e.target.onclick) {
      window.openSdashLightbox(e.target.src);
    }

    // 2. Upload Trigger (Camera Icon click)
    const wrap = e.target.closest(".js-thumb-wrap");
    if (wrap && e.target.classList.contains("js-replace-trigger")) {
      wrap.querySelector(".js-col-img-input").click();
    }
  });

  document.addEventListener("change", async (e) => {
    if (e.target.classList.contains("js-col-img-input")) {
      const file = e.target.files[0];
      if (!file) return;

      const tr = e.target.closest("tr");
      const id = tr.dataset.id;
      const wrap = e.target.closest(".js-thumb-wrap");

      const fd = new FormData();
      fd.append("image", file);
      fd.append("id", id);

      wrap.style.opacity = '0.4';
      try {
        const uploadUrl = getEndpoint('endpointUpload', 'api/dashboard_upload.php');
        const res = await fetch(uploadUrl, { method: "POST", body: fd });
        const data = await res.json();
        if (data.success) {
          let img = wrap.querySelector("img");
          if (!img) {
            wrap.innerHTML = `<img src="${data.path}" class="sdash-thumb js-lightbox-trigger" alt="Preview" loading="lazy">
                              <div class="sdash-thumb-overlay js-replace-trigger"></div>
                              <input type="file" class="js-col-img-input hidden" accept="image/*">`;
          } else {
            img.src = data.path;
          }
          showBanner("Bild erfolgreich aktualisiert!", "success");
        } else {
          showBanner("Fehler beim Upload: " + data.error, "error");
        }
      } catch (err) { console.error(err); }
      wrap.style.opacity = '1';
    }
  });

  /* ===== Drag & Drop (Enhanced Mobile Core) ===== */
  $$("table[data-table]").forEach(table => {
    const tbody = table.tBodies[0];
    if (!tbody) return;

    let draggingRow = null;
    let placeholder = null;

    const startDrag = (row, y) => {
      draggingRow = row;
      row.classList.add("sdash-dragging");
      placeholder = document.createElement("tr");
      placeholder.className = "sdash-drag-placeholder";
      placeholder.innerHTML = `<td colspan="${row.cells.length}"></td>`;
      moveDrag(y);
    };

    const moveDrag = (y) => {
      if (!draggingRow) return;
      const rows = Array.from(tbody.rows).filter(r => r !== draggingRow && r !== placeholder);
      const nextRow = rows.find(r => {
        const rect = r.getBoundingClientRect();
        return y < rect.top + rect.height / 2;
      });
      if (nextRow) tbody.insertBefore(placeholder, nextRow);
      else tbody.appendChild(placeholder);
    };

    const endDrag = () => {
      if (!draggingRow) return;
      if (placeholder && placeholder.parentNode) {
        placeholder.parentNode.replaceChild(draggingRow, placeholder);
      }
      draggingRow.classList.remove("sdash-dragging");
      draggingRow = null;
      placeholder = null;
    };

    tbody.addEventListener("mousedown", (e) => {
      const tr = e.target.closest("tr");
      if (tr && e.target.closest(".reorder-handle")) {
        startDrag(tr, e.clientY);
      }
    });

    document.addEventListener("mousemove", (e) => {
      if (draggingRow) { e.preventDefault(); moveDrag(e.clientY); }
    });
    document.addEventListener("mouseup", endDrag);

    // TOUCH FIX: Immediate scroll-stop for handle
    tbody.addEventListener("touchstart", (e) => {
      const tr = e.target.closest("tr");
      if (tr && e.target.closest(".reorder-handle")) {
        e.preventDefault(); // Stoppt Browser-Scroll sofort!
        startDrag(tr, e.touches[0].clientY);
      }
    }, { passive: false });

    document.addEventListener("touchmove", (e) => {
      if (draggingRow) { e.preventDefault(); moveDrag(e.touches[0].clientY); }
    }, { passive: false });
    document.addEventListener("touchend", endDrag);
  });

  /* ===== Batch Updates ===== */
  const pending = new Map();
  const markDirty = (el) => el.classList.add('sdash-dirty');

  document.addEventListener('focusin', e => { if (e.target.matches('.sdash-edit')) e.target.dataset.orig = e.target.innerText; });

  document.addEventListener('blur', e => {
    const el = e.target;
    if (!el.matches('.sdash-edit')) return;
    const table = el.closest('table')?.dataset.table;
    const rowId = el.closest('tr')?.dataset.id;
    const field = el.dataset.editField;
    if (!table || !rowId || !field || el.innerText === el.dataset.orig) return;

    if (!pending.has(table)) pending.set(table, new Map());
    const m = pending.get(table);
    const cur = m.get(rowId) || { changes: {}, row: el.closest('tr') };
    cur.changes[field] = el.innerText;
    m.set(rowId, cur);
    markDirty(el);
  }, true);

  // Status/Select Änderungen erfassen
  document.addEventListener('change', e => {
    const el = e.target;
    if (!el.dataset.editTable || !el.dataset.editId || !el.dataset.editField) return;

    const table = el.dataset.editTable;
    const rowId = el.dataset.editId;
    const field = el.dataset.editField;

    if (!pending.has(table)) pending.set(table, new Map());
    const m = pending.get(table);
    const cur = m.get(rowId) || { changes: {}, row: el.closest('tr') };
    cur.changes[field] = el.value;
    m.set(rowId, cur);
    markDirty(el);
  });

  $$(".js-save-table").forEach(btn => {
    btn.addEventListener('click', async () => {
      const tableEl = btn.closest('.sdash-panel')?.querySelector('table[data-table]');
      if (!tableEl) return;
      const table = tableEl.dataset.table;
      const m = pending.get(table) || new Map();
      const updates = Array.from(m.entries()).map(([id, o]) => ({ id, changes: o.changes }));

      let order = null;
      if (tableEl.dataset.orderField) {
        order = $$("tr", tableEl.tBodies[0]).map(tr => tr.dataset.id).filter(Boolean);
      }

      btn.disabled = true;
      try {
        const batchUrl = getEndpoint('endpointBatch', 'api/batch_update.php');
        const res = await fetch(batchUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ table, updates, order, orderField: tableEl.dataset.orderField })
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.error);

        m.forEach(o => $$('.sdash-dirty', o.row).forEach(td => td.classList.remove('sdash-dirty')));
        pending.delete(table);
        showToast(`${data.updated || updates.length} Änderungen gespeichert`, 'success');
      } catch (e) {
        showToast(e.message || 'Speichern fehlgeschlagen', 'error');
      } finally { btn.disabled = false; }
    });
  });

  /* ===== Listen-Vorschau laden ===== */
  const listsPreset = $("#lists-preset");
  const pendenzenBody = $("#pendenzen-body");

  // Spalten-Sichtbarkeit Cache (Persistent)
  const colVisibility = new Map();
  const STORAGE_KEY = 'sdash-col-prefs';

  const saveColPrefs = () => {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.fromEntries(colVisibility)));
  };

  const loadColPrefs = () => {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (!saved) return;
    try {
      const prefs = JSON.parse(saved);
      Object.entries(prefs).forEach(([k, v]) => {
        colVisibility.set(k, v);
        const chk = $(`.js-col-toggle[data-col="${k}"]`);
        if (chk) chk.checked = v;
      });
    } catch (e) { console.warn(e); }
  };

  const applyColVisibility = () => {
    colVisibility.forEach((visible, colClass) => {
      $$(`.${colClass}`).forEach(el => el.classList.toggle('col-hidden', !visible));
    });
  };

  if (pendenzenBody && listsPreset) {
    const render = (rows) => {
      pendenzenBody.innerHTML = rows?.length ? rows.map(r => {
        const stat = r.status || 'offen';
        const opts = ['offen', 'in Bearbeitung', 'erledigt', 'archiviert'];
        return `
          <tr data-id="${r.id || ""}">
            <td class="col-id">${r.id || ""}</td>
            <td class="col-img">
              <div class="sdash-thumb-container js-thumb-wrap">
                ${r.cover_pfad ? `<img src="${escapeHtml(r.cover_pfad)}" class="sdash-thumb js-lightbox-trigger" alt="Preview" loading="lazy">` : `<div class="sdash-thumb js-thumb-empty js-replace-trigger" style="display:flex;align-items:center;justify-content:center;font-size:10px;color:#ccc">Kein Bild</div>`}
                <div class="sdash-replace-btn js-replace-trigger" title="Bild ändern"></div>
                <a href="pages/pendenz_show.php?id=${r.id}" class="sdash-detail-btn" title="Details bearbeiten"></a>
                <input type="file" class="js-col-img-input hidden" accept="image/*">
              </div>
            </td>
            <td class="col-proj"><small>${escapeHtml(r.projekt_name || "—")}</small></td>
            <td class="col-obj"><small>${escapeHtml(r.objekt_name || "—")}</small></td>
            <td class="col-unit"><small>${escapeHtml(r.wohnung_name || "—")}</small></td>
            <td class="col-titel" contenteditable="true" class="sdash-edit" data-edit-field="titel">${escapeHtml(r.titel || "")}</td>
            <td class="col-desc" contenteditable="true" class="sdash-edit" data-edit-field="kurzbeschreibung">${escapeHtml(r.kurzbeschreibung || "—")}</td>
            <td class="col-resp"><span class="sdash-badge">${escapeHtml(r.zustaendig_name || "—")}</span></td>
            <td class="col-status">
              <select class="sdash-select status-badge status-${stat}" data-edit-field="status" onchange="this.className='sdash-select status-badge status-'+this.value">
                ${opts.map(o => `<option value="${o}" ${o === stat ? 'selected' : ''}>${o}</option>`).join('')}
              </select>
            </td>
            <td class="col-prio" contenteditable="true" class="sdash-edit" data-edit-field="prioritaet">${escapeHtml(r.prioritaet || "normal")}</td>
            <td class="col-due" contenteditable="true" class="sdash-edit" data-edit-field="enddatum"><small>${escapeHtml(r.faellig_am || "—")}</small></td>
            <td class="col-created"><small>${escapeHtml(r.erstellt_am || "—")}</small></td>
            <td class="col-updated"><small>${escapeHtml(r.aktualisiert_am || "—")}</small></td>
            <td class="col-note" contenteditable="true" class="sdash-edit" data-edit-field="notiz">${escapeHtml(r.notiz || "—")}</td>
            <td class="reorder-handle"><svg class="sdash-icon" viewBox="0 0 24 24" style="width:16px; height:16px"><path d="M7 15l5 5 5-5M7 9l5-5 5 5"/></svg></td>
          </tr>`;
      }).join('') : '<tr><td colspan="14">Keine Einträge gefunden</td></tr>';

      applyColVisibility();
    };

    const load = async () => {
      pendenzenBody.style.opacity = '0.5';
      try {
        const previewUrl = getEndpoint('endpointPreview', 'api/pendenzen_preview.php') + `?preset=${encodeURIComponent(listsPreset.value)}`;
        const res = await fetch(previewUrl);
        const data = await res.json();
        render(Array.isArray(data.rows) ? data.rows : (Array.isArray(data) ? data : []));
      } catch (e) { console.error(e); }
      pendenzenBody.style.opacity = '1';
    };
    listsPreset.addEventListener("change", load);
  }

  /* ===== Column Picker Logic ===== */
  const btnCol = $("#btn-col-picker");
  const menuCol = $("#col-menu");
  if (btnCol && menuCol) {
    btnCol.addEventListener("click", (e) => {
      e.stopPropagation();
      menuCol.classList.toggle("sdash-col-menu--show");
    });

    document.addEventListener("click", (e) => {
      if (!menuCol.contains(e.target)) menuCol.classList.remove("sdash-col-menu--show");
    });

    $$(".js-col-toggle").forEach(chk => {
      colVisibility.set(chk.dataset.col, chk.checked);

      chk.addEventListener("change", () => {
        colVisibility.set(chk.dataset.col, chk.checked);
        applyColVisibility();
        saveColPrefs();
      });
    });

    loadColPrefs();
    applyColVisibility();
  }

  /* ===== Live Quick Insert ===== */
  const quickInsert = async (table, values, tbody, rowTemplate) => {
    try {
      const insertUrl = getEndpoint('endpointQuick', 'api/quick_insert.php');
      const res = await fetch(insertUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ table, values })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error);

      const temp = document.createElement('tbody');
      temp.innerHTML = rowTemplate(data.id);
      const newRow = temp.firstElementChild;
      tbody.prepend(newRow);

      const focusCell = $(".sdash-edit", newRow);
      if (focusCell) {
        focusCell.focus();
        showToast('Neuer Eintrag erstellt', 'success');
      }
    } catch (e) {
      showToast('Quick Insert fehlgeschlagen', 'error');
    }
  };

  $("#btn-add-project")?.addEventListener("click", () => {
    quickInsert('projekte', { name: 'Neues Projekt' }, $("#tbl-projects tbody"), (id) => `
      <tr data-id="${id}">
        <td>${id}</td>
        <td contenteditable="true" class="sdash-edit" data-edit-table="projekte" data-edit-id="${id}" data-edit-field="name">Neues Projekt</td>
        <td><small>gerade eben</small></td>
        <td class="reorder-column" data-no-sort><svg class="sdash-icon" viewBox="0 0 24 24" style="width:16px; height:16px"><path d="M7 15l5 5 5-5M7 9l5-5 5 5"/></svg></td>
      </tr>
    `);
  });

  $("#btn-add-todo")?.addEventListener("click", () => {
    quickInsert('pendenzen', { titel: 'Neue Pendenz', status: 'offen' }, $("#tbl-todos tbody"), (id) => `
      <tr data-id="${id}">
        <td>${id}</td>
        <td contenteditable="true" class="sdash-edit" data-edit-table="pendenzen" data-edit-id="${id}" data-edit-field="titel">Neue Pendenz</td>
        <td>—</td>
        <td><span class="status-badge status-offen">offen</span></td>
        <td><small>gerade eben</small></td>
        <td class="reorder-column" data-no-sort><svg class="sdash-icon" viewBox="0 0 24 24" style="width:16px; height:16px"><path d="M7 15l5 5 5-5M7 9l5-5 5 5"/></svg></td>
      </tr>
    `);
  });

  /* ===== Table Sorting ===== */
  document.addEventListener("click", e => {
    const th = e.target.closest("th");
    if (!th || th.matches('.reorder-column')) return;
    const table = th.closest("table");
    if (!table || th.dataset.noSort !== undefined) return;

    const tbody = table.tBodies[0];
    if (!tbody) return;

    const rows = Array.from(tbody.rows);
    const index = Array.from(th.parentElement.children).indexOf(th);
    const isAsc = !th.classList.contains("sdash-th--asc");

    $$("th", th.parentElement).forEach(h => h.classList.remove("sdash-th--asc", "sdash-th--desc"));
    th.classList.toggle("sdash-th--asc", isAsc);
    th.classList.toggle("sdash-th--desc", !isAsc);

    rows.sort((a, b) => {
      const aVal = a.cells[index].innerText.trim();
      const bVal = b.cells[index].innerText.trim();

      const aNum = parseFloat(aVal.replace(/[^\d.-]/g, ''));
      const bNum = parseFloat(bVal.replace(/[^\d.-]/g, ''));

      if (!isNaN(aNum) && !isNaN(bNum)) return isAsc ? aNum - bNum : bNum - aNum;
      return isAsc ? aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
    });

    tbody.append(...rows);
  });

  /**
   * Column Resizing & Persistence
   */
  const initResizers = () => {
    const tables = document.querySelectorAll('.sdash-table');
    const isMobile = window.innerWidth <= 768;
    const storageKey = isMobile ? 'sdash-col-widths-mobile' : 'sdash-col-widths-desktop';
    
    // Debug info for the user to see it's working (Optional)
    console.log(`[Dashboard] Initializing Resizers for ${isMobile ? 'Mobile' : 'Desktop'}`);

    tables.forEach(table => {
      const tableId = table.id;
      if (!tableId) return;

      const headers = table.querySelectorAll('th');
      const savedWidths = JSON.parse(localStorage.getItem(storageKey + '-' + tableId) || '{}');

      headers.forEach((th) => {
        if (th.classList.contains('reorder-column')) return;

        const colClass = Array.from(th.classList).find(c => c.startsWith('col-'));
        
        // Reset or Apply
        if (colClass && savedWidths[colClass]) {
          th.style.width = savedWidths[colClass];
          th.style.minWidth = savedWidths[colClass];
        } else {
          // Reset to CSS defaults if no specific resize was saved yet for this platform
          th.style.width = '';
          th.style.minWidth = '';
        }

        // Add resizer handle
        if (!th.querySelector('.sdash-resizer')) {
          const resizer = document.createElement('div');
          resizer.className = 'sdash-resizer';
          th.appendChild(resizer);
          
          let startX, startWidth;

          const onMove = (e) => {
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            const newWidth = startWidth + (clientX - startX);
            if (newWidth > 40) {
              th.style.width = `${newWidth}px`;
              th.style.minWidth = `${newWidth}px`;
              resizer.classList.add('resizing');
            }
          };

          const onEnd = () => {
            resizer.classList.remove('resizing');
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onEnd);

            const currentWidths = JSON.parse(localStorage.getItem(storageKey + '-' + tableId) || '{}');
            if (colClass) {
              currentWidths[colClass] = th.style.width;
              localStorage.setItem(storageKey + '-' + tableId, JSON.stringify(currentWidths));
            }
          };

          const onStart = (e) => {
            startX = e.touches ? e.touches[0].clientX : e.clientX;
            startWidth = th.offsetWidth;
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onEnd);
            document.addEventListener('touchmove', onMove);
            document.addEventListener('touchend', onEnd);
          };

          resizer.addEventListener('mousedown', onStart);
          resizer.addEventListener('touchstart', onStart);
        }
      });
    });
  };

  initResizers();
  // Listen for custom event if needed or just rerun when table is built
  window.addEventListener('resize', initResizers);

})();
