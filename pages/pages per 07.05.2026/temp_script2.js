
// --- GLOBAL STATE ---
window.globalOptions = {
    projects: null,
    categories: null,
    status: ["offen", "in Bearbeitung", "erledigt", "archiviert", "wartend"],
    tageszeit: ["", "Vormittag", "Mittag", "Nachmittag", "Abend", "Nacht"],
    projekt_id: [],
    objekt_id: [],
    wohnung_id: [],
    kategorie_id: [],
    subkategorie_id: [],
    zustaendig_id: []
};
window.layerQuickChoices = null;

// --- GLOBAL DELETE (Funktion in den Head geschoben) ---
(function() {
    // Populate dropdown options into global state for the inline editor
    const sync = (name) => {
        document.querySelectorAll(`select[name="${name}"], select[id="filter_${name}"]`).forEach(sel => {
            Array.from(sel.options).forEach(o => {
                if (o.value && !window.globalOptions[name].find(x => String(x.id) === String(o.value))) {
                    window.globalOptions[name].push({ id: o.value, name: o.textContent.trim() });
                }
            });
        });
    };
    ['projekt_id', 'objekt_id', 'wohnung_id', 'kategorie_id', 'zustaendig_id'].forEach(sync);

    // Scroll persistence
    const p = new URL(location.href).searchParams.get('scroll');
    if (p && +p > 0) window.scrollTo(0, +p);

    document.querySelectorAll('form[method="get"]').forEach(f => {
        f.addEventListener('submit', () => {
            let hid = f.querySelector('input[name="scroll"]');
            if (!hid) { hid = document.createElement('input'); hid.type = 'hidden'; hid.name = 'scroll'; f.appendChild(hid); }
            hid.value = String(window.scrollY);
        });
    });

    // Project Context Auto-Trigger with Inheritance
    const initialPid = null;
    const initialOid = null;
    const initialWid = null;
    const initialKid = null;

    const initialRid = null;

    const fpidEl = document.getElementById('filter_projekt_id');
    const fpid = fpidEl ? fpidEl.value : null;
    
    if (initialPid) {
        // Both for the main form and the inline row
        updateProjectContext(initialPid, 'form', initialOid, initialWid, initialKid, initialRid);
        if (document.getElementById('inline_project_id')) {
            updateProjectContext(initialPid, 'inline', 0, 0, 0, 0);
        }
    }
    if (fpid) updateProjectContext(fpid, 'filter');


    // Initial Column Resizing Setup
    initResizers();
})();

// --- LIGHTBOX ---
function openLightbox(src) {
    const lb = document.getElementById('imageLightbox');
    const img = document.getElementById('lightboxImg');
    if (lb && img) {
        img.src = src;
        lb.style.display = 'flex';
    }
}

// --- COLUMN RESIZING ---
function initResizers() {
    const table = document.getElementById('pendenzenTable');
    if (!table) return;
    const cols = table.querySelectorAll('th');
    cols.forEach(col => {
        const resizer = col.querySelector('.resizer');
        if (!resizer) return;
        
        let startX, startWidth;

        resizer.addEventListener('mousedown', e => {
            startX = e.pageX;
            startWidth = col.offsetWidth;
            
            const onMouseMove = e => {
                const width = startWidth + (e.pageX - startX);
                col.style.width = width + 'px';
            };
            
            const onMouseUp = () => {
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
                saveColWidths();
            };
            
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });
    });
}

async function saveColWidths() {
    const table = document.getElementById('pendenzenTable');
    const ths = table.querySelectorAll('thead th[data-field]');
    const widths = {};
    ths.forEach(th => {
        const field = th.dataset.field || th.classList[0].replace('col-','');
        widths[field] = th.style.width;
    });
    await fetch('../api/pendenzen_save_widths.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ widths })
    });
}

// --- QUICK CAPTURE LOGIC ---

function toggleQuickDrawer() {
    const dr = document.getElementById('quickDrawer');
    dr.classList.toggle('active');
    if (dr.classList.contains('active')) {
        updateQContext();
        document.getElementById('qTitle').focus();
    }
}

function updateQContext() {
    const pidSelect = document.getElementById('projekt_id');
    const widSelect = document.querySelector('.wohnung-select');
    const pext = (pidSelect && pidSelect.options[pidSelect.selectedIndex] ? pidSelect.options[pidSelect.selectedIndex].text : '—');
    const wext = (widSelect && widSelect.options[widSelect.selectedIndex] ? widSelect.options[widSelect.selectedIndex].text : 'Alle');
    const ctxText = document.getElementById('qContextText');
    if (ctxText) ctxText.textContent = pext + " > " + wext;
}

