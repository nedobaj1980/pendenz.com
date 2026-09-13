<?php
/**
 * includes/quick_capture.php
 * Reusable Quick Capture Component for Pendenzen.
 * Includes Floating Action Button (FAB), Modal, and JS Logic.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/auth.php';

// Fetch basic data needed for the form (or we fetch via AJAX later)
// For now, let's assume we want some data pre-rendered or we use AJAX.
// Using AJAX is cleaner for a global component.
?>

<?php if (is_logged_in()): ?>
<!-- FAB: Floating Action Button -->
<div id="quick-pendenz-fab" title="Schnell Pendenz erfassen">
    <span class="fab-icon">+</span>
</div>

<!-- Modal Overlay -->
<div id="quick-pendenz-modal" class="qpm-modal">
    <div class="qpm-content">
        <div class="qpm-header">
            <h3>🚀 Schnell Pendenz</h3>
            <span class="qpm-close">&times;</span>
        </div>
        <form id="quick-pendenz-form" method="POST" action="<?= page_url('ajax_quick_pendenz.php') ?>"
            enctype="multipart/form-data" data-offline-sync data-no-reload="true">
            <div class="qpm-body">
                <!-- 1. Vorgangsart -->
                <div class="qpm-row" style="grid-template-columns: 2fr 1fr;">
                    <div class="qpm-field">
                        <label>1. Vorgangsart</label>
                        <select name="vorgangsart_id" id="qp_vorgangsart_id" required>
                            <option value="">— auswählen —</option>
                        </select>
                    </div>
                    <div class="qpm-field">
                        <label>Priorität</label>
                        <select name="wichtigkeit" id="qp_wichtigkeit">
                            <option value="3" selected>3 - Mittel</option>
                            <option value="5">5 - Sehr wichtig</option>
                            <option value="4">4 - Wichtig</option>
                            <option value="2">2 - Gering</option>
                            <option value="1">1 - Info</option>
                        </select>
                    </div>
                </div>

                <!-- 2. Location Grid -->
                <div class="qpm-row">
                    <div class="qpm-field">
                        <label>Projekt</label>
                        <select name="projekt_id" id="qp_projekt_id">
                            <option value="">— kein Projekt —</option>
                        </select>
                    </div>
                    <div class="qpm-field">
                        <label>Objekt</label>
                        <select name="objekt_id" id="qp_objekt_id">
                            <option value="">— kein Objekt —</option>
                        </select>
                    </div>
                </div>
                <div class="qpm-row triple">
                    <div class="qpm-field">
                        <label>Wohnung</label>
                        <div style="display:flex; gap:8px;">
                            <select name="wohnung_id" id="qp_wohnung_id" style="flex:1;">
                                <option value="">— keine Wohnung —</option>
                            </select>
                            <button type="button" onclick="openQpPlanPicker()" style="padding: 0 8px; font-size: 11px; border: 1px solid #cbd5e1; background: #fff; border-radius: 6px; cursor: pointer;">📍 Auf Plan markieren</button>
                        </div>
                        <input type="hidden" name="plan_id" id="qp_plan_id" value="">
                        <input type="hidden" name="pin_x" id="qp_pin_x" value="">
                        <input type="hidden" name="pin_y" id="qp_pin_y" value="">
                        <input type="hidden" name="plan_pins" id="qp_plan_pins" value="[]">
                        <input type="hidden" name="plan_snapshot_data" id="qp_plan_snapshot_data" value="">
                    </div>
                    <div class="qpm-field">
                        <label>Raum</label>
                        <select name="raum_id" id="qp_raum_id">
                            <option value="">— kein Raum —</option>
                        </select>
                    </div>
                    <div class="qpm-field">
                        <label>Empfänger</label>
                        <select name="zustaendig_id" id="qp_zustaendig_id" required>
                            <option value="">— auswählen —</option>
                        </select>
                    </div>
                </div>


                <!-- 3. & 4. Vorlagen & Titel -->
                <div id="qpm-world-mieter" class="qpm-world-section" style="display:none;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div class="qpm-field">
                                <label>Mieterkategorie</label>
                                <select name="mieter_kategorie_id" id="qp_mieter_kategorie_id">
                                    <option value="">— keine Vorlage —</option>
                                </select>
                            </div>
                            <div class="qpm-field">
                                <label>Kurzbeschreibung Vorlage</label>
                                <select name="mieter_subkategorie_id" id="qp_mieter_subkategorie_id">
                                    <option value="">— keine Vorlage —</option>
                                </select>
                            </div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div class="qpm-field">
                                <label>Titel manuell</label>
                                <input type="text" name="titel_manuell_m" id="qp_titel_manuell_m" placeholder="z. B. Defekt">
                            </div>
                            <div class="qpm-field">
                                <label>Kurzbeschreibung manuell</label>
                                <input type="text" name="kurzbeschreibung_manuell_m" id="qp_kurzbeschreibung_manuell_m" placeholder="...">
                            </div>
                        </div>
                    </div>
                </div>

                <div id="qpm-world-vermieter" class="qpm-world-section" style="display:none;">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div class="qpm-field">
                                <label>Vermieterkategorie</label>
                                <select name="vermieter_kategorie_id" id="qp_vermieter_kategorie_id">
                                    <option value="">— auswählen —</option>
                                </select>
                            </div>
                            <div class="qpm-field">
                                <label>Kurzbeschreibung Vorlage</label>
                                <select name="vermieter_subkategorie_id" id="qp_vermieter_subkategorie_id">
                                    <option value="">— keine Vorlage —</option>
                                </select>
                            </div>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <div class="qpm-field">
                                <label>Titel manuell</label>
                                <input type="text" name="titel_manuell_v" id="qp_titel_manuell_v" placeholder="z. B. Badezimmer">
                            </div>
                            <div class="qpm-field">
                                <label>Kurzbeschreibung manuell</label>
                                <input type="text" name="kurzbeschreibung_manuell_v" id="qp_kurzbeschreibung_manuell_v" placeholder="...">
                            </div>
                        </div>
                    </div>
                </div>

                <div id="qpm-world-bkp" class="qpm-world-section" style="display:none;">
                    <!-- Reihe 1: BKP Code -->
                    <div class="qpm-row">
                        <div class="qpm-field" style="grid-column: span 2;">
                            <label>BKP Code</label>
                            <select name="bkp_id" id="qp_bkp_id">
                                <option value="">— kein BKP —</option>
                            </select>
                        </div>
                    </div>
                    <!-- Reihe 2: BKP Titel (Vorlage) & Titel manuell -->
                    <div class="qpm-row">
                        <div class="qpm-field">
                            <label>BKP Titel (Vorlage)</label>
                            <select name="bkp_kategorie_id" id="qp_bkp_kategorie_id">
                                <option value="">— auswählen —</option>
                            </select>
                        </div>
                        <div class="qpm-field">
                            <label>Titel manuell</label>
                            <input type="text" name="titel_manuell_bkp" id="qp_titel_manuell_bkp" placeholder="Eigener Titel...">
                        </div>
                    </div>
                    <!-- Reihe 3: BKP Beschreibung (Vorlage) & Kurzbeschreibung manuell -->
                    <div class="qpm-row">
                        <div class="qpm-field">
                            <label>BKP Beschreibung (Vorlage)</label>
                            <select name="bkp_text_id" id="qp_bkp_text_id">
                                <option value="">— auswählen —</option>
                            </select>
                        </div>
                        <div class="qpm-field">
                            <label>Kurzbeschreibung manuell</label>
                            <input type="text" name="kurzbeschreibung_manuell_bkp" id="qp_kurzbeschreibung_manuell_bkp" placeholder="Eigene Beschreibung...">
                        </div>
                    </div>
                </div>

                <!-- 5. Beschreibung & Notiz (Global) -->
                <div class="qpm-row">
                    <div class="qpm-field">
                        <label>Beschreibung manuell</label>
                        <textarea name="beschreibung_manuell" id="qp_beschreibung_manuell" placeholder="Freier Text für Details" style="min-height: 50px; height: 50px;"></textarea>
                    </div>
                    <div class="qpm-field">
                        <label>Notiz manuell</label>
                        <textarea name="notiz_manuell" id="qp_notiz_manuell" placeholder="Interne Notizen" style="min-height: 50px; height: 50px;"></textarea>
                    </div>
                </div>



                <!-- 6. Zeit -->
                <div class="qpm-row quad">
                    <div class="qpm-field">
                        <label>Startdatum</label>
                        <input type="date" name="startdatum" id="qp_startdatum">
                    </div>
                    <div class="qpm-field">
                        <label>Dauer (Tage)</label>
                        <input type="number" name="dauer" id="qp_dauer" placeholder="0">
                    </div>
                    <div class="qpm-field">
                        <label>Uhrzeit</label>
                        <input type="time" name="uhrzeit" id="qp_uhrzeit">
                    </div>
                    <div class="qpm-field">
                        <label>Enddatum</label>
                        <input type="date" name="enddatum" id="qp_enddatum">
                    </div>
                </div>

                <!-- 7. Bilder & Dokumente -->
                <div class="qpm-row" style="margin-top: 10px; gap: 20px;">
                    <div class="qpm-field">
                        <label>📸 Bilder</label>
                        <input type="file" name="bilder[]" id="qp_bilder" accept="image/*" multiple>
                    </div>
                    <div class="qpm-field">
                        <label>📄 Dokumente (PDF, etc.)</label>
                        <input type="file" name="dokumente[]" id="qp_dokumente"
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.txt,.zip" multiple>
                    </div>
                </div>

                <!-- 8. Optionen & Status -->

                <div class="qpm-footer-grid">
                    <div class="qpm-field footer-status">
                        <select name="status" id="qp_status">
                            <option value="offen">offen</option>
                            <option value="in Bearbeitung">in Bearbeitung</option>
                            <option value="erledigt">erledigt</option>
                        </select>
                    </div>
                    <div class="qpm-checkboxes">
                        <label class="qpm-check"><input type="checkbox" name="send_now"> sofort senden</label>
                        <label class="qpm-check"><input type="checkbox" name="confirmation_required"> Bestätigung
                            nötig</label>
                        <label class="qpm-check"><input type="checkbox" name="external_can_view" checked> extern
                            sichtbar</label>
                        <label class="qpm-check"><input type="checkbox" name="external_can_upload"> externe
                            Uploads</label>
                        <label class="qpm-check"><input type="checkbox" name="public_enabled"> öffentlich aktiv</label>
                    </div>
                </div>
                <div class="qpm-actions" style="margin-top: 20px; text-align: right; border-top: 1px solid #f1f5f9; padding-top: 15px;">
                    <button type="submit" class="qpm-btn-save">💾 Pendenz speichern</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Plan Picker Modal (Global) -->
<div id="qpPlanPickerModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.8); z-index:10001; align-items:center; justify-content:center;">
    <div style="background:#fff; width:95%; max-width:1200px; height:90%; border-radius:16px; display:flex; flex-direction:column; overflow:hidden; box-shadow:0 25px 50px -12px rgba(0,0,0,0.25);">
        <div style="padding:16px 24px; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; align-items:center; background:#f8fafc;">
            <h3 style="margin:0; font-size:20px; color:#0f172a;">📍 Ort auf Plan markieren</h3>
            <div style="display:flex; gap:12px; align-items:center;">
                <select id="qpPlanSelect" style="padding:8px 12px; border:1px solid #cbd5e1; border-radius:6px; background:#fff; min-width:200px;" onchange="loadQpSelectedPlan()">
                    <option value="">-- Plan laden --</option>
                </select>
                <button type="button" onclick="closeQpPlanPicker()" style="background:none; border:none; font-size:24px; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
            </div>
        </div>
        
        <div style="flex:1; overflow:auto; display:flex; background:#e2e8f0; position:relative;" id="qpPlanAreaWrap">
            <!-- Toolbar -->
            <div style="position:fixed; bottom:80px; left:50%; transform:translateX(-50%); background:white; padding:8px; border-radius:30px; box-shadow:0 4px 15px rgba(0,0,0,0.3); z-index:10002; display:flex; gap:10px; border:1px solid #e2e8f0;">
                <button type="button" class="qp-draw-tool active" data-tool="pin" onclick="setQpDrawTool('pin')" style="background:#ef4444; color:white; border:none; padding:8px 16px; border-radius:20px; font-weight:600; cursor:pointer; font-size:14px; transition:0.2s;">📍 Pin</button>
                <button type="button" class="qp-draw-tool" data-tool="line" onclick="setQpDrawTool('line')" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:8px 16px; border-radius:20px; font-weight:600; cursor:pointer; font-size:14px; transition:0.2s;">✏️ Linie</button>
                <button type="button" class="qp-draw-tool" data-tool="rect" onclick="setQpDrawTool('rect')" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:8px 16px; border-radius:20px; font-weight:600; cursor:pointer; font-size:14px; transition:0.2s;">🔲 Fläche</button>
                <button type="button" onclick="undoQpLastDraw()" style="background:#f8fafc; color:#334155; border:1px solid #cbd5e1; padding:8px 16px; border-radius:20px; font-weight:600; cursor:pointer; font-size:14px; transition:0.2s;">↩️ Zurück</button>
            </div>
            
            <div id="qpPlanContainer" style="position:relative; margin:auto; cursor:crosshair; box-shadow:0 10px 15px -3px rgba(0,0,0,0.1); background:#fff; display:none; flex-shrink:0;">
                <img id="qpPlanImage" style="display:block; max-width:100%; max-height:100%; object-fit:contain; pointer-events:none; user-select:none;" crossorigin="anonymous">
                <svg id="qpDrawOverlay" viewBox="0 0 100 100" preserveAspectRatio="none" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:40; overflow:visible;"></svg>
            </div>
            <div id="qpPlanLoading" style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); display:none; color:#475569; font-weight:600;">Pläne werden geladen...</div>
            <div id="qpPlanEmpty" style="position:absolute; top:50%; left:50%; transform:translate(-50%, -50%); display:none; text-align:center; color:#64748b; font-weight:600;">Keine Pläne für dieses Objekt vorhanden.</div>
        </div>
        
        <div style="padding:16px 24px; border-top:1px solid #e2e8f0; background:#f8fafc; display:flex; justify-content:space-between; align-items:center;">
            <div style="color:#64748b; font-size:14px;" id="qpPlanStatusText">Bitte klicke auf den Plan, um einen Pin zu setzen.</div>
            <div style="display:flex; gap:12px;">
                <button type="button" class="qpm-btn-save" style="background:#64748b;" onclick="clearQpPlanPin()">Pin entfernen</button>
                <button type="button" class="qpm-btn-save" onclick="applyQpPlanPin()" id="qpApplyPinBtn" disabled>Pin übernehmen</button>
            </div>
        </div>
    </div>
</div>
<?php endif; // end modal logged in check ?>

<style>
    /* FAB Styling */
    #quick-pendenz-fab {
        position: fixed;
        bottom: 25px;
        right: 25px;
        width: 65px;
        height: 65px;
        background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        box-shadow: 0 10px 25px rgba(37, 99, 235, 0.4);
        cursor: pointer;
        z-index: 9999;
        transition: all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    #quick-pendenz-fab:hover {
        transform: scale(1.1) rotate(90deg);
        box-shadow: 0 15px 30px rgba(37, 99, 235, 0.5);
    }

    #quick-pendenz-fab .fab-icon {
        font-size: 36px;
        font-weight: 300;
    }

    /* Modal Styling */
    .qpm-modal {
        display: none;
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(15, 23, 42, 0.6);
        backdrop-filter: blur(8px);
    }

    body.qpm-lock-scroll {
        overflow: hidden !important;
    }

    .qpm-modal {
        font-family: 'Inter', system-ui, -apple-system, sans-serif;
        font-size: 12px;
        color: #1e293b;
        overflow-x: hidden;
    }

    /* Scrollbar Styling */
    .qpm-body::-webkit-scrollbar {
        width: 10px;
    }

    .qpm-body::-webkit-scrollbar-track {
        background: #f8fafc;
        border-radius: 0 0 20px 0;
    }

    .qpm-body::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
        border: 2px solid #f8fafc;
    }

    .qpm-body::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .qpm-body {
        scrollbar-width: auto;
        scrollbar-color: #cbd5e1 #f8fafc;
    }

    .qpm-content {
        background: #ffffff;
        margin: 2vh auto;
        width: 95%;
        max-width: 850px;
        max-height: 96vh;
        border-radius: 28px;
        box-shadow: 0 25px 50px -12px rgba(37, 99, 235, 0.2);
        overflow: hidden;
        overflow-x: hidden;
        animation: qpmSlideUp 0.4s ease-out;
        display: flex;
        flex-direction: column;
    }

    @keyframes qpmSlideUp {
        from {
            transform: translateY(30px);
            opacity: 0;
        }

        to {
            transform: translateY(0);
            opacity: 1;
        }
    }

    .qpm-header {
        background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        padding: 6px 20px;
        border-bottom: 1px solid #bfdbfe;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .qpm-header h3 {
        margin: 0;
        font-size: 14px;
        font-weight: 800;
        color: #1d4ed8;
    }

    .qpm-close {
        font-size: 28px;
        font-weight: bold;
        color: #1d4ed8;
        cursor: pointer;
        transition: 0.2s;
        padding: 2px 10px;
        opacity: 0.6;
    }

    .qpm-close:hover {
        color: #ef4444;
        opacity: 1;
    }

    .qpm-body { padding: 10px 20px 20px 20px; flex: 1; overflow-y: auto; overflow-x: hidden; min-height: 0; overscroll-behavior: contain; }

    .qpm-row {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
        margin-bottom: 10px;
    }

    .qpm-row.triple {
        grid-template-columns: repeat(3, 1fr);
    }

    .qpm-row.quad {
        grid-template-columns: 1fr 1.2fr 1fr 1fr;
    }

    .qpm-field {
        margin-bottom: 10px;
    }

    .qpm-field.full {
        width: 100%;
    }

    .qpm-field label {
        display: block;
        font-size: 10px;
        font-weight: 700;
        color: #64748b;
        margin-bottom: 4px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .qpm-field input,
    .qpm-field select,
    .qpm-field textarea {
        width: 100%;
        height: 32px;
        padding: 4px 10px;
        border: 1px solid #e2e8f0;
        border-radius: 8px;
        font-size: 12px;
        font-family: inherit;
        color: #1e293b;
        transition: 0.2s;
        background: #fff;
        box-sizing: border-box;
    }

    .qpm-field textarea {
        height: auto;
    }

    .qpm-field input[type="file"] {
        padding: 4px;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
    }

    .qpm-field input[type="date"],
    .qpm-field input[type="time"],
    .qpm-field input[type="number"] {
        padding: 4px 8px;
        font-size: 12px;
        height: 32px;
        box-sizing: border-box;
    }

    .qpm-field input:focus,
    .qpm-field select:focus,
    .qpm-field textarea:focus {
        border-color: #2563eb;
        outline: none;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .qpm-field textarea {
        min-height: 60px;
        resize: vertical;
    }

    .qpm-footer-grid {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
        border-top: 1px solid #f1f5f9;
        padding-top: 10px;
        margin-top: 5px;
    }

    .qpm-checkboxes {
        display: flex;
        align-items: center;
        gap: 4px 10px;
        flex-wrap: wrap;
        flex: 1;
    }

    .qpm-field.footer-status {
        min-width: 100px;
        margin-bottom: 0;
    }

    .qpm-field.footer-status select {
        padding: 4px 8px;
        font-size: 10px;
        height: 26px;
        font-weight: 700;
        background: #f8fafc;
    }

    .qpm-check {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 11px;
        font-weight: 700;
        color: #475569;
        cursor: pointer;
    }

    .qpm-check input {
        width: 16px;
        height: 16px;
        accent-color: #3b82f6;
    }

    .qpm-actions {
        padding: 15px 20px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        text-align: right;
    }

    .qpm-btn-save {
        background: linear-gradient(135deg, #0f766e 0%, #14b8a6 100%);
        color: #fff;
        border: 0;
        padding: 10px 24px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        cursor: pointer;
        transition: 0.2s;
        box-shadow: 0 4px 12px rgba(20, 184, 166, 0.2);
    }

    .qpm-btn-save:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 16px rgba(37, 99, 235, 0.3);
    }

    /* Mobile Adjustments */
    @media (max-width: 768px) {
        .qpm-content {
            margin: 0;
            width: 100%;
            height: 100%;
            max-width: 100%;
            border-radius: 0;
            display: flex;
            flex-direction: column;
        }

        .qpm-header {
            padding: 10px 15px;
            flex-shrink: 0;
        }

        .qpm-body { 
        padding: 10px 12px 20px 12px; 
        flex: 1;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        min-height: 0;
        touch-action: pan-y;
        overscroll-behavior: contain;
    }

        .qpm-actions {
            padding: 10px 15px;
            flex-shrink: 0;
            background: #fff;
            border-top: 1px solid #e2e8f0;
            position: sticky;
            bottom: 0;
            z-index: 10;
        }

        .qpm-btn-save {
            width: 100%;
            padding: 12px;
            font-size: 15px;
        }

        .qpm-row {
            display: grid;
            gap: 4px;
            margin-bottom: 4px;
        }

        /* Keep multi-column layout on mobile, just make it tighter */
        .qpm-row.quad {
            grid-template-columns: 1fr 1.2fr 1fr 1fr !important;
        }

        .qpm-row.triple {
            grid-template-columns: 1fr 1fr 1fr !important;
        }

        .qpm-field {
            margin-bottom: 2px;
        }

        .qpm-field label {
            font-size: 8px;
            margin-bottom: 0px;
        }

        .qpm-field input,
        .qpm-field select,
        .qpm-field textarea {
            font-size: 14px;
            padding: 5px 8px;
        }

        .qpm-field input[type="date"],
        .qpm-field input[type="time"],
        .qpm-field input[type="number"] {
            padding: 2px 3px;
            font-size: 11px;
            height: 24px;
            box-sizing: border-box;
        }

        .qpm-footer-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .qpm-checkboxes {
            display: flex;
            flex-wrap: wrap;
            gap: 4px 8px;
        }

        .qpm-check {
            padding: 4px 6px;
            background: #f1f5f9;
            border-radius: 4px;
            font-size: 9px;
            white-space: nowrap;
        }
    }

    /* Landscape Optimization */
    @media (max-height: 500px) and (orientation: landscape) {
        .qpm-content {
            margin: 0;
            height: 100%;
            width: 100%;
            border-radius: 0;
        }

        .qpm-header {
            padding: 5px 15px;
        }

        .qpm-header h3 {
            font-size: 12px;
        }

        .qpm-body {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 15px;
            padding: 5px 15px;
            max-height: calc(100vh - 80px);
        }

        .qpm-row {
            margin-bottom: 2px !important;
            gap: 4px !important;
        }

        .qpm-field {
            margin-bottom: 2px !important;
        }

        .qpm-field label {
            font-size: 7px;
        }

        .qpm-field input,
        .qpm-field select,
        .qpm-field textarea {
            padding: 3px 6px;
            font-size: 12px;
            height: 22px;
        }

        .qpm-field textarea {
            min-height: 35px;
        }

        .qpm-footer-grid {
            grid-column: span 2;
            padding-top: 5px;
            margin-top: 2px;
        }

        .qpm-actions {
            padding: 5px 15px;
        }

        .qpm-btn-save {
            padding: 6px;
            font-size: 13px;
        }

        .qpm-world-section {
            grid-column: span 2;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 15px;
        }

        .qpm-row {
            grid-column: span 2;
        }

        .qpm-row.quad,
        .qpm-row.triple {
            grid-column: span 2;
        }

        #qp_beschreibung_manuell {
            height: 40px;
        }
    }
</style>

<script>
    (function () {
        const fab = document.getElementById('quick-pendenz-fab');
        const modal = document.getElementById('quick-pendenz-modal');
        const close = document.querySelector('.qpm-close');
        const form = document.getElementById('quick-pendenz-form');

        if (!fab || !modal || !close) return;

        // State
        let qpData = null;

        fab.onclick = () => {
            modal.style.display = "block";
            document.body.classList.add('qpm-lock-scroll');
            
            // Startdatum auf heute setzen
            const startEl = document.getElementById('qp_startdatum');
            if (!startEl.value) {
                startEl.value = new Date().toISOString().split('T')[0];
            }

            if (!qpData) loadQuickData();
        };

        const qpmClose = () => {
            modal.style.display = "none";
            document.body.classList.remove('qpm-lock-scroll');
        };

        close.onclick = qpmClose;

        window.onclick = (event) => {
            if (event.target == modal) {
                qpmClose();
            }
        };

        // Pre-fetch for offline use
        loadQuickData();

        async function loadQuickData(retryCount = 0) {
            // 1. Wait for OfflineSync if it's currently loading
            if (typeof OfflineSync === 'undefined' || !OfflineSync.initPromise) {
                if (retryCount < 10) {
                    setTimeout(() => loadQuickData(retryCount + 1), 200);
                    return;
                }
            }

            // 2. Try to load from cache first for immediate UI
            if (typeof OfflineSync !== 'undefined') {
                try {
                    const cached = await OfflineSync.getMeta('quick_init_data');
                    if (cached) {
                        qpData = cached;
                        renderAllOptions();
                        console.log("Quick Capture: Loaded from cache");
                    } else if (!navigator.onLine) {
                        if (typeof OfflineSync.showNotification === 'function') {
                            OfflineSync.showNotification('Offline-Modus: Bitte lade die Seite einmal mit Internet neu.', 'info');
                        }
                    }
                } catch(e) {
                    console.error("Cache load failed", e);
                }
            }

            // 3. Try to fetch fresh data from server
            try {
                const ts = new Date().getTime();
                const response = await fetch('<?= page_url('ajax_quick_pendenz.php?action=get_init_data') ?>&_t=' + ts);
                const freshData = await response.json();
                if (freshData.success) {
                    qpData = freshData;
                    renderAllOptions();
                    
                    // Cache it for next time (offline use)
                    if (typeof OfflineSync !== 'undefined') {
                        OfflineSync.setMeta('quick_init_data', qpData);
                    }
                }
            } catch (err) {
                console.error("Server fetch failed", err);
            }
        }

        function renderAllOptions() {
            if (!qpData) return;
            renderOptions('qp_vorgangsart_id', qpData.arten, 'name');
            renderOptions('qp_projekt_id', qpData.projekte, 'name');
            renderOptions('qp_vermieter_kategorie_id', qpData.vermieter_kategorien, 'name');
            renderOptions('qp_mieter_kategorie_id', qpData.mieter_kategorien, 'name');
            renderOptions('qp_bkp_id', qpData.bkp_codes, 'name');
            renderOptions('qp_zustaendig_id', qpData.users, 'label');
            detectContext();
        }

        function renderOptions(elId, data, labelKey) {
            const el = document.getElementById(elId);
            if (!el || !data) return;
            const currentVal = el.value;
            el.innerHTML = '<option value="">— auswählen —</option>';
            data.forEach(item => {
                const opt = document.createElement('option');
                opt.value = item.id;
                opt.textContent = (item.icon ? item.icon + ' ' : '') + (item[labelKey] || item.name || '');
                if (item.id == currentVal) opt.selected = true;
                el.appendChild(opt);
            });
        }

        // Vorgangsart Change -> Apply Defaults & Toggle World
        document.getElementById('qp_vorgangsart_id').onchange = (e) => {
            const artId = e.target.value;
            const art = qpData.arten.find(a => a.id == artId);
            const defaults = qpData.defaultsByArt[artId] ? qpData.defaultsByArt[artId][0] : null;

            if (art) {
                // Toggle Visibility
                const welt = deriveWelt(art);
                document.querySelectorAll('.qpm-world-section').forEach(s => s.style.display = 'none');
                const target = document.getElementById('qpm-world-' + welt);
                if (target) target.style.display = 'block';

                // Prefill Project
                const targetPid = defaults?.projekt_id || art.default_projekt_id;
                if (targetPid) {
                    const elP = document.getElementById('qp_projekt_id');
                    elP.value = targetPid;
                    // Trigger Project change logic (filters objects)
                    const filteredObj = qpData.objekte.filter(o => !targetPid || o.projekt_id == targetPid);
                    renderOptions('qp_objekt_id', filteredObj, 'name');
                }

                // Prefill Object
                const targetOid = defaults?.objekt_id || art.default_objekt_id;
                if (targetOid) {
                    setTimeout(() => {
                        const elO = document.getElementById('qp_objekt_id');
                        if (elO) {
                            elO.value = targetOid;
                            // Trigger Object change logic (filters units)
                            const filteredWohn = qpData.wohnungen.filter(w => !targetOid || w.objekt_id == targetOid);
                            renderOptions('qp_wohnung_id', filteredWohn, 'name');
                            
                            // Prefill Wohnung
                            const targetWid = defaults?.wohnung_id || art.default_wohnung_id;
                            if (targetWid) {
                                setTimeout(() => {
                                    const elW = document.getElementById('qp_wohnung_id');
                                    if (elW) {
                                        elW.value = targetWid;
                                        elW.dispatchEvent(new Event('change'));
                                    }
                                }, 100);
                            }
                        }
                    }, 100);
                }

                // Recipient (Empfänger)
                if (art.default_benutzer_id) document.getElementById('qp_zustaendig_id').value = art.default_benutzer_id;
                if (defaults?.benutzer_id) document.getElementById('qp_zustaendig_id').value = defaults.benutzer_id;

                // Vermieter / Mieter Templates
                if (art.default_vermieter_kategorie_id || defaults?.vermieter_kategorie_id) {
                    const vkId = art.default_vermieter_kategorie_id || defaults.vermieter_kategorie_id;
                    document.getElementById('qp_vermieter_kategorie_id').value = vkId;
                    document.getElementById('qp_vermieter_kategorie_id').dispatchEvent(new Event('change'));
                }
                if (art.default_mieter_kategorie_id || defaults?.mieter_kategorie_id) {
                    const mkId = art.default_mieter_kategorie_id || defaults.mieter_kategorie_id;
                    document.getElementById('qp_mieter_kategorie_id').value = mkId;
                    document.getElementById('qp_mieter_kategorie_id').dispatchEvent(new Event('change'));
                }
                // Prefill BKP (only if mode is not 'all')
                const bkpMode = defaults?.bkp_mode || art.default_bkp_mode || 'single';
                if (bkpMode === 'single' && (art.default_bkp_id || defaults?.bkp_id)) {
                    const bid = art.default_bkp_id || defaults.bkp_id;
                    document.getElementById('qp_bkp_id').value = bid;
                    document.getElementById('qp_bkp_id').dispatchEvent(new Event('change'));
                }
                
                // Refresh BKP dropdown based on company BKP setting
                filterQpBkp();
            }
        };

        function deriveWelt(art) {
            const welt = String(art?.vorlagen_welt || '').trim();
            const empfaengerTyp = String(art?.empfaenger_typ || '').trim().toLowerCase();
            if (welt === 'mieter' || welt === 'vermieter') return welt;
            if (['mieter', 'mietinteressent', 'vormieter'].includes(empfaengerTyp)) return 'mieter';
            if (['vermieter', 'eigentuemer', 'eigentümer', 'verwaltung'].includes(empfaengerTyp)) return 'vermieter';
            return welt || 'bkp';
        }

        // Cascading logic
        document.getElementById('qp_projekt_id').onchange = (e) => {
            const pid = e.target.value;
            const filtered = qpData.objekte.filter(o => !pid || o.projekt_id == pid);
            renderOptions('qp_objekt_id', filtered, 'name');
            document.getElementById('qp_wohnung_id').innerHTML = '<option value="">— auswählen —</option>';
            document.getElementById('qp_raum_id').innerHTML = '<option value="">— auswählen —</option>';
        };

        document.getElementById('qp_objekt_id').onchange = (e) => {
            const oid = e.target.value;
            const filtered = qpData.wohnungen.filter(w => !oid || w.objekt_id == oid);
            renderOptions('qp_wohnung_id', filtered, 'name');
            document.getElementById('qp_raum_id').innerHTML = '<option value="">— auswählen —</option>';
        };

        function filterQpBkp() {
            const artId = document.getElementById('qp_vorgangsart_id').value;
            const userId = document.getElementById('qp_zustaendig_id').value;
            const art = qpData.arten.find(a => a.id == artId);
            const defaults = qpData.defaultsByArt && artId && qpData.defaultsByArt[artId] ? qpData.defaultsByArt[artId][0] : null;
            
            const bkpMode = defaults?.bkp_mode || art?.default_bkp_mode || 'single';
            const defaultBkpId = art?.default_bkp_id || defaults?.bkp_id;
            
            const useFirma = (art && (String(art.nur_firmen_bkp || '0') === '1' || String(art.firma_bkp_filter || '0') === '1')) || bkpMode === 'all_firma';
            
            let allowedBkps = qpData.bkp_codes.slice();
            
            if (bkpMode === 'single' && defaultBkpId) {
                // Wenn Modus 'single' ist und ein Standard-BKP gesetzt ist, NUR dieses eine BKP anzeigen
                allowedBkps = qpData.bkp_codes.filter(b => String(b.id) === String(defaultBkpId));
            } else if (useFirma && userId) {
                // Firmen-Filter aktiv
                if (qpData.userFirmaBkpMap && qpData.userFirmaBkpMap[userId]) {
                    const allowedIds = qpData.userFirmaBkpMap[userId].map(String);
                    allowedBkps = qpData.bkp_codes.filter(b => allowedIds.includes(String(b.id)));
                } else {
                    // Wenn der Unternehmer gar keine Firmen-BKPs hat, ist die Liste leer!
                    allowedBkps = [];
                }
            }
            
            const currentVal = document.getElementById('qp_bkp_id').value;
            renderOptions('qp_bkp_id', allowedBkps, 'name');
            
            // Re-apply value if still allowed, or if single mode just auto-select it
            if (allowedBkps.some(b => String(b.id) === String(currentVal))) {
                document.getElementById('qp_bkp_id').value = currentVal;
            } else if (allowedBkps.length === 1) {
                document.getElementById('qp_bkp_id').value = allowedBkps[0].id;
                document.getElementById('qp_bkp_id').dispatchEvent(new Event('change'));
            }
        }

        document.getElementById('qp_zustaendig_id').onchange = filterQpBkp;

        document.getElementById('qp_wohnung_id').onchange = async (e) => {
            const wid = e.target.value;
            if (!wid) {
                document.getElementById('qp_raum_id').innerHTML = '<option value="">— auswählen —</option>';
                return;
            }
            try {
                const res = await fetch('<?= page_url('ajax_unit_rooms.php?wohnung_id=') ?>' + wid);
                const json = await res.json();
                if (json.success && json.data.rooms) {
                    renderOptions('qp_raum_id', json.data.rooms, 'name');
                }
            } catch (err) { console.error(err); }
        };

        document.getElementById('qp_vermieter_kategorie_id').onchange = (e) => {
            const kid = e.target.value;
            const subs = qpData.vermieter_subkategorien.filter(s => s.kategorie_id == kid);
            renderOptions('qp_vermieter_subkategorie_id', subs, 'name');
        };

        document.getElementById('qp_mieter_kategorie_id').onchange = (e) => {
            const kid = e.target.value;
            const subs = qpData.mieter_subkategorien.filter(s => s.kategorie_id == kid);
            renderOptions('qp_mieter_subkategorie_id', subs, 'name');
        };

        document.getElementById('qp_vermieter_subkategorie_id').onchange = (e) => {
            // Keine Aktion für manuelle Felder, wird im Backend zusammengeführt
        };

        document.getElementById('qp_mieter_subkategorie_id').onchange = (e) => {
            // Keine Aktion für manuelle Felder, wird im Backend zusammengeführt
        };

        document.getElementById('qp_bkp_id').onchange = (e) => {
            const bid = e.target.value;
            const kats = qpData.bkp_kategorien.filter(k => k.bkp_id == bid);
            renderOptions('qp_bkp_kategorie_id', kats, 'name');
            renderOptions('qp_bkp_text_id', [], 'name');
        };

        document.getElementById('qp_bkp_kategorie_id').onchange = (e) => {
            const kid = e.target.value;
            const txts = qpData.bkp_texte.filter(t => t.kategorie_id == kid);
            renderOptions('qp_bkp_text_id', txts, 'name');
        };

        document.getElementById('qp_bkp_text_id').onchange = (e) => {
            // Keine Aktion für manuelle Felder, wird im Backend zusammengeführt
        };


        // Date Logic
        document.getElementById('qp_dauer').addEventListener('input', updateEndDate);
        document.getElementById('qp_startdatum').addEventListener('change', updateEndDate);
        document.getElementById('qp_enddatum').addEventListener('change', updateDuration);

        function updateEndDate() {
            const startEl = document.getElementById('qp_startdatum');
            const dauerEl = document.getElementById('qp_dauer');
            const endEl = document.getElementById('qp_enddatum');

            let start = startEl.value;
            const dauer = parseInt(dauerEl.value) || 0;

            // If duration is entered but no start date, set start to today
            if (dauer > 0 && !start) {
                start = new Date().toISOString().split('T')[0];
                startEl.value = start;
            }

            if (start && dauer >= 0) {
                const date = new Date(start);
                date.setDate(date.getDate() + dauer);
                endEl.value = date.toISOString().split('T')[0];
            }
        }

        function updateDuration() {
            const startEl = document.getElementById('qp_startdatum');
            const dauerEl = document.getElementById('qp_dauer');
            const endEl = document.getElementById('qp_enddatum');

            if (startEl.value && endEl.value) {
                const start = new Date(startEl.value);
                const end = new Date(endEl.value);
                const diffTime = end - start;
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                dauerEl.value = diffDays >= 0 ? diffDays : 0;
            }
        }

        // Auto-Enddatum Berechnung
        const calcEndFromDauer = () => {
            const startVal = document.getElementById('qp_startdatum').value;
            const dauerVal = parseInt(document.getElementById('qp_dauer').value) || 0;
            if (startVal && dauerVal > 0) {
                const d = new Date(startVal);
                d.setDate(d.getDate() + dauerVal);
                document.getElementById('qp_enddatum').value = d.toISOString().split('T')[0];
            }
        };

        document.getElementById('qp_startdatum').addEventListener('change', calcEndFromDauer);
        document.getElementById('qp_dauer').addEventListener('input', calcEndFromDauer);

        // Context Detection (Project/Object/Unit from URL)
        function detectContext() {
            const params = new URLSearchParams(window.location.search);
            const pid = params.get('projekt_id');
            const oid = params.get('objekt_id');
            const wid = params.get('id') || params.get('wohnung_id');

            if (pid) {
                document.getElementById('qp_projekt_id').value = pid;
                document.getElementById('qp_projekt_id').dispatchEvent(new Event('change'));
            }
            if (oid) {
                setTimeout(() => {
                    document.getElementById('qp_objekt_id').value = oid;
                    document.getElementById('qp_objekt_id').dispatchEvent(new Event('change'));
                }, 250);
            }
            if (wid) {
                setTimeout(() => {
                    document.getElementById('qp_wohnung_id').value = wid;
                    document.getElementById('qp_wohnung_id').dispatchEvent(new Event('change'));
                }, 500);
            }
        }

        // Use OfflineSync events for handling the result
        form.addEventListener('offlineSyncSuccess', (e) => {
            const { online, response: res } = e.detail;
            
            if (!online) {
                // Stored in offline queue
                qpmClose();
                form.reset();
                // Reset plan fields
                document.getElementById('qp_plan_pins').value = '[]';
                document.getElementById('qp_plan_id').value = '';
                document.getElementById('qp_pin_x').value = '';
                document.getElementById('qp_pin_y').value = '';
                document.getElementById('qp_plan_snapshot_data').value = '';
                return;
            }

            // If online, handle the server response
            if (res && res.success) {
                modal.style.display = "none";
                form.reset();
                // Reset plan fields
                document.getElementById('qp_plan_pins').value = '[]';
                document.getElementById('qp_plan_id').value = '';
                document.getElementById('qp_pin_x').value = '';
                document.getElementById('qp_pin_y').value = '';
                document.getElementById('qp_plan_snapshot_data').value = '';
                
                if (typeof loadPendenzen === 'function') loadPendenzen();
                
                // Show brief success toast
                const toast = document.createElement('div');
                toast.style.cssText = 'position:fixed;bottom:80px;right:20px;background:#22c55e;color:#fff;padding:12px 20px;border-radius:8px;z-index:99999;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.3)';
                toast.textContent = '✅ Pendenz #' + res.pendenz_id + ' gespeichert!';
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 3500);
            } else if (res && !res.success) {
                alert('Fehler beim Speichern:\n\n' + (res.error || 'Unbekannter Fehler'));
            }
        });

        // --- Plan Picker Logic for Quick Capture ---
        let currentQpPlans = [];
        let currentQpZones = [];
        let activeQpPlanId = null;
        let currentQpPins = [];
        let currentQpDrawTool = 'pin';
        let isQpDrawing = false;
        let currentQpDrawObj = null;

        window.setQpDrawTool = function(tool) {
            currentQpDrawTool = tool;
            document.querySelectorAll('.qp-draw-tool').forEach(btn => {
                btn.style.background = '#f8fafc';
                btn.style.color = '#334155';
                btn.style.border = '1px solid #cbd5e1';
            });
            const activeBtn = document.querySelector(`.qp-draw-tool[data-tool="${tool}"]`);
            if (activeBtn) {
                activeBtn.style.background = '#ef4444';
                activeBtn.style.color = 'white';
                activeBtn.style.border = 'none';
            }
        };

        window.undoQpLastDraw = function() {
            if (currentQpPins.length > 0) {
                currentQpPins.pop();
                loadQpSelectedPlan();
            }
        };

        window.openQpPlanPicker = function() {
            const projId = document.getElementById('qp_projekt_id').value;
            if (!projId) {
                alert("Bitte wähle zuerst ein Projekt aus.");
                return;
            }
            
            document.getElementById('qpPlanPickerModal').style.display = 'flex';
            document.getElementById('qpPlanLoading').style.display = 'block';
            document.getElementById('qpPlanContainer').style.display = 'none';
            document.getElementById('qpPlanEmpty').style.display = 'none';
            
            currentQpPins = JSON.parse(document.getElementById('qp_plan_pins').value || '[]');
            
            const ts = new Date().getTime();
            fetch('<?= page_url('ajax_get_plans.php?projekt_id=') ?>' + projId + '&_t=' + ts)
                .then(r => r.json())
                .then(json => {
                    document.getElementById('qpPlanLoading').style.display = 'none';
                    if (json.error || !json.plaene || json.plaene.length === 0) {
                        document.getElementById('qpPlanEmpty').style.display = 'block';
                        return;
                    }
                    
                    currentQpPlans = json.plaene;
                    currentQpZones = json.zonen;
                    
                    const wDropdown = document.getElementById('qp_wohnung_id').value;
                    let allowedPlans = currentQpPlans;
                    
                    if (wDropdown) {
                        const wZones = currentQpZones.filter(z => z.wohnung_id == wDropdown);
                        if (wZones.length > 0) {
                            const pids = wZones.map(z => z.plan_id);
                            allowedPlans = currentQpPlans.filter(p => pids.includes(p.id));
                        }
                    }
                    
                    const sel = document.getElementById('qpPlanSelect');
                    sel.innerHTML = '';
                    allowedPlans.forEach(p => {
                        const opt = document.createElement('option');
                        opt.value = p.id;
                        opt.textContent = p.name;
                        if (p.id == activeQpPlanId) opt.selected = true;
                        sel.appendChild(opt);
                    });
                    
                    if (!activeQpPlanId || !allowedPlans.find(p => p.id == activeQpPlanId)) {
                        activeQpPlanId = allowedPlans[0].id;
                    }
                    loadQpSelectedPlan();
                });
        };

        window.closeQpPlanPicker = function() {
            document.getElementById('qpPlanPickerModal').style.display = 'none';
        };

        window.loadQpSelectedPlan = function() {
            const sel = document.getElementById('qpPlanSelect');
            activeQpPlanId = sel.value;
            const plan = currentQpPlans.find(p => p.id == activeQpPlanId);
            if (!plan) return;
            
            const img = document.getElementById('qpPlanImage');
            const cont = document.getElementById('qpPlanContainer');
            const wrap = document.getElementById('qpPlanAreaWrap');
            
            img.onload = function() {
                cont.style.display = 'inline-block';
                cont.style.width = '100%';
                img.style.width = '100%';
                img.style.height = 'auto';
                
                cont.querySelectorAll('.picker-multi-pin').forEach(e => e.remove());
                cont.querySelectorAll('.picker-zone').forEach(e => e.remove());
                
                const svg = document.getElementById('qpDrawOverlay');
                svg.innerHTML = '';
                
                const pZones = currentQpZones.filter(z => z.plan_id == activeQpPlanId);
                pZones.forEach(z => {
                    const div = document.createElement('div');
                    div.className = 'picker-zone';
                    div.id = 'qp-picker-zone-' + z.wohnung_id;
                    div.style.position = 'absolute';
                    div.style.left = z.x + '%';
                    div.style.top = z.y + '%';
                    div.style.width = z.w + '%';
                    div.style.height = z.h + '%';
                    div.style.border = '2px dashed rgba(59, 130, 246, 0.4)';
                    div.style.background = 'rgba(59, 130, 246, 0.05)';
                    div.style.pointerEvents = 'none';
                    cont.appendChild(div);
                });
                
                currentQpPins.forEach((pin, idx) => {
                    if (String(pin.plan_id) === String(activeQpPlanId)) {
                        if (pin.type === 'line') renderQpLine(pin, idx, svg);
                        else if (pin.type === 'rect') renderQpRect(pin, idx, svg);
                        else renderQpPin(pin.x, pin.y, idx);
                    }
                });
                document.getElementById('qpApplyPinBtn').disabled = false;

                // AUTO-ZOOM logic
                const selWid = document.getElementById('qp_wohnung_id').value;
                if (selWid) {
                    const tz = pZones.find(z => z.wohnung_id == selWid);
                    if (tz) {
                        const maxScaleW = 100 / tz.w * 0.9;
                        const maxScaleH = 100 / tz.h * 0.9;
                        const scale = Math.max(1, Math.min(maxScaleW, maxScaleH, 5)); // Limit zoom to 5x
                        
                        cont.style.width = (scale * 100) + '%';
                        
                        setTimeout(() => {
                            const cx = (tz.x + tz.w / 2) / 100 * cont.offsetWidth;
                            const cy = (tz.y + tz.h / 2) / 100 * cont.offsetHeight;
                            wrap.scrollLeft = cx - (wrap.clientWidth / 2);
                            wrap.scrollTop = cy - (wrap.clientHeight / 2);
                        }, 50);
                        
                        highlightQpZone(tz.wohnung_id);
                        
                        cont.ondblclick = function() {
                            cont.style.width = '100%';
                            wrap.scrollLeft = 0;
                            wrap.scrollTop = 0;
                        };
                    } else {
                        cont.style.width = '100%';
                    }
                } else {
                    cont.style.width = '100%';
                }
            };
            img.src = plan.url;
        };

        function highlightQpZone(wid) {
            document.querySelectorAll('.picker-zone').forEach(z => {
                z.style.borderColor = 'rgba(59, 130, 246, 0.4)';
                z.style.background = 'rgba(59, 130, 246, 0.05)';
                z.style.zIndex = 10;
            });
            const target = document.getElementById('qp-picker-zone-' + wid);
            if (target) {
                target.style.borderColor = '#ef4444';
                target.style.background = 'rgba(239, 68, 68, 0.1)';
                target.style.zIndex = 20;
            }
        }

        function renderQpPin(x, y, index) {
            const cont = document.getElementById('qpPlanContainer');
            const div = document.createElement('div');
            div.className = 'picker-multi-pin';
            div.style.position = 'absolute';
            div.style.left = x + '%';
            div.style.top = y + '%';
            div.style.transform = 'translate(-50%, -100%)';
            div.style.cursor = 'pointer';
            div.innerHTML = `<svg viewBox="0 0 24 24" width="28" height="28" style="filter:drop-shadow(0 4px 4px rgba(0,0,0,0.4));">
                <path fill="#ef4444" d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" stroke="#ffffff" stroke-width="1.5"/>
                <circle cx="12" cy="9" r="3.5" fill="#ffffff"/>
            </svg>`;
            div.onclick = (e) => { e.stopPropagation(); currentQpPins.splice(index, 1); loadQpSelectedPlan(); };
            cont.appendChild(div);
        }

        function renderQpLine(line, index, svg) {
            const pts = line.points.map(p => `${p.x},${p.y}`).join(' ');
            const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
            poly.setAttribute('points', pts);
            poly.setAttribute('fill', 'none');
            poly.setAttribute('stroke', '#ef4444');
            poly.setAttribute('stroke-width', '4');
            poly.setAttribute('stroke-linecap', 'round');
            poly.setAttribute('stroke-linejoin', 'round');
            poly.setAttribute('vector-effect', 'non-scaling-stroke');
            poly.style.cursor = 'pointer';
            poly.onclick = (e) => { e.stopPropagation(); currentQpPins.splice(index, 1); loadQpSelectedPlan(); };
            svg.appendChild(poly);
        }

        function renderQpRect(rectObj, index, svg) {
            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('x', rectObj.x);
            rect.setAttribute('y', rectObj.y);
            rect.setAttribute('width', rectObj.w);
            rect.setAttribute('height', rectObj.h);
            rect.setAttribute('fill', 'rgba(239, 68, 68, 0.3)');
            rect.setAttribute('stroke', '#ef4444');
            rect.setAttribute('stroke-width', '0.5');
            rect.setAttribute('vector-effect', 'non-scaling-stroke');
            rect.style.cursor = 'pointer';
            rect.onclick = (e) => { e.stopPropagation(); currentQpPins.splice(index, 1); loadQpSelectedPlan(); };
            svg.appendChild(rect);
        }

        const qpCont = document.getElementById('qpPlanContainer');
        qpCont.onmousedown = function(e) {
            if (e.target.closest('.picker-multi-pin') || e.target.tagName === 'polyline' || e.target.tagName === 'rect') return;
            const rect = this.getBoundingClientRect();
            const x = ((e.clientX - rect.left) / rect.width) * 100;
            const y = ((e.clientY - rect.top) / rect.height) * 100;
            if (currentQpDrawTool === 'pin') return;
            isQpDrawing = true;
            if (currentQpDrawTool === 'line') currentQpDrawObj = { type: 'line', plan_id: activeQpPlanId, points: [{x, y}] };
            else if (currentQpDrawTool === 'rect') currentQpDrawObj = { type: 'rect', plan_id: activeQpPlanId, x, y, w: 0, h: 0, startX: x, startY: y };
            currentQpPins.push(currentQpDrawObj);
            loadQpSelectedPlan();
        };

        qpCont.onmousemove = function(e) {
            if (!isQpDrawing || !currentQpDrawObj) return;
            const rect = this.getBoundingClientRect();
            const x = ((e.clientX - rect.left) / rect.width) * 100;
            const y = ((e.clientY - rect.top) / rect.height) * 100;
            if (currentQpDrawTool === 'line') currentQpDrawObj.points.push({x, y});
            else if (currentQpDrawTool === 'rect') {
                currentQpDrawObj.x = Math.min(x, currentQpDrawObj.startX);
                currentQpDrawObj.y = Math.min(y, currentQpDrawObj.startY);
                currentQpDrawObj.w = Math.abs(x - currentQpDrawObj.startX);
                currentQpDrawObj.h = Math.abs(y - currentQpDrawObj.startY);
            }
            const svg = document.getElementById('qpDrawOverlay');
            svg.innerHTML = '';
            currentQpPins.forEach((pin, idx) => {
                if (String(pin.plan_id) === String(activeQpPlanId)) {
                    if (pin.type === 'line') renderQpLine(pin, idx, svg);
                    else if (pin.type === 'rect') renderQpRect(pin, idx, svg);
                }
            });
        };

        window.onmouseup = function() {
            if (isQpDrawing) { isQpDrawing = false; currentQpDrawObj = null; loadQpSelectedPlan(); }
        };

        qpCont.onclick = function(e) {
            if (e.target.closest('.picker-multi-pin') || e.target.tagName === 'polyline' || e.target.tagName === 'rect') return;
            if (currentQpDrawTool !== 'pin') return;
            const rect = this.getBoundingClientRect();
            const x = ((e.clientX - rect.left) / rect.width) * 100;
            const y = ((e.clientY - rect.top) / rect.height) * 100;
            currentQpPins.push({ plan_id: activeQpPlanId, x: parseFloat(x).toFixed(4), y: parseFloat(y).toFixed(4), type: 'pin' });
            loadQpSelectedPlan();
        };

        window.clearQpPlanPin = function() {
            currentQpPins = [];
            loadQpSelectedPlan();
        };

        window.applyQpPlanPin = function() {
            document.getElementById('qp_plan_pins').value = JSON.stringify(currentQpPins);
            if (currentQpPins.length > 0) {
                document.getElementById('qp_plan_id').value = currentQpPins[0].plan_id;
                document.getElementById('qp_pin_x').value = currentQpPins[0].x;
                document.getElementById('qp_pin_y').value = currentQpPins[0].y;
                
                // Snapshot
                const img = document.getElementById('qpPlanImage');
                if (img && img.naturalWidth) {
                    const natW = img.naturalWidth;
                    const natH = img.naturalHeight;
                    let zx=0, zy=0, zw=100, zh=100;
                    const wDropdown = document.getElementById('qp_wohnung_id').value;
                    if (wDropdown) {
                        const tz = currentQpZones.find(z => z.wohnung_id == wDropdown && z.plan_id == activeQpPlanId);
                        if (tz) { zx=Math.max(0, tz.x-5); zy=Math.max(0, tz.y-5); zw=Math.min(100-zx, tz.w+10); zh=Math.min(100-zy, tz.h+10); }
                    }
                    const cropX = (zx/100)*natW, cropY = (zy/100)*natH, cropW = (zw/100)*natW, cropH = (zh/100)*natH;
                    const canvas = document.createElement('canvas');
                    const maxDim = 1200;
                    let scale = 1;
                    if (cropW > maxDim || cropH > maxDim) scale = maxDim / Math.max(cropW, cropH);
                    canvas.width = cropW * scale; canvas.height = cropH * scale;
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#fff'; ctx.fillRect(0,0,canvas.width,canvas.height);
                    ctx.drawImage(img, cropX, cropY, cropW, cropH, 0, 0, canvas.width, canvas.height);
                    
                    currentQpPins.forEach(pin => {
                        if (String(pin.plan_id) === String(activeQpPlanId)) {
                            if (pin.type === 'line' && pin.points && pin.points.length > 1) {
                                ctx.strokeStyle = '#ef4444'; ctx.lineWidth = Math.max(3, 5*scale); ctx.lineCap = 'round'; ctx.lineJoin = 'round';
                                ctx.beginPath();
                                pin.points.forEach((pt, i) => {
                                    const rx = ((pt.x/100)*natW - cropX)*scale, ry = ((pt.y/100)*natH - cropY)*scale;
                                    if (i===0) ctx.moveTo(rx, ry); else ctx.lineTo(rx, ry);
                                });
                                ctx.stroke();
                            } else if (pin.type === 'rect') {
                                const rx = ((pin.x/100)*natW - cropX)*scale, ry = ((pin.y/100)*natH - cropY)*scale;
                                const rw = (pin.w/100)*natW*scale, rh = (pin.h/100)*natH*scale;
                                ctx.fillStyle = 'rgba(239,68,68,0.3)'; ctx.fillRect(rx, ry, rw, rh);
                                ctx.strokeStyle = '#ef4444'; ctx.lineWidth = Math.max(1, 3*scale); ctx.strokeRect(rx, ry, rw, rh);
                            } else {
                                const rx = ((pin.x/100)*natW - cropX)*scale, ry = ((pin.y/100)*natH - cropY)*scale;
                                ctx.fillStyle = '#ef4444'; ctx.beginPath(); ctx.arc(rx, ry-24, 12, 0, Math.PI*2); ctx.fill();
                                ctx.beginPath(); ctx.moveTo(rx-11, ry-20); ctx.lineTo(rx+11, ry-20); ctx.lineTo(rx, ry); ctx.fill();
                                ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.arc(rx, ry-24, 4, 0, Math.PI*2); ctx.fill();
                            }
                        }
                    });
                    document.getElementById('qp_plan_snapshot_data').value = canvas.toDataURL('image/jpeg', 0.85);
                }
            }
            closeQpPlanPicker();
        };

    })();
</script>