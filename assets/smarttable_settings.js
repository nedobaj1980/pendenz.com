(function(){
  const $  = (s, c=document)=> c.querySelector(s);
  const $$ = (s, c=document)=> Array.from(c.querySelectorAll(s));

  const API = "/pendenz.com/api/smarttable_api.php";

  const elTableSel   = $("#sts-table");
  const elPageSize   = $("#sts-page-size");
  const elTableTitle = $("#sts-table-title");
  const elTableName  = $("#sts-table-name");
  const elColList    = $("#sts-col-list");
  const elForm       = $("#sts-col-form");

  let tables = [];
  let cols = [];
  let curTable = null;
  let dragging = null;

  async function fetchTables(){
    const res = await fetch(`${API}?act=tables`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error||"tables");
    tables = data.tables || [];
    elTableSel.innerHTML = tables.map(t=> `<option value="${t.id}">${t.title} (${t.table_name})</option>`).join("");
    if (!tables.length) return;
    loadTable(tables[0].id);
  }

  async function loadTable(id){
    curTable = tables.find(t=> String(t.id)===String(id));
    if (!curTable) return;
    elTableSel.value = String(curTable.id);
    elPageSize.value = curTable.page_size || 20;
    elTableTitle.value= curTable.title || "";
    elTableName.value = curTable.table_name || "";

    const res = await fetch(`${API}?act=columns&table_id=${curTable.id}`);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error||"columns");
    cols = data.columns || [];
    renderCols();
    clearForm();
  }

  function renderCols(){
    elColList.innerHTML = cols.map(c=> `
      <li class="sts-item" draggable="true" data-id="${c.id}">
        <span class="sts-handle" title="Ziehen">↕</span>
        <span class="sts-item-main">
          <strong>${c.label}</strong>
          <em>${c.field}</em>
          <code>${c.type}${c.visible? ' • sichtbar':''}</code>
        </span>
        <button class="sts-btn sts-btn--sm" data-edit="${c.id}">Bearbeiten</button>
      </li>
    `).join("");
  }

  function clearForm(){
    elForm.reset();
    $("#col-id").value = "";
    $("#col-step").value = "";
    $("#col-options").value = "";
    $("#col-visible").checked = true;
    showTypeSections($("#col-type").value);
  }

  function showTypeSections(type){
    $$('[data-if]').forEach(box=>{
      box.style.display = (box.getAttribute('data-if')===type ? '' : 'none');
    });
  }

  // Drag & Drop Reihenfolge
  elColList.addEventListener("dragstart",(e)=>{
    const li = e.target.closest("li"); if (!li) return;
    dragging = li; li.classList.add("dragging");
    e.dataTransfer.setData("text/plain", li.dataset.id);
  });
  elColList.addEventListener("dragover",(e)=>{
    if (!dragging) return; e.preventDefault();
    const after = getDragAfterElement(elColList, e.clientY);
    if (after==null) elColList.appendChild(dragging);
    else elColList.insertBefore(dragging, after);
  });
  elColList.addEventListener("dragend",()=>{ dragging?.classList.remove("dragging"); dragging=null; });

  function getDragAfterElement(container, y){
    const els = [...container.querySelectorAll(".sts-item:not(.dragging)")];
    return els.reduce((closest, child)=>{
      const box = child.getBoundingClientRect();
      const offset = y - box.top - box.height/2;
      if (offset < 0 && offset > closest.offset) return {offset, element: child};
      return closest;
    }, {offset: Number.NEGATIVE_INFINITY}).element;
  }

  // Edit-Button
  elColList.addEventListener("click",(e)=>{
    const id = e.target.dataset.edit;
    if (!id) return;
    const c = cols.find(x=> String(x.id)===String(id));
    if (!c) return;
    $("#col-id").value = c.id;
    $("#col-field").value = c.field;
    $("#col-label").value = c.label;
    $("#col-type").value  = c.type;
    $("#col-step").value  = c.step ?? "";
    $("#col-validate").value = c.validate ?? "";
    $("#col-visible").checked = !!c.visible;
    $("#col-options").value = (c.options||[]).join("\n");
    showTypeSections(c.type);
  });

  // Neuer Datensatz
  $("#btn-col-new").addEventListener("click", ()=> clearForm());

  // Spalte löschen
  $("#btn-col-delete").addEventListener("click", async ()=>{
    const id = $("#col-id").value;
    if (!id) return;
    if (!confirm("Spalte wirklich löschen?")) return;
    const res = await fetch(API+"?act=column.delete", {method:"POST", body: JSON.stringify({id: Number(id)})});
    const data = await res.json();
    if (!data.ok) return alert(data.error||"Fehler");
    cols = cols.filter(c=> String(c.id)!==String(id));
    renderCols(); clearForm();
  });

  // Spalte speichern
  elForm.addEventListener("submit", async (e)=>{
    e.preventDefault();
    if (!curTable) return;
    const id = $("#col-id").value ? Number($("#col-id").value) : undefined;
    const field = $("#col-field").value.trim();
    const label = $("#col-label").value.trim();
    const type = $("#col-type").value;
    const visible = $("#col-visible").checked ? 1 : 0;
    const step = $("#col-step").value === "" ? null : Number($("#col-step").value);
    const validate = $("#col-validate").value.trim() || null;
    const options = type==="select" ? $("#col-options").value.split(/\r?\n/).map(s=>s.trim()).filter(Boolean) : null;
    const position = (id ? (cols.find(c=>c.id===id)?.position||0) : (cols.length ? Math.max(...cols.map(c=>c.position||0))+1 : 1));

    const res = await fetch(API+"?act=column.upsert", {
      method:"POST",
      headers:{ "Content-Type":"application/json; charset=utf-8" },
      body: JSON.stringify({ id, table_id: curTable.id, field, label, type, visible, step, validate, options, position })
    });
    const data = await res.json();
    if (!data.ok) return alert(data.error||"Fehler");
    const savedId = data.id;
    // Reload columns
    await loadTable(curTable.id);
    // Re-select edited
    const li = elColList.querySelector(`li[data-id="${savedId}"]`);
    li?.scrollIntoView({block:"center"});
  });

  // Reihenfolge speichern
  $("#btn-col-save-order").addEventListener("click", async ()=>{
    if (!curTable) return;
    const ids = $$("li", elColList).map(li=> Number(li.dataset.id));
    const res = await fetch(API+"?act=column.reorder", {
      method:"POST",
      headers:{ "Content-Type":"application/json; charset=utf-8" },
      body: JSON.stringify({ table_id: curTable.id, ids })
    });
    const data = await res.json();
    if (!data.ok) return alert(data.error||"Fehler");
    await loadTable(curTable.id);
  });

  // Typ zeigt Felder ein/aus
  $("#col-type").addEventListener("change", (e)=> showTypeSections(e.target.value));

  // Tabelle wechseln
  elTableSel.addEventListener("change", (e)=> loadTable(e.target.value));

  // Neue Tabelle
  $("#btn-table-new").addEventListener("click", async ()=>{
    elTableName.value = ""; elTableTitle.value = ""; elPageSize.value = 20;
    curTable = null;
    elTableSel.value = "";
    elColList.innerHTML = "";
    clearForm();
    elTableName.focus();
  });

  // Tabelle speichern
  $("#btn-table-save").addEventListener("click", async ()=>{
    const id = curTable?.id || undefined;
    const table_name = elTableName.value.trim();
    const title = elTableTitle.value.trim();
    const page_size = Number(elPageSize.value||20);
    if (!table_name || !title) return alert("Table Name & Titel sind Pflicht.");
    const res = await fetch(API+"?act=table.upsert", {
      method:"POST",
      headers:{ "Content-Type":"application/json; charset=utf-8" },
      body: JSON.stringify({ id, table_name, title, page_size })
    });
    const data = await res.json();
    if (!data.ok) return alert(data.error||"Fehler");
    await fetchTables();
    // Auswahl auf gespeicherte Tabelle setzen
    elTableSel.value = String(data.id);
    loadTable(data.id);
  });

  // Tabelle löschen
  $("#btn-table-del").addEventListener("click", async ()=>{
    if (!curTable?.id) return;
    if (!confirm("Diese Tabelle inkl. Spaltenkonfiguration löschen?")) return;
    const res = await fetch(API+"?act=table.delete", { method:"POST", body: JSON.stringify({ id: curTable.id })});
    const data = await res.json();
    if (!data.ok) return alert(data.error||"Fehler");
    await fetchTables();
  });

  // Init
  document.addEventListener("DOMContentLoaded", fetchTables);
})();