function selectQCat(id, btn) {
    document.querySelectorAll('.quick-cat-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('qCatId').value = id;
    
    // Trigger Quick Choices for the drawer as well
    const container = document.getElementById('qChoices');
    container.innerHTML = '';
    
    // Reuse the existing updateQuickChoices logic but target the drawer
    updateQuickChoices(id);
    
    // Copy the choices to the drawer's container
    setTimeout(() => {
        const globalChoices = document.querySelector('#inlineNewRow .quick-choices');
        if (globalChoices) {
            Array.from(globalChoices.children).forEach(pill => {
                const clone = pill.cloneNode(true);
                clone.onclick = () => {
                    document.getElementById('qTitle').value = pill.textContent;
                };
                container.appendChild(clone);
            });
        }
    }, 50);
}

function handleQPhotos(input) {
    const preview = document.getElementById('qPhotoPreview');
    preview.innerHTML = '';
    Array.from(input.files).forEach(file => {
        const reader = new FileReader();
        reader.onload = e => {
            const img = document.createElement('img');
            img.src = e.target.result;
            preview.appendChild(img);
        };
        reader.readAsDataURL(file);
    });
}

async function saveQuickPendenz() {
    const btn = document.getElementById('qSaveBtn');
    const msg = document.getElementById('qMsg');
    const pid_a = document.getElementById('projekt_id');
    const pid_b = document.getElementById('inline_project_id');
    const pid = (pid_a ? pid_a.value : null) || (pid_b ? pid_b.value : null);
    const widEl = document.querySelector('.wohnung-select');
    const wid = widEl ? widEl.value : null;
    const cid = document.getElementById('qCatId').value;
    const title = document.getElementById('qTitle').value;
    const files = document.getElementById('qFiles').files;

    if (!pid || !title) { alert("Bitte Projekt und Titel angeben"); return; }

    btn.disabled = true;
    btn.textContent = "⏳ Speichern...";
    msg.textContent = "";

    const fd = new FormData();
    fd.append('inline_new', '1');
    fd.append('projekt_id', pid);
    fd.append('wohnung_id', wid);
    fd.append('kategorie_id', cid);
    fd.append('titel', title);
    Array.from(files).forEach(f => fd.append('bilder[]', f));

    try {
        const res = await fetch('pendenzen.php', { method: 'POST', body: fd });
        const js = await res.json();
        if (js.ok) {
            msg.style.color = 'green';
            msg.textContent = "✅ Erfolgreich gespeichert!";
            setTimeout(() => location.reload(), 1000);
        } else {
            msg.style.color = 'red';
            msg.textContent = "❌ Fehler: " + (js.error || "Server-Error");
            btn.disabled = false;
            btn.textContent = "🚀 SICHERN";
        }
    } catch (e) {
        msg.textContent = "❌ Verbindungsproblem";
        btn.disabled = false;
    }
}



// --- UI HELPERS ---
window.toggleForm = () => {
    const fs = document.getElementById('formSection');
    const btn = document.getElementById('btnToggleForm');
    if (!fs || !btn) return;
    if (fs.style.display === 'none') {
        fs.style.display = 'block';
        btn.textContent = '✖ Schließen';
        btn.style.background = '#f43f5e';

        // Formular-Kontext gezielt aus dem Formular selbst laden, nicht aus dem Tabellenfilter unten.
        const fPidEl = document.getElementById('projekt_id');
        const fOidEl = document.getElementById('objekt_id');
        const fWidEl = document.getElementById('wohnung_id');
        const formPid = fPidEl ? fPidEl.value : '';
        const formOid = fOidEl ? fOidEl.value : '';
        const formWid = fWidEl ? fWidEl.value : '';
        if (formPid) {
            updateProjectContext(formPid, 'form', formOid, formWid);
        }

        syncFolderPath(); // Ensure path is visible immediately
        fs.scrollIntoView({ behavior: 'smooth' });
    } else {
        fs.style.display = 'none';
        btn.textContent = '➕ Neue Pendenz';
        btn.style.background = '';
    }
};

// --- DATE & TIME CALCULATIONS (WORKDAYS ONLY) ---
const isWorkDay = (d) => d.getDay() !== 0 && d.getDay() !== 6;

const addWorkDays = (start, days) => {
    let d = new Date(start);
    let count = 0;
    while (count < days) {
        d.setDate(d.getDate() + 1);
        if (isWorkDay(d)) count++;
    }
    return d;
};

const getWorkDaysDiff = (start, end) => {
    if (end < start) return 0;
    let d = new Date(start);
    let count = 0;
    while (d < end) {
        d.setDate(d.getDate() + 1);
        if (isWorkDay(d)) count++;
    }
    return count;
};

const subWorkDays = (end, days) => {
    let d = new Date(end);
    let count = 0;
    while (count < days) {
        d.setDate(d.getDate() - 1);
        if (isWorkDay(d)) count++;
    }
    return d;
};

const parseDuration = (val) => {
    if (!val) return 0;
    const num = parseFloat(val.replace(',', '.'));
    if (isNaN(num)) return 0;
    const suffix = val.replace(/[0-9., ]/g, '').toLowerCase();
    
    // Wochen (Faktor 5 Arbeitstage)
    if (['w','wo','woc','woch'].includes(suffix)) return num * 5;
    // Stunden (Faktor 1/8 Tag)
    if (['h','s','st','stu','stun','sund'].includes(suffix)) return num / 8;
    // Tage (Faktor 1)
    return num;
};

const refreshDateCalculations = (row, sourceField = null) => {
    const getEl = (f) => row.querySelector(`[name="${f}"], [data-field="${f}"]`);
    const startIn = getEl('startdatum');
    const endIn   = row.querySelector('[name="enddatum"], td[data-field="enddatum"]'); 
    const durIn   = getEl('dauer');
    if (!startIn || !endIn || !durIn) return;

    const getVal = (el) => {
        if (el.tagName === 'INPUT' || el.tagName === 'SELECT') return el.value;
        return el.dataset.value || el.innerText;
    };
    const setVal = (el, v) => {
        if (el.tagName === 'INPUT') {
            el.value = v;
            el.dispatchEvent(new Event('change', {bubbles: true})); 
        } else {
            el.dataset.value = v;
            if (v && v.includes('-') && v.length === 10) {
                const parts = v.split('-');
                el.innerText = `${parts[2]}.${parts[1]}.${parts[0].slice(-2)}`;
            } else if (v !== null && !isNaN(parseFloat(v))) {
                const d = parseFloat(v);
                el.innerText = (Number.isInteger(d) ? d : d.toFixed(1)) + ' Tage';
            } else {
                el.innerText = v || '—';
            }
        }
    };

    const startVal = getVal(startIn);
    const endVal   = getVal(endIn);
    const durVal   = getVal(durIn);

    const start = startVal ? new Date(startVal) : null;
    const end   = endVal ? new Date(endVal) : null;
    const dur   = parseDuration(durVal);

    if (sourceField === 'enddatum' && start && end) {
        // ZWANG: Wenn Ende geändert -> Dauer berechnen
        const diff = getWorkDaysDiff(start, end);
        setVal(durIn, diff + " Tage");
    } else if (start && dur > 0) {
        // ZWANG: Wenn Start/Dauer geändert (oder initial) -> Ende berechnen
        const res = addWorkDays(start, dur);
        setVal(endIn, res.toISOString().split('T')[0]);
        // Dauer formatieren falls nackt
        if (!durVal.includes('Tage')) setVal(durIn, dur + " Tage");
    } else if (end && dur > 0 && !start) {
        // Fallback: Ende + Dauer -> Start
        const res = subWorkDays(end, dur);
        setVal(startIn, res.toISOString().split('T')[0]);
        if (!durVal.includes('Tage')) setVal(durIn, dur + " Tage");
    }
};

document.addEventListener('input', e => {
    const row = e.target.closest('form') || e.target.closest('tr');
    if (!row) return;

    // Live Date Calculation
    if (e.target.classList.contains('date-calc-trigger')) {
        refreshDateCalculations(row, e.target.name || e.target.dataset.field);
    }

    // Live Time/Tageszeit Recognition
    if (e.target.classList.contains('time-calc-trigger')) {
        const timeVal = e.target.value;
        const tzSel = row.querySelector('[name="tageszeit"]');
        if (timeVal && tzSel) {
            const hour = parseInt(timeVal.split(':')[0]);
            let tz = '';
            if (hour >= 5 && hour < 9) tz = 'Morgens';
            else if (hour >= 9 && hour < 12) tz = 'Vormittag';
            else if (hour >= 12 && hour < 14) tz = 'Mittag';
            else if (hour >= 14 && hour < 18) tz = 'Nachmittag';
            else if (hour >= 18 && hour < 22) tz = 'Abend';
            else tz = 'Nacht';
            if (tz) tzSel.value = tz;
        }
    }
});

// --- DURATION AUTO-FORMATTER ---
const formatDauerField = (el) => {
    if (!el.value) return;
    const days = parseDuration(el.value);
    if (days >= 0) {
        const formatted = Number.isInteger(days) ? days : days.toFixed(1);
        el.value = formatted + ' Tage';
    }
};

document.addEventListener('change', e => {
    if (e.target.name === 'dauer' && e.target.value) {
        formatDauerField(e.target);
    }
});

// Format existing durations on load
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('input[name="dauer"]').forEach(formatDauerField);
    
    // Trigger auto-calculations for existing data
    document.querySelectorAll('form, tr').forEach(row => {
        if (row.querySelector('.date-calc-trigger')) refreshDateCalculations(row);
    });
});

