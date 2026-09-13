<?php
if (session_status()===PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>
<style>
/* --- PREMIUM SAAS UI STYLES --- */
*, *::before, *::after { box-sizing: border-box; }
.hero-glow { background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color: white; padding: 2rem; border-radius: 12px; margin-bottom: 24px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1); border: 1px solid #334155; }
.hero-glow h1 { margin: 0; font-size: 24px; font-weight: 600; letter-spacing: -0.5px; display:flex; align-items:center; }
.hero-glow p { margin: 8px 0 0 0; color: #94a3b8; font-size: 15px; }

/* Vorschau-Sektion */
.preview-card { background: #fff; border-radius: 12px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1), 0 8px 10px -6px rgba(0,0,0,0.1); border: 1px solid #bae6fd; padding: 2px; margin-bottom: 32px; overflow: hidden; position: relative; }
.preview-header { background: #f0f9ff; padding: 16px 20px; border-bottom: 1px solid #e0f2fe; display: flex; justify-content: space-between; align-items: center; border-radius: 10px 10px 0 0; }
.preview-header h3 { margin: 0; color: #0369a1; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; }
#livePreviewContainer { padding: 16px; background: #fff; border-radius: 0 0 10px 10px; overflow-x: auto; }

/* Steuerungsleiste (Horizontal Oben) */
.profile-bar { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; padding: 16px 20px; margin-bottom: 24px; display: flex; align-items: center; gap: 24px; position: sticky; top: 20px; z-index: 10; }

ul#listIndex { list-style: none; padding: 0; margin: 0; display: flex; flex-wrap: wrap; gap: 8px; flex: 1; align-items: center; }
ul#listIndex li { display: inline-block; }
ul#listIndex li a { display: inline-flex; align-items: center; padding: 8px 16px; border-radius: 20px; color: #475569; text-decoration: none; font-weight: 600; font-size: 13px; transition: all 0.2s; border: 1px solid #cbd5e1; background: #fff; }
ul#listIndex li a:hover { background: #f8fafc; color: #0ea5e9; border-color: #93c5fd; }
ul#listIndex li.active a { background: #e0f2fe; color: #0369a1; border-color: #7dd3fc; box-shadow: 0 2px 4px rgba(2, 132, 199, 0.1); }

/* Editor-Bereich */
.editor-card { background: transparent; border: none; padding: 0; margin-bottom: 24px; }
.smart-section { background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; padding: 32px; margin-bottom: 32px; }
.smart-section-header { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #f1f5f9; }
.smart-section-header h3 { font-size: 16px; font-weight: 600; color: #0f172a; margin: 0; display:flex; align-items:center; gap:8px;}
.smart-section-header .badge { background:#e0f2fe; color:#0284c7; padding:4px 10px; border-radius:20px; font-size:12px; font-weight:bold; }

.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; }
.input-group { display: flex; flex-direction: column; gap: 8px; }
.input-group label { font-size: 13px; font-weight: 600; color: #334155; }
.input-group input, .input-group select { padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; color: #1e293b; background:#fff; transition: all 0.2s; box-shadow: 0 1px 2px rgba(0,0,0,0.02) inset; width: 100%; }
.input-group input:focus, .input-group select:focus { border-color: #38bdf8; outline: none; box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.15); }

/* Ziehen & Ablegen (DND) */
.dnd-container { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.dnd-zone { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px; display: flex; flex-direction: column; }
.dnd-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
.dnd-header span { font-weight: 600; color: #334155; font-size: 14px; }

#availableCols, #selectedCols { display: flex; flex-direction: column; gap: 8px; min-height: 200px; }
#selectedCols { background: #fff; border: 2px dashed #cbd5e1; border-radius: 8px; padding: 12px; }
#selectedCols:not(:empty) { border-style: solid; border-color: #e2e8f0; background: #fafafa; }

.dnd-zone li { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; font-size: 13.5px; font-weight: 500; color: #1e293b; box-shadow: 0 1px 3px rgba(0,0,0,0.04); cursor: grab; transition: all 0.15s ease; }
.dnd-zone li:active { cursor: grabbing; transform: scale(0.98); box-shadow: 0 4px 15px rgba(0,0,0,0.1); border-color:#93c5fd; }
.dnd-zone li .drag-handle { color: #94a3b8; font-size:18px; line-height:1; }

.btn-premium { background: #0ea5e9; color: white; border: none; padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size:14px; cursor:pointer; box-shadow: 0 4px 6px -1px rgba(14,165,233,0.3); transition:all 0.2s; white-space: nowrap; }
.btn-premium:hover { background: #0284c7; box-shadow: 0 6px 8px -1px rgba(14,165,233,0.4); transform: translateY(-1px); }
.btn-outline { background: #fff; color: #0f172a; border: 1px solid #cbd5e1; padding: 12px 24px; border-radius: 8px; font-weight: 600; font-size:14px; cursor:pointer; transition:all 0.2s; white-space: nowrap; }
.btn-outline:hover { background: #f8fafc; border-color: #94a3b8; }
</style>

<div style="padding: 1.5rem 2rem; background: #f8fafc; min-height: 100vh;">
  <header class="hero-glow">
    <h1>Listen-Architekt <span style="font-size:12px; font-weight:bold; background:rgba(255,255,255,0.15); padding:4px 10px; border-radius:20px; margin-left:12px; text-transform:uppercase; letter-spacing:1px;">Premium SaaS Modul</span></h1>
    <p>Konstruiere flexible Daten-Ansichten, Protokolle und Dashboards für deine Pendenzen mit 1:1 Live-Vorschau.</p>
  </header>

  <!-- STEUERUNGSLEISTE (Horizontal Oben) -->
  <aside class="profile-bar">
    <div style="display:flex; align-items:center; gap:12px; border-right:2px solid #e2e8f0; padding-right:24px;">
      <h3 style="margin:0; font-size:14px; font-weight:700; color:#334155; text-transform:uppercase;">Datenquelle</h3>
      <select id="selTable" style="padding:8px 12px; border-radius:8px; border:1px solid #cbd5e1; background:#f8fafc; font-size:13px; font-weight:600; color:#0f172a; min-width:160px;">
         <option value="pendenzen">Haupt-Pendenzen</option>
      </select>
    </div>
    
    <div style="display:flex; align-items:center; gap:12px; flex:1;">
      <h3 style="margin:0; font-size:14px; font-weight:700; color:#334155; text-transform:uppercase; padding-left:12px;">Deine Listen-Tabellen:</h3>
      <ul id="listIndex">
          <!-- JS befüllt dies -->
      </ul>
    </div>

    <div style="padding-left:16px;">
        <button id="btnNew" class="btn-premium" style="padding:12px 24px; font-size:15px; font-weight:800; background:linear-gradient(135deg, #10b981 0%, #059669 100%); box-shadow: 0 4px 10px rgba(16,185,129,0.4); border:none; color:white; border-radius:8px; cursor:pointer; text-transform:uppercase; letter-spacing:0.5px; transition:transform 0.2s;">
            + Neue Listen-Tabelle erstellen
        </button>
    </div>
  </aside>

  <!-- Live-Vorschau GANZ OBEN ÜBER DIE GESAMTE BREITE -->
  <section id="previewSection" class="preview-card hidden">
      <div class="preview-header">
         <h3>
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
            Echtzeit-Vorschau (Live)
         </h3>
         <button type="button" class="btn-outline" style="padding:8px 16px; font-size:13px;" onclick="refreshPreview()">↻ Aktualisieren</button>
      </div>
      <div id="livePreviewContainer" style="overflow-x:auto;">
         <!-- Vorschau-Tabelle wird hier injiziert -->
      </div>
  </section>

  <!-- Rechts: Arbeitsbereich -->
  <div style="min-width:0; width:100%;">
      
      <!-- Status-Meldungen -->
      <div id="msg" style="position:fixed; top:20px; right:20px; z-index:1000; padding:12px 24px; border-radius:8px; background:#0f172a; color:#fff; box-shadow:0 10px 25px rgba(0,0,0,0.2); transition:all 0.3s; pointer-events:none;"></div>

      <!-- Editor -->
      <section id="editor" class="editor-card hidden">
        <h2 id="edTitle" style="margin-bottom:20px; color:#0f172a; font-size:20px;">Listen-Tabelle konfigurieren</h2>
        <form id="formList" autocomplete="off">
          <input type="hidden" id="list_id" value="">
          
          <!-- SECTION 1: BASIS -->
          <div class="smart-section">
             <div class="smart-section-header">
                <span class="badge">Schritt 1</span>
                <h3>Basis-Konfiguration</h3>
             </div>
             <div style="display:grid; grid-template-columns: 2fr 1fr; gap:24px; align-items:start;">
                <div class="input-group">
                  <label>Name der Listen-Tabelle <span style="color:#ef4444">*</span></label>
                  <input id="name" required placeholder="z.B. Abnahmeprotokoll Rohbau">
                </div>
                 <div class="input-group">
                    <label>Eigenschaften</label>
                    <div style="display:flex; gap:12px;">
                       <label style="flex:1; display:flex; align-items:center; gap:10px; height:46px; padding:0 16px; border:1px solid #cbd5e1; border-radius:8px; cursor:pointer; background:#f8fafc; font-size:14px; font-weight:500; color:#334155;">
                         <input type="checkbox" id="shared" style="width:18px; height:18px; margin:0;">
                         Öffentlich freigeben
                       </label>
                       <label style="flex:1; display:flex; align-items:center; gap:10px; height:46px; padding:0 16px; border:1px solid #bae6fd; border-radius:8px; cursor:pointer; background:#f0f9ff; font-size:14px; font-weight:700; color:#0369a1;">
                         <input type="checkbox" id="is_default" style="width:18px; height:18px; margin:0;">
                         Als Standard-Ansicht (Autoload)
                       </label>
                    </div>
                 </div>
              </div>
           </div>

          <!-- SECTION 2: FILTER -->
          <div class="smart-section">
             <div class="smart-section-header">
                <span class="badge">Schritt 2</span>
                <h3>Smarte Daten-Filterung <span style="font-weight:normal; color:#64748b; font-size:13px; margin-left:8px;">(Was wird geladen?)</span></h3>
             </div>
             
             <div class="form-grid">
                <div class="input-group">
                  <label>Zwingender Status</label>
                  <select id="f_status">
                    <option value="">– Alle Status erlaubt –</option>
                    <option value="offen">Nur "Offen"</option>
                    <option value="in_bearbeitung">Nur "In Bearbeitung"</option>
                    <option value="erledigt">Nur "Erledigt"</option>
                    <option value="wartend">Nur "Wartend"</option>
                  </select>
                </div>
                <div class="input-group">
                  <label>Kategorie-Filter</label>
                  <select id="f_kategorie">
                    <option value="">– Alle Kategorien erlaubt –</option>
                    <?php
                      $resK = $mysqli->query("SELECT id, name FROM pendenz_kategorien ORDER BY name");
                      if($resK) while($k = $resK->fetch_assoc()) {
                        echo '<option value="'.(int)$k['id'].'">'.h($k['name']).'</option>';
                      }
                    ?>
                  </select>
                </div>
                <div class="input-group">
                  <label>Auf Projekt reduziert</label>
                  <select id="f_projekt" onchange="updateProjectContextForFilters(this.value)">
                    <option value="">– Alle Projekte –</option>
                    <?php
                      $resProj = $mysqli->query("SELECT id, name FROM projekte ORDER BY name");
                      if($resProj) while($p = $resProj->fetch_assoc()) {
                        echo '<option value="'.(int)$p['id'].'">'.h($p['name']).'</option>';
                      }
                    ?>
                  </select>
                </div>
                <div class="input-group">
                  <label>Auf Objekt reduziert</label>
                  <select id="f_objekt" onchange="updateApartmentsForFilters(this.value)">
                    <option value="">– Alle Objekte –</option>
                  </select>
                </div>
                <div class="input-group">
                  <label>Auf Wohnung reduziert</label>
                  <select id="f_wohnung">
                    <option value="">– Alle Wohnungen –</option>
                  </select>
                </div>
                <div class="input-group">
                  <label>Automatisches Suchwort</label>
                  <input id="f_q" placeholder="z.B. Schlüssel...">
                </div>
                <div class="input-group">
                  <label>Fällig ab / bis</label>
                  <div style="display:flex; gap:8px;">
                     <input id="f_von" type="date" style="flex:1;">
                     <input id="f_bis" type="date" style="flex:1;">
                  </div>
                </div>
                 <div class="input-group">
                    <label>Verstecken</label>
                    <label style="display:flex; align-items:center; gap:10px; height:46px; cursor:pointer; font-size:14px; color:#334155;">
                      <input id="f_only_open" type="checkbox" style="width:18px; height:18px; margin:0;">
                      Erledigte ausblenden
                    </label>
                 </div>
                 <div class="input-group" style="grid-column: span 2;">
                   <label>Vordefinierte Kurzbeschreibungen (eine pro Zeile)</label>
                   <textarea id="f_quick_choices" rows="3" placeholder="z.B. Maler Gipser: Wand Riss&#10;Maler Gipser: Farbe fehlt" style="padding: 12px 14px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 14px; width: 100%;"></textarea>
                 </div>

             </div>
          </div>

          <!-- SECTION 3: SPALTEN & LAYOUT -->
          <div class="smart-section">
             <div class="smart-section-header">
                <span class="badge">Schritt 3</span>
                <h3>Tabellen-Layout & Spalten <span style="font-weight:normal; color:#64748b; font-size:13px; margin-left:8px;">(Was wird angezeigt?)</span></h3>
             </div>
             
             <div style="display:flex; gap:24px; margin-bottom:24px;">
                 <div class="input-group" style="flex:2;">
                    <label>Standard-Sortierung</label>
                    <div style="display:flex; gap:12px;">
                        <select id="sort_col" style="flex:1;"></select>
                        <select id="sort_dir" style="width:130px;">
                          <option value="asc">A-Z (▲)</option>
                          <option value="desc">Z-A (▼)</option>
                        </select>
                    </div>
                 </div>
                 <div class="input-group" style="flex:1;">
                    <label>Einträge pro Seite</label>
                    <input id="per_page" type="number" min="5" max="200" value="25">
                 </div>
             </div>

             <div class="dnd-container">
                <!-- Verfügbar -->
                <div class="dnd-zone">
                  <div class="dnd-header">
                    <div style="display:flex; align-items:center; gap:8px;">
                       <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#64748b;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path></svg>
                       <span>Verfügbare Felder-Matrix</span>
                    </div>
                    <button type="button" class="btn-outline" style="padding:6px 12px; font-size:12px;" onclick="document.getElementById('customFieldBuilder').classList.toggle('hidden');">+ Dynamisches Feld</button>
                  </div>
                  
                  <div id="customFieldBuilder" class="hidden" style="background:#f0f9ff; border:1px solid #bae6fd; padding:16px; border-radius:8px; margin-bottom:16px; box-shadow:0 4px 6px rgba(0,0,0,0.03);">
                     <strong style="font-size:13px; color:#0369a1; display:block; margin-bottom:8px;">Feld-Konstruktor</strong>
                     <div style="display:flex; gap:8px;">
                        <input type="text" id="new_field_name" placeholder="Feldname (z.B. Zählerstand)" style="flex:1; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                        <select id="new_field_type" style="width:110px; padding:10px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px;">
                           <option value="text">Textzeile</option>
                           <option value="number">Zahl</option>
                           <option value="date">Datum</option>
                           <option value="checkbox">Ja/Nein</option>
                        </select>
                        <button type="button" class="btn-premium" style="padding:0 16px;" onclick="addVirtualField()">Hinzufügen</button>
                     </div>
                  </div>

                  <div style="padding:4px 0 12px 0;">
                    <input type="text" id="fieldSearch" placeholder="Feld suchen (z.B. Zeit, Status)..." style="width:100%; padding:10px; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; background:#fff url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2216%22 height=%2216%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%2394a3b8%22 stroke-width=%222%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22><circle cx=%2211%22 cy=%2211%22 r=%228%22></circle><line x1=%2221%22 y1=%2221%22 x2=%2216.65%22 y2=%2216.65%22></line></svg>') no-repeat 95% center; background-size: 16px;">
                  </div>
                  <div id="availableCols" style="flex:1; overflow-y:auto; padding-right:4px;"></div>
                </div>
                
                <!-- Ausgewählt -->
                <div class="dnd-zone" style="background:#f0fdf4; border-color:#bbf7d0;">
                  <div class="dnd-header">
                     <div style="display:flex; align-items:center; gap:8px;">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="color:#16a34a;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <span style="color:#166534;">Aktive Spalten</span>
                     </div>
                  </div>
                  <ul id="selectedCols"></ul>
                  <div style="font-size:12px; color:#15803d; text-align:center; padding:16px; background:#dcfce7; border-radius:8px; margin-top:8px;">
                     Ziehe Felder per "Drag & Drop" (<span style="font-size:14px;">☰</span>) von links nach rechts in deine Liste und ordne sie an.
                  </div>
                </div>
             </div>
          </div>

          <!-- Actions Footer -->
          <div style="display:flex; gap:16px; background:#fff; padding:20px 24px; border-radius:12px; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05); border:1px solid #e2e8f0; justify-content:space-between; align-items:center;">
            <div style="display:flex; gap:12px;">
                <button class="btn-premium" id="btnSave" style="background:#10b981; box-shadow:0 4px 6px -1px rgba(16,185,129,0.3);">💾 Konfiguration Speichern</button>
                <button class="btn-outline" id="btnCancel" type="button">Abbrechen</button>
            </div>
            <div style="display:flex; gap:12px;">
                <a id="btnOpenList" href="#" target="_blank" class="btn-outline" style="background:#f0f9ff; color:#0369a1; border-color:#bae6fd; text-decoration:none;">🚀 Liste als User öffnen</a>
                <button class="btn-outline" id="btnDelete" type="button" style="color:#ef4444; border-color:#fecaca; background:#fef2f2;">🗑️ Löschen</button>
            </div>
          </div>
        </form>
      </section>
    </div>

  </div>
</div>

<script src="<?= htmlspecialchars(asset_url('js/listen_settings.js?v=' . time())) ?>" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
