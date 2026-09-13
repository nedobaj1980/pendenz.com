<?php
// pages/pdf_designer.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_login();

// Beispiel-Pendenz für die Vorschau
$res = $mysqli->query("SELECT id FROM pendenzen ORDER BY id DESC LIMIT 1");
$p_row = $res ? $res->fetch_assoc() : null;
$previewId = $p_row ? $p_row['id'] : 0;

// Konfiguration laden
$resCfg = $mysqli->query("SELECT v FROM settings WHERE k = 'pdf_mask_default'");
$savedCfg = $resCfg && $resCfg->num_rows ? $resCfg->fetch_assoc()['v'] : null;

require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/nav_dispatch.php';
?>

<style>
    .architect-container { display: flex; gap: 20px; padding: 20px; background: #f1f5f9; min-height: calc(100vh - 100px); font-family: 'Inter', sans-serif; }
    .sidebar { width: 380px; background: #fff; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); padding: 20px; height: calc(100vh - 140px); overflow-y: auto; }
    .canvas-area { flex: 1; display: flex; justify-content: center; overflow-y: auto; padding: 30px; background: #94a3b8; }
    /* A4 exact: 210mm × 297mm @ 96dpi = 794px × 1123px */
    .a4-sheet { 
        width: 794px; 
        height: 1123px; 
        background: #fff; 
        box-shadow: 0 10px 40px rgba(0,0,0,0.3); 
        position: relative; 
        flex-shrink: 0;
        overflow: hidden;
    }
    
    .block { position: absolute; border: 1px dashed #cbd5e1; padding: 4px; background: rgba(255,255,255,0.7); cursor: move; overflow: hidden; }
    .block:hover { border-color: #3498db; background: rgba(52, 152, 219, 0.05); }
    .block.selected { border: 2px solid #2ecc71; background: #fff; z-index: 100; box-shadow: 0 0 15px rgba(46,204,113,0.2); }
    
    .style-btn { width: 30px; height: 30px; border: 1px solid #ddd; background: #fff; border-radius: 4px; cursor: pointer; font-weight: bold; display: flex; align-items: center; justify-content: center; transition: all 0.2s; }
    .style-btn:hover { background: #f1f5f9; }
    .style-btn.active { background: #3498db; color: #fff; border-color: #3498db; }
    
    .block-label { position: absolute; top: 0; right: 0; background: #3498db; color: #fff; font-size: 9px; padding: 1px 4px; border-radius: 0 0 0 4px; pointer-events: none; }
    
    .inspector-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; margin-bottom: 20px; }
    .coord-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 10px; }
    .coord-grid input { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 6px; font-size: 13px; }
    
    .data-pill { display: inline-block; padding: 4px 10px; background: #f0f9ff; color: #0369a1; border-radius: 6px; font-size: 12px; margin: 3px; border: 1px solid #bae6fd; cursor: pointer; transition: all 0.2s; }
    .data-pill:hover { background: #0369a1; color: #fff; }
    
    .btn-add { width: 100%; padding: 12px; background: #0ea5e9; color: #fff; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-bottom: 20px; transition: opacity 0.2s; }
    .btn-add:hover { opacity: 0.9; }
    .btn-delete { width: 100%; padding: 8px; background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; border-radius: 6px; margin-top: 15px; cursor: pointer; }
</style>

<div class="architect-container" style="display:flex; height:calc(100vh - 120px); background:#f1f5f9; overflow:hidden;">
    <!-- Sidebar -->
    <div class="sidebar" style="width:320px; flex-shrink:0; background:#fff; border-right:1px solid #e2e8f0; overflow-y:auto; padding:20px; box-shadow: 2px 0 10px rgba(0,0,0,0.05); z-index: 10;">
        <h2 style="margin-top:0; font-size:18px;">📋 Vorlagen-Manager</h2>
        
        <div class="inspector-box" style="background:#f1f5f9; border-color:#cbd5e1;">
            <label style="font-size:11px; font-weight:bold; display:block; margin-bottom:5px;">Vorlage wählen</label>
            <select id="selTemplate" style="width:100%; padding:8px; border-radius:6px; border:1px solid #cbd5e1;"></select>
            <div style="margin-top:10px; display:flex; gap:5px;">
                <button class="btn" style="flex:1; font-size:11px; padding:5px; background:#fff; border:1px solid #ddd;" onclick="loadTemplate()">Laden</button>
                <button class="btn" style="flex:1; font-size:11px; padding:5px; background:#10b981; color:#fff; border:none;" onclick="setDefaultTemplate()">Als Standard</button>
            </div>
        </div>

        <div class="inspector-box">
            <label style="font-size:11px; font-weight:bold; display:block; margin-bottom:5px;">Aktuelles Layout speichern</label>
            <input type="text" id="tplName" placeholder="Name der Vorlage..." style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; margin-bottom:10px;">
            <button class="btn primary" style="width:100%; background:#6366f1; color:#fff; border:none; padding:10px; border-radius:6px; font-weight:bold;" onclick="saveTemplate()">💾 Als Vorlage speichern</button>
        </div>

        <hr style="border:0; border-top:1px solid #eee; margin:20px 0;">
        
        <h3 style="margin-top:0; font-size:16px;">🎨 Layout Designer</h3>

        <div class="inspector-box" style="background:#fff7ed; border-color:#fdba74;">
            <label style="font-size:11px; font-weight:bold; color:#9a3412;">📄 Blattgrösse &amp; Ränder</label>
            
            <!-- Blattgrösse -->
            <div style="margin-top:8px;">
                <label style="font-size:10px; font-weight:bold; color:#666;">Format & Orientierung</label>
                <div style="display:flex; gap:5px; margin-top:4px;">
                    <button class="btn" id="btnPortrait" style="flex:1; font-size:10px; padding:5px;" onclick="setOrientation('portrait')">▯ Hochformat</button>
                    <button class="btn" id="btnLandscape" style="flex:1; font-size:10px; padding:5px;" onclick="setOrientation('landscape')">▭ Querformat</button>
                </div>
            </div>

            <div style="margin-top:8px; display:grid; grid-template-columns: 1fr 2fr 2fr; gap:5px; text-align:center; align-items:center;">
                <div style="font-size:9px; color:#999;">Dimension</div>
                <div style="font-size:9px; color:#999;">Pixel (px)</div>
                <div style="font-size:9px; color:#999;">Zentimeter (cm)</div>

                <div style="font-size:10px; text-align:left;">Breite</div>
                <div><input type="number" id="inpPageW" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>
                <div><input type="number" id="inpPageWCm" step="0.1" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>

                <div style="font-size:10px; text-align:left;">Höhe</div>
                <div><input type="number" id="inpPageH" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>
                <div><input type="number" id="inpPageHCm" step="0.1" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>
            </div>

            <div style="margin-top:12px; display:grid; grid-template-columns: 1fr 1fr; gap:8px; align-items:center; border-top:1px solid #fde68a; padding-top:8px;">
                <div>
                    <label style="font-size:10px; display:block; text-align:center; color:#666;">🎨 Themenfarbe</label>
                    <input type="color" id="cfgColor" style="width:100%; height:30px; border:none; padding:0; background:transparent; cursor:pointer;">
                </div>
                <div></div>
            </div>

            <div style="margin-top:8px; display:grid; grid-template-columns: 1fr 2fr 2fr; gap:5px; text-align:center; align-items:center;">
                <div style="grid-column:1/-1; border-top:1px dashed #fde68a; padding-top:5px; margin-bottom:5px;">
                    <label style="font-size:10px; font-weight:bold; color:#9a3412;">Ränder</label>
                </div>
                
                <div style="font-size:10px; text-align:left;">Oben</div>
                <div><input type="number" id="inpMarginTop" value="53" min="0" max="400" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>
                <div><input type="number" id="inpMarginTopCm" step="0.1" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>

                <div style="font-size:10px; text-align:left;">Links</div>
                <div><input type="number" id="inpMarginLeft" value="53" min="0" max="400" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>
                <div><input type="number" id="inpMarginLeftCm" step="0.1" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>

                <div style="font-size:10px; text-align:left;">Rechts</div>
                <div><input type="number" id="inpMarginRight" value="53" min="0" max="400" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>
                <div><input type="number" id="inpMarginRightCm" step="0.1" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>

                <div style="font-size:10px; text-align:left;">Unten</div>
                <div><input type="number" id="inpMarginBottom" value="53" min="0" max="400" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>
                <div><input type="number" id="inpMarginBottomCm" step="0.1" style="width:100%; padding:4px; border:1px solid #ddd; border-radius:4px; text-align:center; font-size:11px;"></div>
            </div>
        </div>
        
        <div id="inspector">
            <div id="noSelection" class="inspector-box" style="text-align:center; color:#64748b; font-size:13px;">
                Kein Element ausgewählt
            </div>
            <div id="selectionPanel" class="inspector-box hidden">
                <strong id="blockTitle" style="font-size:12px; text-transform:uppercase; color:#475569;">Block Einstellungen</strong>
                <div class="coord-grid">
                    <div><label style="font-size:11px;">X (px)</label><input type="number" id="inpX"></div>
                    <div><label style="font-size:11px;">Y (px)</label><input type="number" id="inpY"></div>
                    <div><label style="font-size:11px;">B (px)</label><input type="number" id="inpW"></div>
                    <div><label style="font-size:11px;">H (px)</label><input type="number" id="inpH"></div>
                </div>
                
                <div style="margin-top:15px; display:grid; grid-template-columns: 1fr 1fr; gap:15px; border-top:1px solid #f1f5f9; padding-top:10px;">
                    <div style="display:flex; flex-direction:column; gap:5px;">
                        <label style="font-size:10px; font-weight:bold; color:#64748b; text-transform:uppercase;">Seite Snap (H/V)</label>
                        <div style="display:flex; gap:2px; background:#f1f5f9; padding:2px; border-radius:4px; margin-bottom:5px;">
                            <button class="style-btn" id="snapLeft" title="Links">L</button>
                            <button class="style-btn" id="snapCenter" title="H-Mitte">C</button>
                            <button class="style-btn" id="snapRight" title="Rechts">R</button>
                        </div>
                        <div style="display:flex; gap:2px; background:#f1f5f9; padding:2px; border-radius:4px;">
                            <button class="style-btn" id="snapTop" title="Oben">↑</button>
                            <button class="style-btn" id="snapVCenter" title="V-Mitte">↔</button>
                            <button class="style-btn" id="snapBottom" title="Unten">↓</button>
                        </div>
                    </div>

                    <div style="display:flex; flex-direction:column; gap:5px; align-items:center;">
                        <label style="font-size:10px; font-weight:bold; color:#64748b; text-transform:uppercase;">Bewegen</label>
                        <div style="display:grid; grid-template-columns: repeat(3, 1fr); gap:2px;">
                            <div></div><button class="style-btn" id="moveUp">↑</button><div></div>
                            <button class="style-btn" id="moveLeft">←</button>
                            <button class="style-btn" id="moveCenter" title="H-Zentrieren">↔</button>
                            <button class="style-btn" id="moveRight">→</button>
                            <div></div><button class="style-btn" id="moveDown">↓</button><div></div>
                        </div>
                    </div>
                </div>

                <div style="margin-top:15px; display:flex; gap:10px; align-items:center;">
                    <div style="flex:1;">
                        <label style="font-size:11px; display:block;">Schriftgrösse (px)</label>
                        <input type="number" id="inpFontSize" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;">
                    </div>
                    <div style="display:flex; gap:2px; margin-top:14px;">
                        <button class="style-btn" id="btnBold" title="Fett">B</button>
                        <button class="style-btn" id="btnItalic" title="Kursiv">I</button>
                        <button class="style-btn" id="btnUnderline" title="Unterstrichen">U</button>
                    </div>
                </div>

                <div style="margin-top:10px; display:flex; gap:10px; align-items:center; justify-content: flex-end;">
                    <div style="display:flex; gap:2px; background:#f1f5f9; padding:2px; border-radius:4px;" title="Horizontale Ausrichtung">
                        <button class="style-btn" id="alignLeft" style="font-size:10px;">L</button>
                        <button class="style-btn" id="alignCenter" style="font-size:10px;">C</button>
                        <button class="style-btn" id="alignRight" style="font-size:10px;">R</button>
                    </div>
                    <div style="display:flex; gap:2px; background:#f1f5f9; padding:2px; border-radius:4px;" title="Vertikale Ausrichtung">
                        <button class="style-btn" id="valignTop" title="Oben">↑</button>
                        <button class="style-btn" id="valignMiddle" title="Mitte">↔</button>
                        <button class="style-btn" id="valignBottom" title="Unten">↓</button>
                    </div>
                </div>

                <div style="margin-top:15px; display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div>
                        <label style="font-size:11px; display:block;">Schriftfarbe</label>
                        <input type="color" id="inpColor" style="width:100%; height:30px; border:none; cursor:pointer; background:none;">
                    </div>
                    <div>
                        <label style="font-size:11px; display:block;">Hintergrund</label>
                        <input type="color" id="inpBgColor" style="width:100%; height:30px; border:none; cursor:pointer; background:none;">
                        <label style="font-size:9px; color:#999;"><input type="checkbox" id="chkNoBg"> Kein Hintergrund</label>
                    </div>
                </div>
                
                <div style="margin-top:15px;">
                    <label style="font-size:11px; font-weight:bold;">Inhalt / Tags</label>
                    <textarea id="inpText" style="width:100%; height:100px; padding:8px; border:1px solid #ddd; border-radius:6px; font-size:13px; margin-top:5px;"></textarea>
                </div>
                <button class="btn-delete" onclick="deleteBlock()">🗑️ Block löschen</button>
            </div>
        </div>

        <button class="btn-add" onclick="addBlock()">➕ Neuer Text/Bild Block</button>

        <div class="inspector-box">
            <label style="font-size:11px; font-weight:bold;">📊 Daten-Felder (Klicken zum Einfügen)</label>
            <input type="text" id="fieldSearch" placeholder="Feld suchen (z.B. Zeit, Status)..." 
                style="width:100%; padding:8px; border:1px solid #ddd; border-radius:6px; font-size:12px; margin-top:8px; box-sizing:border-box;"
                oninput="filterFields(this.value)">
            <div id="fieldList" style="margin-top:10px; max-height:500px; overflow-y:auto; padding-right:5px;">

                <p class="field-group" style="font-size:10px; color:#64748b; margin:5px 0; font-weight:bold; text-transform:uppercase;">⏱️ Termine &amp; Planung</p>
                <span class="data-pill" data-label="Dauer (smart)" onclick="addTag('{DAUER}')">⏱️ Dauer (smart)</span>
                <span class="data-pill" data-label="Fälligkeits-Datum" onclick="addTag('{ENDDATUM}')">🏁 Fälligkeits-Datum</span>
                <span class="data-pill" data-label="Präzise Uhrzeit" onclick="addTag('{UHRZEIT}')">🕐 Präzise Uhrzeit</span>
                <span class="data-pill" data-label="Start-Datum" onclick="addTag('{STARTDATUM}')">📅 Start-Datum</span>
                <span class="data-pill" data-label="Tagesabschnitt" onclick="addTag('{TAGESZEIT}')">🌤️ Tagesabschnitt</span>
                <span class="data-pill" data-label="Vorgänger-ID" onclick="addTag('{VORGAENGER_ID}')">🔗 Vorgänger-ID</span>
                <span class="data-pill" data-label="Beginn" onclick="addTag('{BEGINN}')">📅 Beginn</span>
                <span class="data-pill" data-label="Ende" onclick="addTag('{ENDE}')">🏁 Ende</span>

                <p class="field-group" style="font-size:10px; color:#64748b; margin:15px 0 5px 0; font-weight:bold; text-transform:uppercase;">🏠 Stammdaten &amp; Bilder</p>
                <span class="data-pill" data-label="Objekt Name" onclick="addTag('{OBJEKT}')">🏢 Objekt Name</span>
                <span class="data-pill" data-label="Vorgangsart Typ" onclick="addTag('{VORGANGSART}')">📑 Vorgangsart (Typ)</span>
                <span class="data-pill" data-label="Alle Bilder Dokumente" onclick="addTag('{BILDER}')">📸 Alle Bilder / Dokumente</span>
                <span class="data-pill" data-label="Bilder Vorschau erstes Bild" onclick="addTag('{ERSTES_BILD}')">🖼️ Bilder-Vorschau</span>
                <span class="data-pill" data-label="Haupt Liegenschaftsbild Cover" onclick="addTag('{COVER}')">🖼️ Haupt-Liegenschaftsbild</span>
                <span class="data-pill" data-label="Wohnung Einheit" onclick="addTag('{WOHNUNG}')">🚪 Wohnung / Einheit</span>
                <span class="data-pill" data-label="Kurzbeschreibung" onclick="addTag('{KURZBESCHREIBUNG}')">📄 Kurzbeschreibung</span>
                <span class="data-pill" data-label="Priorität Sterne Wichtigkeit" onclick="addTag('{PRIORITAET}')">⭐ Prioritäts-Sterne</span>
                <span class="data-pill" data-label="Projekt Name" onclick="addTag('{PROJEKT}')">🏗️ Projekt-Name</span>
                <span class="data-pill" data-label="Sichtbarkeit Level" onclick="addTag('{SICHTBARKEIT}')">👁️ Sichtbarkeits-Level</span>
                <span class="data-pill" data-label="Status offen erledigt" onclick="addTag('{STATUS}')">🚥 Status (offen/erledigt)</span>
                <span class="data-pill" data-label="Titel Aufgabe" onclick="addTag('{TITEL}')">🔤 Titel / Aufgabe</span>
                <span class="data-pill" data-label="Zuständige Person" onclick="addTag('{ZUSTAENDIG_NAME}')">👤 Zuständige Person</span>
                <span class="data-pill" data-label="Raum" onclick="addTag('{RAUM}')">🛋️ Raum</span>

                <p class="field-group" style="font-size:10px; color:#64748b; margin:15px 0 5px 0; font-weight:bold; text-transform:uppercase;">📝 Texte &amp; Inhalt</p>
                <span class="data-pill" data-label="Langbeschreibung Beschreibung" onclick="addTag('{LANGBESCHREIBUNG}')">📜 Beschreibung (lang)</span>
                <span class="data-pill" data-label="Notizen" onclick="addTag('{NOTIZEN}')">✍️ Notizen</span>
                <span class="data-pill" data-label="Unternehmer Info" onclick="addTag('{UNT_BEMERKUNG}')">👷 Unternehmer-Info</span>

                <p class="field-group" style="font-size:10px; color:#64748b; margin:15px 0 5px 0; font-weight:bold; text-transform:uppercase;">📅 Verlauf &amp; Protokoll</p>
                <span class="data-pill" data-label="Erstellt am Datum Zeit" onclick="addTag('{ERSTELLT_AM}')">🕒 Erstellt am</span>
                <span class="data-pill" data-label="Geändert am Datum Zeit" onclick="addTag('{GEAENDERT_AM}')">🔄 Geändert am</span>
                <span class="data-pill" data-label="Gelöscht am" onclick="addTag('{DELETED_AT}')">🗑️ Gelöscht am</span>

                <p class="field-group" style="font-size:10px; color:#64748b; margin:15px 0 5px 0; font-weight:bold; text-transform:uppercase;">👤 Personen &amp; Kontakt</p>
                <span class="data-pill" data-label="Zuständig Name Verantwortlich" onclick="addTag('{ZUSTÄNDIG_NAME}')">👤 Name (Zuständiger)</span>
                <span class="data-pill" data-label="Zuständig Firma" onclick="addTag('{ZUSTÄNDIG_FIRMA}')">🏢 Firma (Zuständiger)</span>
                <span class="data-pill" data-label="Email Zuständig" onclick="addTag('{ZUSTÄNDIG_EMAIL}')">📧 E-Mail (Zuständig)</span>
                <span class="data-pill" data-label="Telefon Zuständig" onclick="addTag('{ZUSTÄNDIG_TELEFON}')">📞 Telefon (Zuständig)</span>
                <span class="data-pill" data-label="Firma Strasse Zuständig" onclick="addTag('{ZUSTÄNDIG_FIRMA_STRASSE}')">📍 Strasse (Firma Zuständiger)</span>
                <span class="data-pill" data-label="Firma Ort Zuständig" onclick="addTag('{ZUSTÄNDIG_FIRMA_ORT}')">🏙️ Ort (Firma Zuständiger)</span>
                <span class="data-pill" data-label="Adresse Zuständig Full" onclick="addTag('{ZUSTÄNDIG_ADRESSE}')">📍 Adresse Komplett (Zuständiger)</span>
                <span class="data-pill" data-label="Profilbild" onclick="addTag('{ZUSTAENDIG_PROFILBILD}')">🖼️ Profilbild (Zuständig)</span>

                <p class="field-group" style="font-size:10px; color:#64748b; margin:15px 0 5px 0; font-weight:bold; text-transform:uppercase;">🛠️ Ersteller (Wer hat es erfasst?)</p>
                <span class="data-pill" data-label="Ersteller Name" onclick="addTag('{ERSTELLER_NAME}')">👤 Name (Ersteller)</span>
                <span class="data-pill" data-label="Ersteller Firma" onclick="addTag('{ERSTELLER_FIRMA}')">🏢 Firma (Ersteller)</span>
                <span class="data-pill" data-label="Ersteller Email" onclick="addTag('{ERSTELLER_EMAIL}')">📧 E-Mail (Ersteller)</span>

                <p class="field-group" style="font-size:10px; color:#64748b; margin:15px 0 5px 0; font-weight:bold; text-transform:uppercase;">🏛️ Eigene Firma / Branding</p>
                <span class="data-pill" data-label="Eigene Firma Name" onclick="addTag('{COMPANY_NAME}')">🏢 Eigene Firma Name</span>
                <span class="data-pill" data-label="Eigene Firma Email" onclick="addTag('{COMPANY_EMAIL}')">📧 Eigene Firma E-Mail</span>
                <span class="data-pill" data-label="Eigene Firma Telefon" onclick="addTag('{COMPANY_PHONE}')">📞 Eigene Firma Telefon</span>
                <span class="data-pill" data-label="Eigene Firma Adresse" onclick="addTag('{COMPANY_ADDRESS}')">📍 Eigene Firma Adresse</span>
                <span class="data-pill" data-label="Eigene Firma PLZ" onclick="addTag('{COMPANY_PLZ}')">📍 Eigene Firma PLZ</span>
                <span class="data-pill" data-label="Eigene Firma Ort" onclick="addTag('{COMPANY_ORT}')">🏙️ Eigene Firma Ort</span>

                <p class="field-group" style="font-size:10px; color:#64748b; margin:15px 0 5px 0; font-weight:bold; text-transform:uppercase;">🖼️ Branding &amp; System</p>
                <span class="data-pill" data-label="Firmenlogo Logo" onclick="addTag('{LOGO}')">🖼️ Firmenlogo</span>
                <span class="data-pill" data-label="QR Code" onclick="addTag('{QR}')">🔳 QR-Code</span>
                <span class="data-pill" data-label="Direktlink URL" onclick="addTag('{LINK}')">🔗 Direktlink (URL)</span>
                <span class="data-pill" data-label="Header" onclick="addTag('{HEADER}')">🏛️ Header (Auto)</span>
                <span class="data-pill" data-label="Footer" onclick="addTag('{FOOTER}')">📝 Footer (Auto)</span>

                <p class="field-group" style="font-size:10px; color:#64748b; margin:15px 0 5px 0; font-weight:bold; text-transform:uppercase;">⚙️ Systemfelder &amp; Dokumente</p>
                <span class="data-pill" data-label="Verantwortlicher" onclick="addTag('{VERANTWORTLICHER}')">👤 Verantwortlicher</span>
                <span class="data-pill" data-label="Dokumente alle Dateien" onclick="addTag('{DOKUMENTE}')">📂 Dokumente (alle)</span>
                <span class="data-pill" data-label="PDF Dokumente" onclick="addTag('{PDF_DOCS}')">📕 Nur PDF-Dokumente</span>
                <span class="data-pill" data-label="Erstellt von ID" onclick="addTag('{ERSTELLT_VON}')">🛠️ Erstellt von (ID)</span>
                <span class="data-pill" data-label="ID Eindeutige Nummer" onclick="addTag('{ID}')">🔢 ID (Eindeutige Nummer)</span>

                <p class="field-group" style="font-size:10px; color:#64748b; margin:15px 0 5px 0; font-weight:bold; text-transform:uppercase;">📋 Automatisches Formular</p>
                <span class="data-pill" data-label="alle ausgefüllten Daten" style="background:#fdf2f8; color:#be185d; border-color:#fbcfe8; width:90%; text-align:center; display:block; margin:5px auto;" onclick="addTag('{ALL_DATA}')">✨ ALLE AUSGEFÜLLTEN DATEN ✨</span>
            </div>
        </div>

        <div class="inspector-box">
            <label style="font-size:12px; font-weight:bold;">Themen-Farbe</label>
            <input type="color" id="cfgColor" style="width:100%; height:35px; border:none; cursor:pointer; margin-top:5px;">
        </div>

        <div class="inspector-box" style="background:#fff7ed; border-color:#fdba74;">
            <label style="font-size:11px; font-weight:bold; color:#9a3412;">📄 Seiten-Einstellungen</label>
            <div style="margin-top:5px; display:grid; grid-template-columns:1fr 1fr; gap:5px;">
                <div>
                    <label style="font-size:10px; display:block;">Breite (px)</label>
                    <input type="number" id="inpPageW" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <div>
                    <label style="font-size:10px; display:block;">Breite (cm)</label>
                    <input type="number" id="inpPageWCm" step="0.1" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;">
                </div>
            </div>
            <div style="margin-top:5px; display:grid; grid-template-columns:1fr 1fr; gap:5px;">
                <div>
                    <label style="font-size:10px; display:block;">Höhe (px)</label>
                    <input type="number" id="inpPageH" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;">
                </div>
                <div>
                    <label style="font-size:10px; display:block;">Höhe (cm)</label>
                    <input type="number" id="inpPageHCm" step="0.1" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;">
                </div>
            </div>
        </div>

        <div class="inspector-box" style="background:#f0f9ff; border-color:#bae6fd;">
            <label style="font-size:11px; font-weight:bold; color:#0369a1;">🖼️ Bild-Einstellungen</label>
            <div style="margin-top:8px;">
                <label style="font-size:10px; display:block;">Cover-Höhe (px)</label>
                <input type="number" id="inpCoverH" value="220" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;">
            </div>
            <div style="margin-top:5px;">
                <label style="font-size:10px; display:block;">Galerie-Bilder Höhe (px)</label>
                <input type="number" id="inpGalleryH" value="110" style="width:100%; padding:5px; border:1px solid #ddd; border-radius:4px;">
            </div>
        </div>

        <button class="btn primary" id="btnSave" style="width:100%; padding:15px; background:#1abc9c;" onclick="saveConfig()">💾 Layout Speichern</button>
        <button class="btn" style="width:100%; margin-top:10px; background:#34495e; color:#fff;" onclick="togglePreview()">👁️ Echtzeit-Vorschau</button>
        <button class="btn" style="width:100%; margin-top:10px; background:#f1f5f9; color:#475569;" onclick="resetToDefault()">🔄 Auf Standard zurücksetzen</button>
        
        <div style="margin-top:20px;">
            <a href="pendenz_pdf.php?id=<?= $previewId ?>" target="_blank" class="btn" style="display:block; text-align:center; background:#fff; border:1px solid #ddd; color:#333; text-decoration:none;">📄 Echtes PDF testen</a>
        </div>
    </div>

    <div class="canvas-area" style="flex:1; position:relative; overflow:auto;">
        <div class="a4-sheet" id="canvas"></div>
    </div>

    <div id="pdf-preview-panel" style="width:0; transition: width 0.3s ease; background:#475569; overflow:hidden; display:flex; flex-direction:column; border-left:1px solid #334155;">
        <div style="padding:10px; background:#1e293b; color:#fff; font-size:12px; font-weight:bold; display:flex; justify-content:space-between; align-items:center;">
            <span>PDF LIVE-VORSCHAU (#87)</span>
            <button onclick="togglePreview()" style="background:transparent; border:none; color:#fff; cursor:pointer; font-size:16px;">✕</button>
        </div>
        <iframe id="pdf-frame" style="flex:1; border:none;" src="about:blank"></iframe>
    </div>
</div>

<script>
    const SAVED = <?= $savedCfg ?: 'null' ?>;

    function filterFields(q) {
        q = q.toLowerCase().trim();
        const pills = document.querySelectorAll('#fieldList .data-pill');
        const groups = document.querySelectorAll('#fieldList .field-group');
        
        if (!q) {
            pills.forEach(p => p.style.display = '');
            groups.forEach(g => g.style.display = '');
            return;
        }
        
        // Hide all groups first, then show if they have a match
        groups.forEach(g => g.style.display = 'none');
        
        pills.forEach(pill => {
            const label = (pill.getAttribute('data-label') || pill.textContent).toLowerCase();
            const match = label.includes(q);
            pill.style.display = match ? '' : 'none';
            
            // Show the preceding group header if there's a match
            if (match) {
                let prev = pill.previousElementSibling;
                while (prev) {
                    if (prev.classList.contains('field-group')) { prev.style.display = ''; break; }
                    prev = prev.previousElementSibling;
                }
            }
        });
    }


    const DEFAULT = {
        blocks: [
            { id: 'b_cover', x: 0, y: 0, w: 794, h: 120, text: "{ZUSTAENDIG_TITELBILD}" },
            { id: 'b_header', x: 50, y: 50, w: 700, h: 80, text: "{HEADER}" },
            { id: 'b_title', x: 50, y: 160, w: 500, h: 60, text: "{TITEL}" },
            { id: 'b_id', x: 580, y: 160, w: 170, h: 40, text: "<div style='text-align:right; font-size:28px; font-weight:bold; color:{COLOR}; opacity:0.3;'>{ID}</div>" },
            { id: 'b_short', x: 50, y: 240, w: 460, h: 100, text: "{KURZBESCHREIBUNG}" },
            { id: 'b_meta', x: 530, y: 240, w: 220, h: 180, text: "{META}" },
            { id: 'b_long', x: 50, y: 360, w: 460, h: 350, text: "{LANGBESCHREIBUNG}" },
            { id: 'b_card', x: 530, y: 440, w: 220, h: 260, text: "<div style='border:1px solid #e2e8f0; padding:20px; border-radius:15px; background:#fff; box-shadow:0 4px 6px -1px rgba(0,0,0,0.05);'>\n<div style='width:70px; height:70px; margin:0 auto 15px auto; border:2px solid {COLOR}; border-radius:50%; padding:2px;'>{ZUSTAENDIG_PROFILBILD}</div>\n<div style='text-align:center;'>\n<strong style='display:block; font-size:14px; color:#1e293b;'>{ZUSTAENDIG_NAME}</strong>\n<span style='font-size:11px; color:#64748b; display:block; margin-bottom:10px;'>{ZUSTAENDIG_FIRMA}</span>\n<div style='border-top:1px solid #f1f5f9; padding-top:10px; text-align:left;'>\n<span style='font-size:10px; color:#94a3b8;'>📞 {ZUSTAENDIG_TELEFON}</span><br>\n<span style='font-size:10px; color:#94a3b8;'>📧 {ZUSTAENDIG_EMAIL}</span>\n</div>\n</div>\n</div>" },
            { id: 'b_images', x: 50, y: 730, w: 700, h: 320, text: "{BILDER}" },
            { id: 'b_footer', x: 50, y: 1060, w: 700, h: 50, text: "{FOOTER}" }
        ],
        color: '#1abc9c'
    };

    let CONFIG = SAVED || JSON.parse(JSON.stringify(DEFAULT));
    
    // Migration: Falls noch das alte 'sections' Format vorliegt
    if (CONFIG.sections && !CONFIG.blocks) {
        CONFIG.blocks = [];
        for (let key in CONFIG.sections) {
            let s = CONFIG.sections[key];
            CONFIG.blocks.push({
                id: 'mig_' + key,
                x: s.x, y: s.y, w: s.w, h: s.h || 60,
                text: '{' + key.toUpperCase() + '}'
            });
        }
    }
    if (!CONFIG.blocks) CONFIG.blocks = DEFAULT.blocks;

    let selectedId = null;

    function render() {
        const canvas = document.getElementById('canvas');
        canvas.innerHTML = '';
        
        // Blattgrösse anpassen
        const pageW = CONFIG.pageW || 794;
        const pageH = CONFIG.pageH || 1123;
        canvas.style.width = pageW + 'px';
        canvas.style.height = pageH + 'px';

        // === HILFSLINIEN ===
        const getMargins = () => ({
            t: CONFIG.marginTop    ?? 53,
            r: CONFIG.marginRight  ?? 53,
            b: CONFIG.marginBottom ?? 53,
            l: CONFIG.marginLeft   ?? 53,
        });
        const mg = getMargins();

        const addGuide = (style) => {
            const g = document.createElement('div');
            g.style.position = 'absolute';
            g.style.pointerEvents = 'none';
            g.style.zIndex = '200';
            Object.assign(g.style, style);
            canvas.appendChild(g);
        };
        const addLabel = (text, top, left, color) => {
            const l = document.createElement('div');
            l.textContent = text;
            l.style.cssText = `position:absolute; left:${left ?? 4}px; top:${top}px; font-size:8px; color:${color}; pointer-events:none; z-index:201; background:white; padding:0 2px; opacity:0.8; white-space:nowrap;`;
            canvas.appendChild(l);
        };

        // Rand-Rahmen (4 einzelne Linien)
        addGuide({ left: mg.l+'px', top: mg.t+'px', width: (pageW - mg.l - mg.r)+'px', height: (pageH - mg.t - mg.b)+'px', border: '1px dashed #f97316', opacity: '0.7' });
        addLabel('Rand ('+mg.t+'px)', mg.t + 2, mg.l + 4, '#f97316');

        // Kopfzeilen-Hilfslinie
        const headerY = mg.t + 80;
        addGuide({ left: mg.l+'px', width: (pageW - mg.l - mg.r)+'px', top: headerY+'px', borderTop: '1px dashed #3b82f6', height: '0' });
        addLabel('── Kopfzeile', headerY + 2, mg.l + 4, '#3b82f6');

        // Fusszeilen-Hilfslinie
        const footerY = pageH - mg.b - 80;
        addGuide({ left: mg.l+'px', width: (pageW - mg.l - mg.r)+'px', top: footerY+'px', borderTop: '1px dashed #3b82f6', height: '0' });
        addLabel('── Fusszeile', footerY + 2, mg.l + 4, '#3b82f6');


        CONFIG.blocks.forEach(b => {
            const div = document.createElement('div');
            div.className = 'block' + (selectedId === b.id ? ' selected' : '');
            div.style.left = b.x + 'px';
            div.style.top = b.y + 'px';
            div.style.width = b.w + 'px';
            div.style.height = b.h + 'px';
            
            // Styles anwenden
            div.style.fontSize = (b.fontSize || 12) + 'px';
            if (b.bold) div.style.fontWeight = 'bold';
            if (b.italic) div.style.fontStyle = 'italic';
            if (b.underline) div.style.textDecoration = 'underline';
            if (b.align) div.style.textAlign = b.align;
            if (b.color) div.style.color = b.color;
            if (b.bgColor) {
                div.style.backgroundColor = b.bgColor;
                div.style.padding = '5px';
            }
            
            let html = (b.text || '').replace(/\n/g, '<br>').replace('{COLOR}', CONFIG.color);
            
            // Rich Previews
            if (html.includes('{HEADER}')) html = html.replace('{HEADER}', '<div style="background:#f8fafc; border:1px solid #e2e8f0; height:100%; display:flex; align-items:center; justify-content:center; color:#64748b; font-size:10px; font-weight:bold;">[ 🏛️ BRANDING HEADER ]</div>');
            if (html.includes('{META}')) html = html.replace('{META}', '<div style="background:#f8fafc; border:1px dashed #cbd5e1; height:100%; padding:10px; font-size:9px; color:#94a3b8;">Status: ...<br>Wichtigkeit: ...<br>Termin: ...</div>');
            if (html.includes('{ZUSTAENDIG_PROFILBILD}')) html = html.replace('{ZUSTAENDIG_PROFILBILD}', '<div style="width:100%; height:100%; background:#e2e8f0; border-radius:50%; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-size:10px;">PROFIL</div>');
            if (html.includes('{ZUSTAENDIG_TITELBILD}')) html = html.replace('{ZUSTAENDIG_TITELBILD}', '<div style="width:100%; height:100%; background:#cbd5e1; display:flex; align-items:center; justify-content:center; color:#fff; font-weight:bold; font-size:12px;">TITELBILD BANNER</div>');
            if (html.includes('{LOGO}')) html = html.replace('{LOGO}', '<div style="height:100%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; color:#94a3b8; font-weight:bold;">LOGO</div>');
            if (html.includes('{QR}')) html = html.replace('{QR}', '<div style="height:100%; background:#f1f5f9; display:flex; align-items:center; justify-content:center; border:1px solid #ddd;">QR</div>');
            if (html.includes('{BILDER}')) html = html.replace('{BILDER}', '<div style="height:100%; background:#f8fafc; border:1px solid #e2e8f0; display:flex; align-items:center; justify-content:center; font-size:10px; color:#94a3b8;">[ 📸 BILDER-GRID ]</div>');
            if (html.includes('{FOOTER}')) html = html.replace('{FOOTER}', '<div style="height:100%; border-top:1px solid #eee; font-size:9px; color:#94a3b8; padding-top:5px;">[ 📝 FOOTER / QR ]</div>');

            div.innerHTML = `<div class="block-content" style="height:100%; overflow:hidden; text-align:inherit; display:flex; flex-direction:column;">${html}</div>`;
            const contentDiv = div.querySelector('.block-content');
            
            // Vertikale Ausrichtung
            if (b.vAlign === 'middle') contentDiv.style.justifyContent = 'center';
            else if (b.vAlign === 'bottom') contentDiv.style.justifyContent = 'flex-end';
            else contentDiv.style.justifyContent = 'flex-start';

            contentDiv.querySelectorAll('h1, h2, h3, p, div, span').forEach(el => {
                el.style.textAlign = 'inherit';
                el.style.fontSize = 'inherit';
                el.style.fontWeight = 'inherit';
                el.style.margin = '0';
            });
            div.onmousedown = (e) => startDrag(e, b.id);
            div.onclick = () => selectBlock(b.id);
            canvas.appendChild(div);
        });
        document.getElementById('cfgColor').value = CONFIG.color;
    }

    function resetToDefault() {
        if (confirm("Möchtest du das Layout wirklich auf den neuen Profi-Standard zurücksetzen? Deine aktuellen Änderungen gehen verloren.")) {
            CONFIG = JSON.parse(JSON.stringify(DEFAULT));
            selectedId = null;
            document.getElementById('noSelection').classList.remove('hidden');
            document.getElementById('selectionPanel').classList.add('hidden');
            render();
        }
    }

    function selectBlock(id) {
        selectedId = id;
        const b = CONFIG.blocks.find(x => x.id === id);
        document.getElementById('noSelection').classList.add('hidden');
        document.getElementById('selectionPanel').classList.remove('hidden');
        
        document.getElementById('inpX').value = b.x;
        document.getElementById('inpY').value = b.y;
        document.getElementById('inpW').value = b.w;
        document.getElementById('inpH').value = b.h;
        document.getElementById('inpText').value = b.text;
        
        document.getElementById('inpFontSize').value = b.fontSize || 12;
        document.getElementById('btnBold').classList.toggle('active', !!b.bold);
        document.getElementById('btnItalic').classList.toggle('active', !!b.italic);
        document.getElementById('btnUnderline').classList.toggle('active', !!b.underline);
        
        document.getElementById('alignLeft').classList.toggle('active', b.align === 'left' || !b.align);
        document.getElementById('alignCenter').classList.toggle('active', b.align === 'center');
        document.getElementById('alignRight').classList.toggle('active', b.align === 'right');

        document.getElementById('valignTop').classList.toggle('active', b.vAlign === 'top' || !b.vAlign);
        document.getElementById('valignMiddle').classList.toggle('active', b.vAlign === 'middle');
        document.getElementById('valignBottom').classList.toggle('active', b.vAlign === 'bottom');

        document.getElementById('inpColor').value = b.color || '#000000';
        document.getElementById('inpBgColor').value = b.bgColor || '#ffffff';
        document.getElementById('chkNoBg').checked = !b.bgColor;
        
        render();
    }

    ['inpX', 'inpY', 'inpW', 'inpH', 'inpText', 'inpFontSize', 'inpColor', 'inpBgColor'].forEach(fid => {
        document.getElementById(fid).oninput = (e) => {
            if (!selectedId) return;
            const b = CONFIG.blocks.find(x => x.id === selectedId);
            const v = (fid === 'inpText' || fid === 'inpColor' || fid === 'inpBgColor') ? e.target.value : parseInt(e.target.value);
            if (fid === 'inpX') b.x = v;
            if (fid === 'inpY') b.y = v;
            if (fid === 'inpW') b.w = v;
            if (fid === 'inpH') b.h = v;
            if (fid === 'inpText') b.text = v;
            if (fid === 'inpFontSize') b.fontSize = v;
            if (fid === 'inpColor') b.color = v;
            if (fid === 'inpBgColor') {
                b.bgColor = v;
                document.getElementById('chkNoBg').checked = false;
            }
            if (['inpX','inpY','inpW','inpH'].includes(fid)) clampBlock(b);
            render();
        };
    });

    document.getElementById('chkNoBg').onchange = (e) => {
        if (!selectedId) return;
        const b = CONFIG.blocks.find(x => x.id === selectedId);
        if (e.target.checked) b.bgColor = null;
        else b.bgColor = document.getElementById('inpBgColor').value;
        render();
    };

    document.getElementById('btnBold').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.bold = !b.bold; selectBlock(selectedId); };
    document.getElementById('btnItalic').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.italic = !b.italic; selectBlock(selectedId); };
    document.getElementById('btnUnderline').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.underline = !b.underline; selectBlock(selectedId); };
    
    document.getElementById('alignLeft').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.align = 'left'; selectBlock(selectedId); render(); };
    document.getElementById('alignCenter').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.align = 'center'; selectBlock(selectedId); render(); };
    document.getElementById('alignRight').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.align = 'right'; selectBlock(selectedId); render(); };

    document.getElementById('valignTop').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.vAlign = 'top'; selectBlock(selectedId); render(); };
    document.getElementById('valignMiddle').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.vAlign = 'middle'; selectBlock(selectedId); render(); };
    document.getElementById('valignBottom').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.vAlign = 'bottom'; selectBlock(selectedId); render(); };

    // SNAP & MOVE
    document.getElementById('snapLeft').onclick = () => {
        if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId);
        const mg = CONFIG.marginLeft ?? 53;
        b.x = mg; clampBlock(b); selectBlock(selectedId); render();
    };
    document.getElementById('snapCenter').onclick = () => {
        if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId);
        const pageW = CONFIG.pageW || 794;
        b.x = Math.round((pageW - b.w) / 2); clampBlock(b); selectBlock(selectedId); render();
    };
    document.getElementById('snapRight').onclick = () => {
        if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId);
        const pageW = CONFIG.pageW || 794; const mg = CONFIG.marginRight ?? 53;
        b.x = pageW - mg - b.w; clampBlock(b); selectBlock(selectedId); render();
    };
    document.getElementById('moveCenter').onclick = () => { document.getElementById('snapCenter').click(); };
    
    document.getElementById('moveUp').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.y -= 1; clampBlock(b); selectBlock(selectedId); render(); };
    document.getElementById('moveDown').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.y += 1; clampBlock(b); selectBlock(selectedId); render(); };
    document.getElementById('moveLeft').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.x -= 1; clampBlock(b); selectBlock(selectedId); render(); };
    document.getElementById('moveRight').onclick = () => { if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId); b.x += 1; clampBlock(b); selectBlock(selectedId); render(); };

    // VERTICAL SNAPS
    document.getElementById('snapTop').onclick = () => {
        if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId);
        const mg = CONFIG.marginTop ?? 53;
        b.y = mg; clampBlock(b); selectBlock(selectedId); render();
    };
    document.getElementById('snapVCenter').onclick = () => {
        if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId);
        const pageH = CONFIG.pageH || 1123;
        b.y = Math.round((pageH - b.h) / 2); clampBlock(b); selectBlock(selectedId); render();
    };
    document.getElementById('snapBottom').onclick = () => {
        if(!selectedId) return; const b=CONFIG.blocks.find(x=>x.id===selectedId);
        const pageH = CONFIG.pageH || 1123; const mg = CONFIG.marginBottom ?? 53;
        b.y = pageH - mg - b.h; clampBlock(b); selectBlock(selectedId); render();
    };

    // Clamp-Funktion: Block darf nie über die Ränder hinaus
    function clampBlock(b) {
        const pageW = CONFIG.pageW || 794;
        const pageH = CONFIG.pageH || 1123;
        const mg = {
            t: CONFIG.marginTop    ?? 53,
            r: CONFIG.marginRight  ?? 53,
            b: CONFIG.marginBottom ?? 53,
            l: CONFIG.marginLeft   ?? 53,
        };
        const maxW = pageW - mg.l - mg.r;
        const maxH = pageH - mg.t - mg.b;
        b.w = Math.min(b.w, maxW);
        b.h = Math.min(b.h, maxH);
        b.x = Math.max(mg.l, Math.min(b.x, pageW - mg.r - b.w));
        b.y = Math.max(mg.t, Math.min(b.y, pageH - mg.b - b.h));
        return b;
    }

    let dragging = null;
    function startDrag(e, id) {
        if (e.target.tagName === 'TEXTAREA' || e.target.tagName === 'INPUT') return;
        selectBlock(id);
        const b = CONFIG.blocks.find(x => x.id === id);
        dragging = { id, startX: e.clientX, startY: e.clientY, origX: b.x, origY: b.y };
        document.onmousemove = doDrag;
        document.onmouseup = () => { dragging = null; document.onmousemove = null; };
        e.preventDefault();
    }

    function doDrag(e) {
        if (!dragging) return;
        const b = CONFIG.blocks.find(x => x.id === dragging.id);
        b.x = dragging.origX + (e.clientX - dragging.startX);
        b.y = dragging.origY + (e.clientY - dragging.startY);
        clampBlock(b);
        document.getElementById('inpX').value = b.x;
        document.getElementById('inpY').value = b.y;
        render();
    }

    function addBlock() {
        const id = 'b_' + Date.now();
        CONFIG.blocks.push({ id, x: 100, y: 100, w: 300, h: 60, text: 'Neuer Textblock' });
        selectBlock(id);
    }

    function deleteBlock() {
        if (!selectedId) return;
        CONFIG.blocks = CONFIG.blocks.filter(x => x.id !== selectedId);
        selectedId = null;
        document.getElementById('noSelection').classList.remove('hidden');
        document.getElementById('selectionPanel').classList.add('hidden');
        render();
    }

    function addTag(tag) {
        if (!selectedId) return;
        const area = document.getElementById('inpText');
        const start = area.selectionStart;
        const end = area.selectionEnd;
        area.value = area.value.substring(0, start) + tag + area.value.substring(end);
        area.dispatchEvent(new Event('input'));
    }

    document.getElementById('cfgColor').oninput = (e) => { CONFIG.color = e.target.value; render(); };

    async function saveConfig() {
        const btn = document.getElementById('btnSave');
        btn.innerText = '⌛ Speichert...';
        try {
            const res = await fetch('../api/save_pdf_mask.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(CONFIG)
            }).then(r => r.json());
            btn.innerText = res.success ? '✅ Gespeichert' : '❌ Fehler';
        } catch(e) {
            btn.innerText = '❌ Fehler';
        }
        setTimeout(() => btn.innerText = '💾 Layout Speichern', 2000);
    }

    render();

    async function refreshTemplateList() {
        const resp = await fetch('../api/manage_pdf_templates.php?action=list');
        const res = await resp.json();
        if (res.success) {
            const sel = document.getElementById('selTemplate');
            sel.innerHTML = '';
            res.templates.forEach(t => {
                const opt = document.createElement('option');
                opt.value = t.id;
                opt.innerText = t.name + (t.is_default == 1 ? ' (Standard)' : '');
                if (t.is_default == 1) opt.selected = true;
                sel.appendChild(opt);
            });
        }
    }
    refreshTemplateList();

    document.getElementById('cfgColor').oninput = (e) => {
        CONFIG.color = e.target.value;
        render();
    };
    
    function setOrientation(o) {
        if (o === 'portrait') {
            CONFIG.pageW = 794;
            CONFIG.pageH = 1123;
        } else {
            CONFIG.pageW = 1123;
            CONFIG.pageH = 794;
        }
        syncPageInputs();
        render();
    }

    function syncPageInputs() {
        const w = CONFIG.pageW || 794;
        const h = CONFIG.pageH || 1123;
        document.getElementById('inpPageW').value = w;
        document.getElementById('inpPageH').value = h;
        document.getElementById('inpPageWCm').value = (w / 37.8).toFixed(1);
        document.getElementById('inpPageHCm').value = (h / 37.8).toFixed(1);
        
        document.getElementById('btnPortrait').style.background = (w < h) ? '#3498db' : '';
        document.getElementById('btnPortrait').style.color = (w < h) ? '#fff' : '';
        document.getElementById('btnLandscape').style.background = (w > h) ? '#3498db' : '';
        document.getElementById('btnLandscape').style.color = (w > h) ? '#fff' : '';
    }

    ['W','H'].forEach(dim => {
        const elPx = document.getElementById('inpPage'+dim);
        const elCm = document.getElementById('inpPage'+dim+'Cm');
        elPx.oninput = (e) => {
            const v = parseInt(e.target.value) || 0;
            CONFIG['page'+dim] = v;
            elCm.value = (v / 37.8).toFixed(1);
            render();
        };
        elCm.oninput = (e) => {
            const v = parseFloat(e.target.value) || 0;
            const px = Math.round(v * 37.8);
            CONFIG['page'+dim] = px;
            elPx.value = px;
            render();
        };
    });

    // Margin-Inputs sync PX <-> CM
    ['Top','Right','Bottom','Left'].forEach(side => {
        const elPx = document.getElementById('inpMargin'+side);
        const elCm = document.getElementById('inpMargin'+side+'Cm');
        
        if (elPx) {
            elPx.oninput = (e) => {
                const px = parseInt(e.target.value) || 0;
                CONFIG['margin'+side] = px;
                if (elCm) elCm.value = (px / 37.8).toFixed(1);
                render();
            };
        }
        
        if (elCm) {
            elCm.oninput = (e) => {
                const cm = parseFloat(e.target.value) || 0;
                const px = Math.round(cm * 37.8);
                CONFIG['margin'+side] = px;
                if (elPx) elPx.value = px;
                render();
            };
        }
    });
    // Legacy single margin input
    const legacyMargin = document.getElementById('inpMargin');
    if (legacyMargin) legacyMargin.oninput = (e) => {
        const v = parseInt(e.target.value) || 0;
        CONFIG.marginTop = CONFIG.marginRight = CONFIG.marginBottom = CONFIG.marginLeft = v;
        render();
    };

    document.getElementById('inpCoverH').oninput = (e) => {
        CONFIG.cover_height = parseInt(e.target.value) || 220;
        if (typeof refreshPreview === 'function' && previewOpen) refreshPreview();
    };
    document.getElementById('inpGalleryH').oninput = (e) => {
        CONFIG.gallery_height = parseInt(e.target.value) || 110;
        if (typeof refreshPreview === 'function' && previewOpen) refreshPreview();
    };

    async function loadTemplate() {
        const id = document.getElementById('selTemplate').value;
        if (!id) return;
        const resp = await fetch('../api/manage_pdf_templates.php?action=load&id=' + id);
        const res = await resp.json();
        if (res.success) {
            CONFIG = res.config;
            if (!CONFIG.blocks) CONFIG = { blocks: [], color: '#1abc9c' };
            document.getElementById('cfgColor').value = CONFIG.color || '#1abc9c';
            
            syncPageInputs();

            // Sync margin inputs
            const defM = 53;
            ['Top','Right','Bottom','Left'].forEach(s => {
                const el = document.getElementById('inpMargin'+s);
                const elCm = document.getElementById('inpMargin'+s+'Cm');
                const val = CONFIG['margin'+s] ?? defM;
                if (el) el.value = val;
                if (elCm) elCm.value = (val / 37.8).toFixed(1);
            });

            document.getElementById('inpCoverH').value = CONFIG.cover_height ?? 220;
            document.getElementById('inpGalleryH').value = CONFIG.gallery_height ?? 110;
            selectedId = null;
            render();
            alert("Vorlage geladen!");
        }
    }

    async function saveTemplate() {
        const name = document.getElementById('tplName').value;
        if (!name) { alert("Bitte gib einen Namen für die Vorlage ein."); return; }
        const resp = await fetch('../api/manage_pdf_templates.php?action=save', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ name: name, config: CONFIG })
        });
        const res = await resp.json();
        if (res.success) {
            alert("Vorlage gespeichert!");
            document.getElementById('tplName').value = '';
            refreshTemplateList();
        }
    }

    async function setDefaultTemplate() {
        const id = document.getElementById('selTemplate').value;
        if (!id) return;
        const resp = await fetch('../api/manage_pdf_templates.php?action=set_default', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ id: id })
        });
        const res = await resp.json();
        if (res.success) {
            alert("Standard-Vorlage wurde aktualisiert!");
            refreshTemplateList();
            if (previewOpen) refreshPreview();
        }
    }

    let previewOpen = false;
    function togglePreview() {
        const panel = document.getElementById('pdf-preview-panel');
        previewOpen = !previewOpen;
        panel.style.width = previewOpen ? '450px' : '0';
        if (previewOpen) refreshPreview();
    }

    function refreshPreview() {
        const frame = document.getElementById('pdf-frame');
        // Wir nehmen ID 87 als Test-ID (oder dynamisch falls bekannt)
        frame.src = 'pendenz_pdf.php?id=87&t=' + Date.now();
    }
    
    // In saveConfig einbauen
    async function saveConfig() {
        const btn = document.getElementById('btnSave');
        const oldText = btn.innerText;
        btn.innerText = '⌛ Speichert...';
        try {
            const res = await fetch('../api/save_pdf_mask.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(CONFIG)
            }).then(r => r.json());
            if (res.success) {
                btn.innerText = '✅ Gespeichert';
                if (previewOpen) refreshPreview();
            } else {
                btn.innerText = '❌ Fehler';
            }
        } catch(e) {
            btn.innerText = '❌ Fehler';
        }
        setTimeout(() => btn.innerText = oldText, 2000);
    }

    syncPageInputs();
    render();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