// --- PROJECT & FOLDER CONTEXT ---
async function updateProjectContext(pid, context = 'form', defOid = 0, defWid = 0, defKid = 0, defRid = 0) {
    if (!pid) return;

    const isFilter = (context === 'filter');
    const isInline = (context === 'inline');
    const isQc     = (context === 'qc');
    
    // STRICT ISOLATION: 
    // If we're in 'form' mode, we MUST NOT look at filter_objekt_id.
    let oid = defOid;
    if (!oid) {
        if (isFilter) {
            const fOid = document.getElementById('filter_objekt_id');
            oid = fOid ? fOid.value : 0;
        } else {
            const oOid = document.getElementById('objekt_id');
            oid = oOid ? oOid.value : 0;
        }
    }

    if (isQc) {
        if (typeof document.getElementById('qc_objekt_id') !== 'undefined' && document.getElementById('qc_objekt_id')) document.getElementById('qc_objekt_id').value = oid;
        if (typeof document.getElementById('qc_wohnung_id') !== 'undefined' && document.getElementById('qc_wohnung_id')) document.getElementById('qc_wohnung_id').value = defWid;
    }

    try {
        const res = await fetch(`pendenzen.php?action=context&projekt_id=${pid}&objekt_id=${oid}`);
        const js = await res.json();
        if (!js.ok) return;

        // Update Units (Apartments)
        let wSelector = '#wohnung_id';
        if (isFilter) wSelector = '#filter_wohnung_id';
        if (isInline) wSelector = '.wohnung-select';

        document.querySelectorAll(wSelector).forEach(sel => {
            const cur = sel.value || defWid;
            sel.innerHTML = isFilter ? '<option value="">— Alle Einheiten —</option>' : '<option value="" data-path="">— keine / alle —</option>';
            (js.apartments || []).forEach(a => {
                const opt = new Option(a.name, a.id);
                opt.dataset.path = a.folder_name || a.name;
                opt.dataset.objektId = a.objekt_id;
                if (String(a.id) === String(cur)) opt.selected = true;
                sel.add(opt);
            });
        });

        // Update Buildings (Objects) - Only for Main Form and Filter Bar
        if (!isInline) {
            const oSelector = isFilter ? '#filter_objekt_id' : '#objekt_id';
            const oSel = document.querySelector(oSelector);
            if (oSel) {
                const cur = oSel.value || defOid;
                oSel.innerHTML = isFilter ? '<option value="">— Alle Objekte —</option>' : '<option value="">— wählen —</option>';
                (js.objects || []).forEach(o => {
                    const opt = new Option(o.name, o.id);
                    opt.dataset.path = o.folder_name || o.name;
                    if (String(o.id) === String(cur)) opt.selected = true;
                    oSel.add(opt);
                });
                
                // If PROJECT CHANGED (cur building not found in new objects), we must reset
                if (cur && !Array.from(oSel.options).some(o => String(o.value) === String(cur))) {
                    oSel.value = "";
                    // Re-run context fetch WITHOUT oid to get all units for the new project
                    if (!defOid) {
                        return updateProjectContext(pid, context, 0, 0, defKid, defRid);
                    }
                }

                // Building-to-Unit Cascading
                if (js.objects && js.objects.length === 1 && !isFilter && !oSel.value) {
                    oSel.selectedIndex = 1;
                }
            }
        }

        // Update Categories
        const kSelectors = isInline ? ['.kategorie-select'] : ['#kategorie_id'];
        kSelectors.forEach(query => {
            document.querySelectorAll(query).forEach(sel => {
                const cur = sel.value || defKid;
                sel.innerHTML = '<option value="">Kategorie…</option>';
                (js.categories || []).forEach(k => {
                    const opt = new Option(k.name, k.id);
                    if (String(k.id) === String(cur)) opt.selected = true;
                    sel.add(opt);
                });
                if (cur && isInline) updateQuickChoices(cur);
            });
        });

        // Update Arts
        if (isQc) {
            const qcWrap = document.getElementById('qc_type_selector');
            if (qcWrap) {
                qcWrap.innerHTML = '';
                (js.types || []).forEach(t => {
                   const btn = document.createElement('div');
                   btn.className = 'qc-type-btn';
                   btn.innerHTML = `<span class="emoji">${t.icon || '📝'}</span><label>${t.name}</label>`;
                   btn.dataset.allowedTypes = t.allowed_business_types || '';
                   btn.onclick = () => setQcType(t.id, btn);
                   qcWrap.appendChild(btn);
                });
            }
        } else {
            const aSelectors = isInline ? ['.art-select'] : ['#vorgangsart_id'];
            aSelectors.forEach(query => {
                document.querySelectorAll(query).forEach(sel => {
                    const cur = sel.value;
                    sel.innerHTML = isInline ? '<option value="">Art…</option>' : '<option value="">— bitte wählen —</option>';
                    (js.types || []).forEach(t => {
                        const opt = new Option(t.name, t.id);
                        opt.dataset.allowedTypes = t.allowed_business_types || '';
                        opt.dataset.pids = (t.allowed_pids || []).join(',');
                        opt.dataset.oids = (t.allowed_oids || []).join(',');
                        if (String(t.id) === String(cur)) opt.selected = true;
                        sel.add(opt);
                    });
                    if (!sel.dataset.filterBound) {
                        sel.addEventListener('change', () => {
                            filterMembers(context);
                            handleExpressAutoFill(context);
                        });
                        sel.dataset.filterBound = "1";
                    }
                });
            });
        }

        // Update Members Cache & UI
        if (!window.projectMembers) window.projectMembers = {};
        window.projectMembers[context] = js.members || [];
        filterMembers(context, typeof defUser !== 'undefined' ? defUser : 0);

        if (!isFilter) syncFolderPath();
    } catch (e) {
        console.error("Context update failed", e);
    }
}

