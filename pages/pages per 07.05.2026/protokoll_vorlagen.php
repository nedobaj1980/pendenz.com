<?php
// pages/protokoll_vorlagen.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

// Typen laden
$resTypen = $mysqli->query("SELECT * FROM protokoll_typen ORDER BY name");
$typen = $resTypen->fetch_all(MYSQLI_ASSOC);

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
    .admin-container { padding: 40px; background: #f8fafc; min-height: calc(100vh - 100px); font-family: 'Inter', sans-serif; }
    .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px; }
    .page-title { margin: 0; font-size: 28px; font-weight: 900; color: #0f172a; }
    
    .type-section { margin-bottom: 50px; }
    .type-header { display: flex; align-items: center; gap: 15px; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; }
    .type-title { font-size: 18px; font-weight: 800; color: #1e293b; text-transform: uppercase; letter-spacing: 1px; }
    
    .template-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 25px; }
    .template-card { 
        background: #fff; border-radius: 16px; padding: 25px; border: 1px solid #e2e8f0; 
        box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); transition: all 0.2s; 
        display: flex; flex-direction: column; justify-content: space-between;
    }
    .template-card:hover { transform: translateY(-5px); box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1); border-color: #1abc9c; }
    
    .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
    .template-name { font-size: 16px; font-weight: 700; color: #0f172a; margin: 0; }
    
    .card-actions { display: flex; gap: 10px; margin-top: 20px; padding-top: 15px; border-top: 1px solid #f1f5f9; }
    .btn-icon { 
        width: 40px; height: 40px; border-radius: 10px; border: none; cursor: pointer; 
        display: flex; align-items: center; justify-content: center; font-size: 16px; transition: 0.2s;
    }
    .btn-edit { background: #f1f5f9; color: #475569; }
    .btn-edit:hover { background: #e2e8f0; color: #0f172a; }
    .btn-design { background: #e0f2fe; color: #0284c7; flex: 1; font-weight: 700; font-size: 12px; display: flex; gap: 8px; }
    .btn-design:hover { background: #0284c7; color: #fff; }
    .btn-delete { background: #fee2e2; color: #ef4444; }
    .btn-delete:hover { background: #ef4444; color: #fff; }

    .btn-add-main { background: #1abc9c; color: #fff; border: none; padding: 12px 25px; border-radius: 10px; font-weight: 800; cursor: pointer; transition: 0.2s; }
    .btn-add-main:hover { background: #16a085; transform: scale(1.05); }
</style>

<div class="admin-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">Protokoll-Vorlagen verwalten</h1>
            <p style="color:#64748b; margin-top:5px;">Erstellen und gestalten Sie Ihre Master-Masken für alle Protokolltypen.</p>
        </div>
        <button class="btn-add-main" onclick="addNewTemplate()">➕ NEUE VORLAGE</button>
    </div>

    <div id="templates-content">
        <!-- Wird per JS geladen -->
    </div>
</div>

<script>
    async function loadTemplates() {
        const resp = await fetch('../api/manage_protocol_templates.php?action=list');
        const res = await resp.json();
        if (res.success) {
            const container = document.getElementById('templates-content');
            container.innerHTML = '';

            const types = {};
            res.templates.forEach(t => {
                if (!types[t.typ_id]) types[t.typ_id] = { name: t.typ_name, icon: t.typ_icon, templates: [] };
                types[t.typ_id].templates.push(t);
            });

            for (const typId in types) {
                const type = types[typId];
                const section = document.createElement('div');
                section.className = 'type-section';
                
                let cardsHtml = '';
                type.templates.forEach(t => {
                    const formCount = res.forms.filter(f => f.vorlage_id == t.id).length;
                    cardsHtml += `
                        <div class="template-card">
                            <div class="card-header">
                                <div>
                                    <h3 class="template-name">${t.name}</h3>
                                    <span style="font-size:11px; color:#94a3b8;">${formCount} gespeicherte Dokumente</span>
                                </div>
                                <span style="font-size:20px;">📄</span>
                            </div>
                            <div class="card-actions">
                                <button class="btn-icon btn-design" onclick="location.href='protokoll_designer.php?id=${t.id}'">
                                    <span>🎨</span> DESIGN VERFEINERN
                                </button>
                                <button class="btn-icon btn-edit" title="Umbenennen" onclick="renameTemplate(${t.id}, '${t.name}')">✏️</button>
                                <button class="btn-icon btn-delete" title="Löschen" onclick="deleteTemplate(${t.id})">🗑️</button>
                            </div>
                        </div>
                    `;
                });

                section.innerHTML = `
                    <div class="type-header">
                        <span style="font-size:24px;">${type.icon}</span>
                        <h2 class="type-title">${type.name}</h2>
                    </div>
                    <div class="template-grid">${cardsHtml}</div>
                `;
                container.appendChild(section);
            }
        }
    }

    async function renameTemplate(id, oldName) {
        const newName = prompt("Neuer Name für die Vorlage:", oldName);
        if (!newName || newName === oldName) return;
        await fetch('../api/manage_protocol_templates.php?action=update_template', {
            method: 'POST',
            body: JSON.stringify({ id: id, name: newName })
        });
        loadTemplates();
    }

    async function deleteTemplate(id) {
        if (!confirm("Diese Vorlage wirklich löschen? Alle zugehörigen Dokumente gehen verloren!")) return;
        await fetch('../api/manage_protocol_templates.php?action=delete_template&id=' + id);
        loadTemplates();
    }

    async function addNewTemplate() {
        const name = prompt("Name der neuen Vorlage:");
        if (!name) return;
        
        // Typ wählen
        const types = <?= json_encode($typen) ?>;
        let typeList = "Bitte Typ-Nummer wählen:\n";
        types.forEach((t, i) => { typeList += (i+1) + ". " + t.name + "\n"; });
        const typeIdx = prompt(typeList);
        if (!typeIdx || !types[typeIdx-1]) return;
        
        const typId = types[typeIdx-1].id;
        
        const resp = await fetch('../api/manage_protocol_templates.php?action=save', {
            method: 'POST',
            body: JSON.stringify({ name: name, typ_id: typId, json_data: JSON.stringify({ title: name, blocks: [] }) })
        });
        const res = await resp.json();
        if (res.success) {
            location.href = 'protokoll_designer.php?id=' + res.id;
        }
    }

    loadTemplates();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