function handleExpressAutoFill(context = 'form') {
    if (context !== 'form') return; // Only for main form express setup
    
    const artSel = document.getElementById('vorgangsart_id');
    const pSel   = document.getElementById('projekt_id');
    const oSel   = document.getElementById('objekt_id');
    const uSel   = document.getElementById('assignee_user_id');

    const opt = (artSel && artSel.options) ? artSel.options[artSel.selectedIndex] : null;
    if (!opt || !opt.value) return;

    const pids = opt.dataset.pids ? opt.dataset.pids.split(',') : [];
    const oids = opt.dataset.oids ? opt.dataset.oids.split(',') : [];

    // 1. Auto-Select Project if only one is allowed
    if (pids.length === 1 && pids[0] !== "") {
        if (String(pSel.value) !== String(pids[0])) {
            pSel.value = pids[0];
            pSel.dispatchEvent(new Event('change'));
        }
    }

    // 2. Auto-Select Building if only one is allowed
    if (oids.length === 1 && oids[0] !== "") {
        // Wait for project context to load objects, then select
        setTimeout(() => {
            if (String(oSel.value) !== String(oids[0])) {
                oSel.value = oids[0];
                oSel.dispatchEvent(new Event('change'));
            }
        }, 500);
    }
}

// Global listener for speed-entry auto-derive
document.addEventListener('change', e => {
    // Standard-Form oder Quick-Capture Assignee
    if ((e.target.id === 'assignee_user_id' || e.target.id === 'qc_assignee_user_id') && (e.target.closest('#pendenz-form') || e.target.closest('#qcForm'))) {
        const opt = e.target.options[e.target.selectedIndex];
        const context = e.target.id === 'qc_assignee_user_id' ? 'qc' : 'form';
        
        // Auto-load BKP (Mehrfach-IDs unterstützt)
        if (opt && opt.dataset.bkp) {
            loadBkpCategories(opt.dataset.bkp, context);
        }
        
        // Auto-select Category if bkp_name matches (für das Hauptformular)
        if (opt && opt.dataset.bkpName && context === 'form') {
            const katSel = document.getElementById('kategorie_id');
            if (katSel) {
                for (let i = 0; i < katSel.options.length; i++) {
                    if (katSel.options[i].text.trim().toLowerCase() === opt.dataset.bkpName.trim().toLowerCase()) {
                        katSel.selectedIndex = i;
                        katSel.dispatchEvent(new Event('change'));
                        break;
                    }
                }
            }
        }
    }
});

function filterMembers(context = 'form', defUser = 0) {
    const isFilter = (context === 'filter');
    const isInline = (context === 'inline');
    const isQc     = (context === 'qc');
    
    let aSelector = '#vorgangsart_id';
    let mSelector = '#assignee_user_id';
    
    if (isFilter) {
        aSelector = '#filter_vorgangsart_id';
        mSelector = '#filter_zustaendig_id';
    }
    if (isInline) {
        aSelector = '.art-select';
        mSelector = '.member-select';
    }
    if (isQc) {
        aSelector = '#qc_vorgangsart_id'; 
        mSelector = '#qc_assignee_user_id';
    }

    const aSel = document.querySelector(aSelector);
    let allowedTypes = null;

    if (isQc) {
        const activeBtn = document.querySelector('.qc-type-btn.active');
        const allowedTypesStr = activeBtn ? (activeBtn.dataset.allowedTypes || '') : '';
        allowedTypes = allowedTypesStr ? allowedTypesStr.split(',') : null;
    } else {
        const selectedArtOpt = (aSel && aSel.options) ? aSel.options[aSel.selectedIndex !== undefined ? aSel.selectedIndex : 0] : null;
        const allowedTypesStr = selectedArtOpt ? (selectedArtOpt.dataset.allowedTypes || '') : '';
        allowedTypes = allowedTypesStr ? allowedTypesStr.split(',') : null;
    }

    const mSels = document.querySelectorAll(mSelector);
    if (!mSels.length) return;

    mSels.forEach(mSel => {
        const cur = mSel.value || defUser;
        mSel.innerHTML = isFilter ? '<option value="">— Alle —</option>' : '<option value="">— bitte wählen —</option>';
        
        allMembers.forEach(m => {
            if (allowedTypes && !allowedTypes.includes(m.business_type)) return;
            const opt = new Option(m.name, m.id);
            opt.dataset.bkp = m.bkp_ids || '';
            opt.dataset.bkpName = m.bkp_name || '';
            if (String(m.id) === String(cur)) opt.selected = true;
            mSel.add(opt);
        });
    });
}

async function updateRooms(wid, defRid = null) {
    if (!wid) {
        const rSel = document.getElementById('raum_id');
        if (rSel) rSel.innerHTML = '<option value="">— bitte wählen —</option>';
        return;
    }
    try {
        const res = await fetch(`pendenzen.php?action=rooms&wohnung_id=${wid}`);
        const js = await res.json();
        const rSel = document.getElementById('raum_id');
        if (rSel) {
            rSel.innerHTML = '<option value="">— bitte wählen —</option>';
            if (js.ok) (js.items || []).forEach(r => {
                const opt = new Option(r.name, r.id);
                if (String(r.id) === String(defRid)) opt.selected = true;
                rSel.add(opt);
            });
        }
    } catch(e) { console.error("Room update failed", e); }
    syncFolderPath();
}

async function updateRoomsInline(el) {
    const wid = el.value;
    const row = el.closest('tr');
    const rSel = row.querySelector('.raum-select');
    if (!rSel) return;
    if (!wid) {
        rSel.innerHTML = '<option value="">Raum…</option>';
        return;
    }
    try {
        const res = await fetch(`pendenzen.php?action=rooms&wohnung_id=${wid}`);
        const js = await res.json();
        rSel.innerHTML = '<option value="">Raum…</option>';
        if (js.ok) (js.items || []).forEach(r => rSel.add(new Option(r.name, r.id)));
    } catch(e) { console.error("Inline room update failed", e); }
}

function syncFolderPath() {
    const pSel = document.getElementById('projekt_id');
    const oSel = document.getElementById('objekt_id');
    const bSel = document.getElementById('fs_branch');
    const wSel = document.getElementById('wohnung_id');
    const fsIn = document.getElementById('fs_rel_path');
    if (!fsIn) return;

    let path = '';
    const oPath = (oSel && oSel.options && oSel.options[oSel.selectedIndex]) ? oSel.options[oSel.selectedIndex].dataset.path : '';
    const branch = bSel ? bSel.value : '10_Mietsache';
    const wPath = (wSel && wSel.options && wSel.options[wSel.selectedIndex]) ? wSel.options[wSel.selectedIndex].dataset.path : '';

    if (oPath) {
        path = oPath + '/' + branch;
        if (branch === '10_Mietsache' && wPath) {
            // Include unit path
            path += '/' + wPath;
        }
    }
    if (fsIn) fsIn.value = path;
}

function toggleUnitSelect(branch) {
    const wrap = document.getElementById('unit_select_wrap');
    if (!wrap) return;
    if (branch === '10_Mietsache') {
        wrap.style.display = 'block';
    } else {
        wrap.style.display = 'none';
        const wSel = document.getElementById('wohnung_id');
        if (wSel) wSel.value = ''; // Reset unit if not in Mietsache
    }
}

// --- PROJECT & FOLDER CONTEXT EVENTS ---
function initEventListeners() {
    const pIdEl = document.getElementById('projekt_id');
    if (pIdEl) pIdEl.addEventListener('change', async function(e) { 
        const oSel = document.getElementById('objekt_id');
        const wSel = document.getElementById('wohnung_id');
        if (oSel) oSel.value = "";
        if (wSel) wSel.value = "";
        await updateProjectContext(e.target.value, 'form'); 
        syncFolderPath();
    });

    const oIdEl = document.getElementById('objekt_id');
    if (oIdEl) oIdEl.addEventListener('change', function(e) {
        filterUnitsByObject(e.target.value);
        syncFolderPath();
    });

    const fPIdEl = document.getElementById('filter_projekt_id');
    if (fPIdEl) fPIdEl.addEventListener('change', async function(e) {
        await updateProjectContext(e.target.value, 'filter');
    });

    const fOIdEl = document.getElementById('filter_objekt_id');
    if (fOIdEl) fOIdEl.addEventListener('change', function(e) {
        const pidVal = fPIdEl ? fPIdEl.value : 0;
        updateProjectContext(pidVal, 'filter', e.target.value);
    });

    const wIdEl = document.getElementById('wohnung_id');
    if (wIdEl) wIdEl.addEventListener('change', function(e) {
        updateRooms(e.target.value);
        syncFolderPath();
    });

    const fsBEl = document.getElementById('fs_branch');
    if (fsBEl) fsBEl.addEventListener('change', function() {
        syncFolderPath();
    });

    const iPIdEl = document.getElementById('inline_project_id');
    if (iPIdEl) iPIdEl.addEventListener('change', function(e) {
        updateProjectContext(e.target.value, 'inline');
    });
}
initEventListeners();

function filterUnitsByObject(oid) {
    const wSel = document.getElementById('wohnung_id');
    if (!wSel) return;
    Array.from(wSel.options).forEach(opt => {
        if (opt.value === "") return; // Default empty
        const pOid = opt.dataset.objektId;
        if (!oid || String(pOid) === String(oid)) {
            opt.style.display = '';
        } else {
            opt.style.display = 'none';
            if (opt.selected) wSel.value = ''; // Deselect if hidden
        }
    });
}


async function loadBkpCategories(bkpCode, context = 'form') {
    const prefix = context === 'qc' ? 'qc_' : '';
    const catSel = document.getElementById(prefix + 'bkp_cat_id');
    const tplSel = document.getElementById(prefix + 'bkp_tpl_id');
    if(!catSel) return;
    
    catSel.innerHTML = '<option value="">— Lädt... —</option>';
    if (tplSel) tplSel.innerHTML = '<option value="">— Vorlage wählen —</option>';
    
    try {
        const res = await fetch(`pendenzen.php?action=bkp_hierarchy&bkp=${bkpCode}`);
        const js = await res.json();
        catSel.innerHTML = '<option value="">— Gewerk wählen —</option>';
        if(js.ok && js.kategorien.length > 0) {
            js.kategorien.forEach(k => {
                const opt = new Option(k.name, k.id);
                catSel.add(opt);
            });
            // Falls nur 1 Kategorie, direkt laden
            if(js.kategorien.length === 1) {
                catSel.selectedIndex = 1;
                loadBkpTemplates(js.kategorien[0].id, context);
            }
        } else {
            catSel.innerHTML = '<option value="">(Keine BKP-Kategorien)</option>';
        }
    } catch(e) { console.error("BKP Cat load failed", e); }
}

async function loadBkpTemplates(catId, context = 'form') {
    const prefix = context === 'qc' ? 'qc_' : '';
    const tplSel = document.getElementById(prefix + 'bkp_tpl_id');
    if(!tplSel) return;
    
    tplSel.innerHTML = '<option value="">— Lädt... —</option>';
    try {
        const res = await fetch(`pendenzen.php?action=bkp_hierarchy&kategorie_id=${catId}`);
        const js = await res.json();
        tplSel.innerHTML = '<option value="">— Vorlage wählen —</option>';
        if(js.ok && js.templates.length > 0) {
            js.templates.forEach(t => {
                const opt = new Option(t.text, t.id);
                tplSel.add(opt);
            });
        }
    } catch(e) { console.error("BKP Tpl load failed", e); }
}

function applyBkpTemplate(sel, context = 'form') {
    const text = (sel.options && sel.options[sel.selectedIndex]) ? sel.options[sel.selectedIndex].text : null;
    if(!text || sel.value === "") return;
    
    const selector = context === 'qc' ? '#qcForm input[name="titel"]' : '#pendenz-form input[name="titel"]';
    const titleIn = document.querySelector(selector);
    if(titleIn) titleIn.value = text;
}

// --- QUICK CHOICES ---
window.updateQuickChoices = (catId) => {
    // 1. Hardcoded Defaults
    const defaults = {
        "1": ["Wand Riss", "Farbe fehlt", "Feuchte Stelle", "Putz lose"],
        "2": ["Steckdose locker", "Kabel fehlt", "Licht defekt", "Sicherung"],
        "3": ["Tür klemmt", "Boden Kratzer", "Sockelleiste lose", "Fenster hakt"],
        "4": ["Siphon undicht", "Hahn tropft", "WC hakt", "Fuge offen"]
    };
    
    let currentChoices = defaults[catId] || [];

    // 2. Layer specific overrides from config
    if (window.layerQuickChoices) {
        const lines = window.layerQuickChoices.split('\n');
        const catOpt = document.querySelector('.kategorie-select option[value="' + catId + '"]');
        const catName = catOpt ? catOpt.textContent : '';
        
        lines.forEach(line => {
            if (line.includes(':')) {
                const [cPart, tPart] = line.split(':');
                if (cPart.trim().toLowerCase() === catName.trim().toLowerCase()) {
                    currentChoices.push(tPart.trim());
                }
            } else if (!catId) {
                // If no category selected, show all generic choices from layer
                currentChoices.push(line.trim());
            }
        });
    }

    // Remove duplicates
    currentChoices = [...new Set(currentChoices)];

    // PERFORMANCE FIX: Only update the containers in the ACTIVE elements (Inline row or Drawer)
    const activeContainers = [
        document.querySelector('#inlineNewRow .quick-choices'),
        document.getElementById('qChoices')
    ].filter(Boolean);

    activeContainers.forEach(container => {
        container.innerHTML = '';
        if (currentChoices.length) {
            currentChoices.forEach(text => {
                const pill = document.createElement('div');
                pill.className = 'quick-choice-pill';
                pill.textContent = text;
                pill.onclick = () => {
                    const row = container.closest('tr') || document.querySelector('.quick-drawer');
                    const input = row.querySelector(`[name="${container.dataset.field}"]`) || document.getElementById('qTitle');
                    if (input) {
                        input.value = text;
                        input.focus();
                    }
                };
                container.appendChild(pill);
            });
        }
    });
};



document.addEventListener('change', async e => {
    if (e.target.matches('.kategorie-select') || e.target.id === 'kategorie_id') {
        const catId = e.target.value;
        const form = e.target.closest('form') || e.target.closest('tr');
        const sub = form.querySelector('[name="unterkategorie_id"]') || document.getElementById('unterkategorie_id');
        
        if (catId) {
            updateQuickChoices(catId);
            if (sub) {
                sub.disabled = true;
                sub.innerHTML = '<option value="">Lade...</option>';
                try {
                    const res = await fetch(`pendenzen.php?action=subcats&kategorie_id=${catId}`);
                    const js = await res.json();
                    sub.innerHTML = '<option value="">Unterkategorie…</option>';
                    if (js.ok && js.items) {
                        js.items.forEach(i => {
                            const opt = new Option(i.name, i.id);
                            sub.add(opt);
                        });
                        sub.disabled = false;
                    }
                } catch(e) { console.error(e); }
            }
        } else {
            if (sub) { sub.innerHTML = '<option value="">Unterkategorie…</option>'; sub.disabled = true; }
        }
    }
});


// --- MEDIA HELPERS ---
async function setCover(fid, pid) {
    try {
        const res = await fetch(`pendenzen.php?action=media_set_cover&id=${pid}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file_id=${fid}`
        });
        const js = await res.json();
        if (js.ok) {
            document.querySelectorAll(`.btn-cover-star[data-pid="${pid}"]`).forEach(b => {
                b.classList.remove('is-active');
                b.textContent = '☆';
            });
            const active = document.querySelector(`.btn-cover-star[data-fid="${fid}"]`);
            if (active) { active.classList.add('is-active'); active.textContent = '★'; }
            location.reload(); 
        } else alert(js.error);
    } catch (e) { console.error(e); }
}

async function deleteMedia(fid, pid, type) {
    if (!confirm('Datei wirklich löschen?')) return;
    try {
        const res = await fetch(`pendenzen.php?action=media_delete&id=${pid}`, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: `file_id=${fid}`
        });
        if ((await res.json()).ok) {
            loadMediaLists('file', 'mediaFiles');
            loadMediaLists('image', 'mediaImages');
        }
    } catch (e) { console.error(e); }
}

window.loadMediaLists = async (type, intoId) => {
    const id = null;
    if (id <= 0) return;
    const into = document.getElementById(intoId);
    if (!into) return;

    const res = await fetch(`pendenzen.php?action=media_list&id=${id}`);
    const js = await res.json();
    if (!js.ok) return;

    const items = (js.items || []).filter(x => type === 'image' ? x.typ === 'image' : x.typ !== 'image');
    if (type === 'image') {
        into.className = 'media-grid-smart';
        into.innerHTML = items.map(x => `
            <div class="media-tile-smart" style="position:relative; width:100px;">
                <img src="null${x.pfad}" style="width:100%; height:80px; object-fit:cover; border-radius:6px;">
                <button type="button" class="btn-tile-delete" onclick="deleteMedia(${x.id}, ${id}, 'image')">✖</button>
                <div style="font-size:10px; text-align:center; margin-top:4px;">
                    <button type="button" class="btn-cover-star ${x.is_cover == 1 ? 'is-active' : ''}" 
                            data-fid="${x.id}" data-pid="${id}" onclick="setCover(${x.id}, ${id})">
                        ${x.is_cover == 1 ? '★' : '☆'}
                    </button>
                    ${h(x.titel || '')}
                </div>
            </div>
        `).join('');
    } else {
        into.innerHTML = items.map(x => `
            <div style="display:flex; justify-content:space-between; align-items:center; background:#f8fafc; padding:6px 10px; border-radius:6px; margin-bottom:4px; border:1px solid #e2e8f0; font-size:12px;">
                <span>📎 ${x.titel || x.pfad.split('/').pop()}</span>
                <button type="button" class="btn btn-danger btn-xxs" onclick="deleteMedia(${x.id}, ${id}, 'file')">Löschen</button>
            </div>
        `).join('');
    }
};

(async () => {
    await window.loadMediaLists('file', 'mediaFiles');
    await window.loadMediaLists('image', 'mediaImages');
})();


// --- UI HELPERS ---
function toggleAdvancedFields(el) {
    const fields = el.nextElementSibling;
    if (fields.style.display === 'none') {
        fields.style.display = 'flex';
        el.querySelector('span').textContent = '➖ Weniger Details';
    } else {
        fields.style.display = 'none';
        el.querySelector('span').textContent = '➕ Erweiterte Details & Notiz';
    }
}

// --- SPREADSHEET INLINE EDITOR ---
(function() {
    let currentInput = null;

    document.addEventListener('click', e => {
        const td = e.target.closest('.inline-editable');
        if (td && !td.querySelector('.inline-editor')) startEditing(td);
    });

    window.startEditing = async (td) => {
        const field = td.dataset.field;
        const id = td.dataset.id;
        const type = td.dataset.type || 'text';
        const oldVal = td.dataset.value || td.textContent.trim();
        const isArea = field.includes('beschreibung') || field === 'notiz';

        let input;
        const opts = window.globalOptions[field];

        if (opts && (field.includes('_id') || field === 'tageszeit' || field === 'status')) {
            input = document.createElement('select');
            input.add(new Option("— leer —", ""));
            opts.forEach(o => {
                const opt = new Option(o.name || o, o.id || o);
                if (String(opt.value) === String(oldVal)) opt.selected = true;
                input.add(opt);
            });
        } else if (isArea) {
            input = document.createElement('textarea');
            input.value = oldVal;
            input.rows = 4;
        } else {
            input = document.createElement('input');
            input.type = type;
            input.value = oldVal;
            input.name = field; // Wichtig für den Trigger
            if (['startdatum','enddatum','dauer'].includes(field)) {
                input.classList.add('date-calc-trigger');
            }
        }

        input.className += ' inline-editor';
        const originalContent = td.innerHTML;

        const finish = async () => {
            if (currentInput !== input) return;
            const newVal = input.value;
            currentInput = null;

            if (newVal === oldVal) { td.innerHTML = originalContent; return; }

            td.innerHTML = '<span style="color:#0ea5e9; font-size:10px; font-weight:800;">SYNC...</span>';
            
            let bodyData = { id, field, value: newVal };
            
            // SPECIAL: Wenn es ein Termin-Feld ist, schicken wir das Trio
            if (['startdatum','enddatum','dauer'].includes(field)) {
                const getEl = (f) => td.closest('tr').querySelector(`[name="${f}"], [data-field="${f}"]`);
                const getVal = (el) => {
                    if (!el) return '';
                    if (el.tagName === 'INPUT' || el.tagName === 'SELECT') return el.value;
                    return el.dataset.value || el.innerText;
                };
                bodyData = {
                    id,
                    updates: {
                        startdatum: getVal(getEl('startdatum')),
                        enddatum:   getVal(getEl('enddatum')),
                        dauer:      getVal(getEl('dauer'))
                    }
                };
            }

            try {
                const res = await fetch('../api/pendenzen_inline_save.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(bodyData)
                });
                const js = await res.json();
                if (js.ok) {
                    td.innerText = (input.tagName === 'SELECT') ? input.options[input.selectedIndex].text : newVal;
                    td.dataset.value = newVal;
                    td.style.background = '#dcfce7';
                    setTimeout(() => td.style.background = '', 800);
                } else {
                    alert(js.error || "Fehler");
                    td.innerHTML = originalContent;
                }
            } catch (e) { td.innerHTML = originalContent; }
        };

        td.innerHTML = ''; td.appendChild(input); input.focus(); currentInput = input;
        input.onblur = finish;
        input.onkeydown = (ev) => {
            if (ev.key === 'Enter' && !isArea) {
                ev.preventDefault(); input.blur();
                const pn = td.parentElement.nextElementSibling;
                const nextRowTd = pn ? pn.querySelector('.inline-editable[data-field="' + field + '"]') : null;
                if (nextRowTd) setTimeout(() => startEditing(nextRowTd), 50);
            }
            if (ev.key === 'Tab') {
                ev.preventDefault(); input.blur();
                const ln = td.nextElementSibling;
                const lp = td.parentElement.nextElementSibling;
                const nextTd = (ln ? ln.closest('.inline-editable') : null) || (lp ? lp.querySelector('.inline-editable') : null);
                if (nextTd) setTimeout(() => startEditing(nextTd), 50);
            }
            if (ev.key === 'Escape') { currentInput = null; td.innerHTML = originalContent; }
        };
    };
})();

// --- INLINE CREATE LOGIC ---
document.getElementById('inlineCreateBtn') && document.getElementById('inlineCreateBtn').addEventListener('click', async () => {
    const row = document.getElementById('inlineNewRow');
    const pidEl = document.getElementById('inline_project_id');
    const pid = pidEl ? pidEl.value : null;
    const msg = document.getElementById('inlineMsg');
    if (!pid) { alert("Bitte Projekt wählen"); return; }

    const fd = new FormData();
    fd.append('projekt_id', pid);
    fd.append('inline_new', '1');
    row.querySelectorAll('input, select, textarea').forEach(el => {
        if (el.name) {
            if (el.type === 'file') {
                Array.from(el.files).forEach(f => fd.append(el.name, f));
            } else {
                fd.append(el.name, el.value);
            }
        }
    });

    if (msg) msg.textContent = "⏳ Speichern...";
    try {
        const res = await fetch('pendenzen.php', { method: 'POST', body: fd });
        const js = await res.json();
        if (js.ok) {
            if (msg) { msg.style.color = 'green'; msg.textContent = "✅ Erfolgreich!"; }
            setTimeout(() => location.reload(), 800);
        } else {
            if (msg) { msg.style.color = 'red'; msg.textContent = "❌ " + (js.error || "Fehler"); }
        }
    } catch (e) { if (msg) msg.textContent = "❌ Server-Fehler"; }
});

// --- WIDGET HELPERS ---
window.cycleStatus = async (id, cur) => {
    const states = ["offen", "in Bearbeitung", "erledigt", "archiviert", "wartend"];
    const next = states[(states.indexOf(cur) + 1) % states.length];
    const res = await fetch('../api/pendenzen_inline_save.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, field: 'status', value: next })
    });
    if ((await res.json()).ok) location.reload();
};

window.updatePrio = async (id, val) => {
    const res = await fetch('../api/pendenzen_inline_save.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, field: 'wichtigkeit', value: val })
    });
    if ((await res.json()).ok) {
        document.querySelectorAll(`.star-rating[data-id="${id}"] span`).forEach((s, i) => {
            s.style.color = (i < val) ? '#f59e0b' : '#cbd5e1';
        });
    }
};

// --- SCHNELL-ERFASSUNG LOGIC ---
window.openQuickCapture = () => {
    const overlay = document.getElementById('quickCaptureOverlay');
    if (!overlay) return;
    overlay.style.display = 'flex';
    
    // Auto-fill from dashboard filters
    const pidEl = document.getElementById('filter_projekt_id');
    const oidEl = document.getElementById('filter_objekt_id');
    const widEl = document.getElementById('filter_wohnung_id');
    const pid = pidEl ? pidEl.value : null;
    const oid = oidEl ? oidEl.value : null;
    const wid = widEl ? widEl.value : null;
    
    if (pid) {
        const qc_pid = document.getElementById('qc_projekt_id');
        if (qc_pid) {
            qc_pid.value = pid;
            updateProjectContext(pid, 'qc', oid, wid);
        }
    }
    
    // Set current context text
    const contextInfo = document.getElementById('qcContextText');
    if (contextInfo) {
        const pSel_qc = document.querySelector('select[name="projekt_id"]');
        const pName = (pSel_qc && pSel_qc.options[pSel_qc.selectedIndex]) ? pSel_qc.options[pSel_qc.selectedIndex].text : 'Kein Projekt';
        const uSel_qc = document.querySelector('#filter_wohnung_id');
        const uName = (uSel_qc && uSel_qc.options[uSel_qc.selectedIndex]) ? uSel_qc.options[uSel_qc.selectedIndex].text : 'Alle Einheiten';
        contextInfo.innerHTML = "📍 " + pName + " > " + uName;
    }
};

window.closeQuickCapture = () => {
    document.getElementById('quickCaptureOverlay').style.display = 'none';
};

window.setQcType = (id, el) => {
    document.querySelectorAll('.qc-type-btn').forEach(b => b.classList.remove('active'));
    el.classList.add('active');
    document.getElementById('qc_vorgangsart_id').value = id;
    filterMembers('qc');
};


// --- DRAG & DROP REORDER ---
if (null) {
    const tbody = document.querySelector('#pendenzenTable tbody');
    let dragRow = null;

    tbody.addEventListener('dragstart', e => {
        dragRow = e.target.closest('tr');
        if (dragRow) dragRow.classList.add('dragging');
    });
    tbody.addEventListener('dragend', () => { if (dragRow) dragRow.classList.remove('dragging'); });
    
    const reorderBtn = document.getElementById('reorderSave');
    if (reorderBtn) reorderBtn.addEventListener('click', async () => {
        const ids = Array.from(tbody.querySelectorAll('tr[data-id]')).map(tr => tr.dataset.id);
        const fd = new FormData();
        ids.forEach(id => fd.append('ids[]', id));
        const res = await fetch('pendenzen.php?action=reorder', { method: 'POST', body: fd });
        if ((await res.json()).ok) alert("Reihenfolge gespeichert!");
    });
}
