<?php
// includes/footer.php
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/csrf.php';

$__csrf_token = csrf_token();
$__prefix = site_prefix();
$__user_id = (int) ($_SESSION['user_id'] ?? 0);
?>
</main>

</div>

<!-- gimi AI Global Assistant Sidebar (Co-Pilot) -->
<div id="ai-copilot-container">
    <div class="ai-copilot-header">
        <div style="display:flex; align-items:center; gap:8px;">
            <span class="status-dot"></span>
            <strong style="font-size:15px; color:#fff; letter-spacing:0.5px;">gimi</strong>
            <span style="opacity:0.6;font-weight:400; font-size:13px; color:#94a3b8;">| Co-Pilot</span>
            <span class="gimi-model-badge">⚡ Gemini Intelligence</span>
        </div>
        <div style="display:flex; align-items:center; gap:6px;">
            <button class="gimi-head-btn" onclick="clearGimiChat()" title="Chat-Verlauf leeren">🗑️</button>
            <button class="gimi-head-btn" onclick="toggleGimi()" title="Schliessen">✕</button>
        </div>
    </div>

    <div class="ai-messages" id="gimi-messages">
        <div class="ai-context-chip" id="gimi-context-chip">📍 <?= h($PAGE_TITLE ?? 'Seiten-Kontext geladen') ?></div>
        <div id="gimi-quick-chips" class="gimi-quick-chips"></div>
        <div class="ai-msg bot" id="gimi-initial-msg">
            Grüezi! Ich bin <strong>gimi</strong>, dein persönlicher Schweizer PropTech KI-Assistent. Ich habe den Kontext dieser Seite analysiert. Wie kann ich dir helfen?
        </div>
    </div>

    <div class="ai-copilot-input">
        <div id="gimi-typing" class="ai-typing">
            <span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span> gimi denkt nach...
        </div>
        <div class="ai-input-wrapper">
            <button id="gimi-mic" class="ai-mic-btn" onclick="startGimiVoice()" title="Spracheingabe / Diktat">🎤</button>
            <input type="text" id="gimi-input" placeholder="Frag gimi oder diktiere eine Pendenz..." autocomplete="off">
            <button id="gimi-send" class="ai-send-btn" title="Senden">🚀</button>
        </div>
    </div>
</div>

<style>
    .ai-mic-btn {
        background: none;
        border: 0;
        color: #9ca3af;
        font-size: 18px;
        cursor: pointer;
        padding: 0 5px;
        transition: 0.3s;
    }

    .ai-mic-btn.active {
        color: #ef4444;
        animation: pulse-red 1.5s infinite;
    }

    @keyframes pulse-red {
        0% {
            opacity: 1;
        }

        50% {
            opacity: 0.5;
        }

        100% {
            opacity: 1;
        }
    }
</style>

<!-- Platzhalter-CSS + Widget-Styles -->
<link rel="stylesheet" href="<?= h(asset_url('chat_widget.css')) ?>">

<style>
    :root {
        --bg: #0b1220;
        --fg: #e5e7eb;
        --muted: #9ca3af;
        --line: #1f2937;
        --accent: #3b82f6;
        --chip: #0f1629;
        --chip-act: #122040;
        --chip-dot: #ef4444;
        --mine: #1f2a44;
        --other: #111827;
        --sys: #0b3d3a;
    }

    #chat-widget {
        position: fixed;
        bottom: 20px;
        right: 20px;
        font-family: system-ui, sans-serif;
        z-index: 9999;
        color: var(--fg);
    }

    #chat-toggle {
        background: #0ea5e9;
        color: #fff;
        padding: 8px 12px;
        border-radius: 50px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 8px;
        box-shadow: 0 4px 10px rgba(0, 0, 0, .2);
        user-select: none;
        outline: none;
    }

    #chat-arrow {
        font-size: 12px;
    }

    .badge-dot {
        width: 8px;
        height: 8px;
        border-radius: 999px;
        background: var(--chip-dot);
        display: inline-block;
    }

    .badge-dot.pulse {
        animation: pulse 1.1s ease-out 3;
    }

    @keyframes pulse {
        0% {
            transform: scale(.7);
            opacity: .7
        }

        70% {
            transform: scale(1.4);
            opacity: .2
        }

        100% {
            transform: scale(1);
            opacity: 0
        }
    }

    #chat-window {
        display: none;
        flex-direction: column;
        width: 360px;
        max-height: 580px;
        background: var(--bg);
        border: 2px solid var(--line);
        border-radius: 12px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, .35);
        margin-top: 8px;
        overflow: hidden;
    }

    #chat-header {
        background: #0ea5e9;
        color: #fff;
        padding: 10px;
        border-radius: 10px 10px 0 0;
        font-weight: 600;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .btn-unread {
        background: transparent;
        color: #fff;
        border: 1px solid rgba(255, 255, 255, .6);
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        cursor: pointer;
        opacity: .9
    }

    .btn-unread[disabled] {
        opacity: .5;
        cursor: not-allowed
    }

    .btn-unread.active {
        background: #fff;
        color: #0ea5e9;
        border-color: #fff;
        font-weight: 700
    }

    #chat-controls {
        padding: 8px;
        border-bottom: 1px solid var(--line)
    }

    #chat-controls .row {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        align-items: center
    }

    #chat-controls label {
        font-size: 12px;
        display: flex;
        gap: 6px;
        align-items: center
    }

    #chat-controls select {
        padding: 4px 6px;
        border: 1px solid #374151;
        border-radius: 8px;
        background: var(--bg);
        color: var(--fg)
    }

    .rooms-bar {
        margin-top: 8px;
        display: flex;
        gap: 6px;
        overflow: auto;
        scrollbar-width: thin;
        padding-bottom: 2px
    }

    .chip {
        background: var(--chip);
        border: 1px solid var(--line);
        color: var(--fg);
        padding: 6px 10px;
        font-size: 12px;
        border-radius: 999px;
        white-space: nowrap;
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }

    .chip .dot {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        background: var(--chip-dot);
        display: none;
        flex: 0 0 auto;
        box-shadow: 0 0 8px rgba(239, 68, 68, .7);
    }

    .chip.unread .dot {
        display: inline-block;
    }

    .chip .jump {
        font-size: 11px;
        opacity: .8;
        border: 1px dashed rgba(239, 68, 68, .6);
        padding: 1px 6px;
        border-radius: 999px;
        display: none;
    }

    .chip.unread .jump {
        display: inline-block;
    }

    .chip.active {
        background: var(--chip-act);
        border-color: #1d4ed8;
    }

    #chat-messages {
        flex: 1;
        padding: 10px;
        overflow: auto;
        font-size: 14px;
        background: #0a0f1c;
        outline: none
    }

    .msg {
        margin: 6px 0;
        padding: 8px 10px;
        border-radius: 10px;
        max-width: 80%
    }

    .msg.mine {
        background: var(--mine);
        color: var(--fg);
        margin-left: auto
    }

    .msg.other {
        background: var(--other);
        color: var(--fg);
        margin-right: auto
    }

    .msg.system {
        background: var(--sys);
        color: #fff;
        max-width: 100%
    }

    .msg .meta {
        font-size: 12px;
        color: var(--muted);
        margin-bottom: 2px
    }

    .msg.new {
        box-shadow: 0 0 0 2px rgba(59, 130, 246, .35);
        animation: flash .9s ease-out 1;
    }

    @keyframes flash {
        from {
            transform: translateY(2px);
            opacity: .7
        }

        to {
            transform: none;
            opacity: 1
        }
    }

    .divider {
        text-align: center;
        color: #cbd5e1;
        font-size: 12px;
        margin: 8px 0;
        position: relative
    }

    .divider::before,
    .divider::after {
        content: "";
        position: absolute;
        top: 50%;
        width: 38%;
        height: 1px;
        background: var(--line)
    }

    .divider::before {
        left: 0
    }

    .divider::after {
        right: 0
    }

    #chat-input {
        display: flex;
        border-top: 1px solid var(--line);
        background: #0a0f1c
    }

    #chat-input input {
        flex: 1;
        border: 0;
        padding: 12px;
        font-size: 14px;
        border-radius: 0 0 0 12px;
        background: var(--bg);
        color: var(--fg)
    }

    #chat-input button {
        border: 0;
        background: var(--accent);
        color: #fff;
        padding: 0 16px;
        cursor: pointer;
        border-radius: 0 0 12px 0;
        font-weight: 600
    }
</style>

<!-- Widget-Logik -->
<script src="<?= h(asset_url('js/chat_widget.js')) ?>" defer></script>

<!-- gimi Global Logic -->
<script>
    let gimiChatId = 0;
    let gimiLoaded = false;

    function getCleanUrl() {
        const u = new URL(window.location.href);
        const params = new URLSearchParams();
        const keep = ['projekt_id', 'id', 'wohnung_id', 'objekt_id', 'path'];

        u.searchParams.forEach((val, key) => {
            if (keep.includes(key) && val !== '' && val !== '0') {
                params.append(key, val);
            }
        });

        params.sort();
        return u.origin + u.pathname + (params.toString() ? '?' + params.toString() : '');
    }

    function getGimiStorageKey() {
        return 'gimi_hist_' + encodeURIComponent(getCleanUrl());
    }

    function formatGimiMarkdown(text) {
        if (!text) return '';
        let str = String(text);

        // Vorformatierte HTML-Blöcke schützen (z.B. Pendenz-Erfolgs-Boxen)
        const preserved = [];
        str = str.replace(/<div class=['"]ai-action-success[\s\S]*?<\/div>/gi, (match) => {
            preserved.push(match);
            return `%%%GIMI_PRESERVED_${preserved.length - 1}%%%`;
        });

        // HTML-Sonderzeichen maskieren (XSS-Schutz)
        str = str.replace(/&/g, "&amp;")
                 .replace(/</g, "&lt;")
                 .replace(/>/g, "&gt;");

        // Code-Blöcke: ```code```
        str = str.replace(/```([a-z0-9_-]*)\n([\s\S]*?)```/gi, (match, lang, code) => {
            return `<pre class="gimi-code-block"><code>${code.trim()}</code></pre>`;
        });

        // Inline Code: `code`
        str = str.replace(/`([^`]+)`/g, '<code class="gimi-inline-code">$1</code>');

        // Überschriften: ###, ##, #
        str = str.replace(/^### (.*$)/gim, '<div class="gimi-h4">$1</div>');
        str = str.replace(/^## (.*$)/gim, '<div class="gimi-h3">$1</div>');
        str = str.replace(/^# (.*$)/gim, '<div class="gimi-h2">$1</div>');

        // Fett: **text**
        str = str.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');

        // Kursiv: *text*
        str = str.replace(/(^|[^\*])\*([^\*]+)\*([^\*]|$)/g, '$1<em>$2</em>$3');

        // Horizontale Trennlinie: ---
        str = str.replace(/^---$/gim, '<hr class="gimi-hr">');

        // Ungeordnete Listen: * oder -
        str = str.replace(/^\s*[\*\-]\s+(.*$)/gim, '<li class="gimi-li">$1</li>');
        str = str.replace(/((?:<li class="gimi-li">.*<\/li>\s*)+)/gims, '<ul class="gimi-ul">$1</ul>');

        // Geordnete Listen: 1. text
        str = str.replace(/^\s*(\d+)\.\s+(.*$)/gim, '<li value="$1" class="gimi-li-num">$2</li>');
        str = str.replace(/((?:<li value="\d+" class="gimi-li-num">.*<\/li>\s*)+)/gims, '<ol class="gimi-ol">$1</ol>');

        // Markdown-Links: [Text](url)
        str = str.replace(/\[([^\]]+)\]\(([^)]+)\)/g, (match, label, href) => {
            let cleanHref = href.trim();
            // Relative Pfade innerhalb pendenz.com anpassen
            if (!cleanHref.startsWith('http://') && !cleanHref.startsWith('https://') && !cleanHref.startsWith('/')) {
                cleanHref = '<?= site_prefix() ?>' + cleanHref;
            }
            return `<a href="${cleanHref}" class="gimi-link" target="_self">${label} ↗</a>`;
        });

        // Zeilenumbrüche
        str = str.replace(/\n/g, '<br>');
        // Unnötige <br> um Listen und Blöcke entfernen
        str = str.replace(/<ul class="gimi-ul"><br>/g, '<ul class="gimi-ul">')
                 .replace(/<\/li><br>/g, '</li>')
                 .replace(/<\/ul><br>/g, '</ul>')
                 .replace(/<ol class="gimi-ol"><br>/g, '<ol class="gimi-ol">')
                 .replace(/<\/ol><br>/g, '</ol>');

        // Geschützte Blöcke wieder einsetzen
        preserved.forEach((pBlock, idx) => {
            str = str.replace(`%%%GIMI_PRESERVED_${idx}%%%`, pBlock);
        });

        return str;
    }

    function renderMessage(role, text) {
        const log = document.getElementById('gimi-messages');
        if (!log) return;
        const div = document.createElement('div');
        div.className = 'ai-msg ' + (role === 'user' ? 'user' : 'bot');
        div.innerHTML = formatGimiMarkdown(text || '');
        log.appendChild(div);
        log.scrollTop = log.scrollHeight;
    }

    function getRenderedGimiMessages() {
        return Array.from(document.querySelectorAll('#gimi-messages .ai-msg')).map((node) => ({
            role: node.classList.contains('user') ? 'user' : 'assistant',
            content: node.getAttribute('data-raw') || node.innerHTML
        }));
    }

    function saveGimiState() {
        try {
            sessionStorage.setItem(getGimiStorageKey(), JSON.stringify({
                messages: getRenderedGimiMessages(),
                chat_id: gimiChatId
            }));
        } catch (err) {
            console.warn('gimi cache konnte nicht gespeichert werden', err);
        }
    }

    function clearGimiChat() {
        try {
            sessionStorage.removeItem(getGimiStorageKey());
        } catch (e) {}
        gimiChatId = 0;
        const log = document.getElementById('gimi-messages');
        if (log) {
            log.innerHTML = `
                <div class="ai-context-chip" id="gimi-context-chip">📍 ${document.title}</div>
                <div id="gimi-quick-chips" class="gimi-quick-chips"></div>
                <div class="ai-msg bot">
                    Chat zurückgesetzt. Ich bin bereit für deine Fragen zur Liegenschaftsverwaltung oder neue Aufgaben!
                </div>
            `;
            renderSuggestionChips();
        }
    }

    function renderSuggestionChips() {
        const chipsContainer = document.getElementById('gimi-quick-chips');
        if (!chipsContainer) return;
        chipsContainer.innerHTML = '';

        const path = window.location.pathname;
        const urlParams = new URLSearchParams(window.location.search);
        const hasProject = urlParams.has('projekt_id');
        const hasFolder = urlParams.has('path');

        let suggestions = [];

        if (path.includes('files.php')) {
            if (hasFolder) {
                suggestions.push({ label: '📂 Dateien in diesem Ordner?', prompt: 'Welche Dateien oder Rechnungen befinden sich aktuell in diesem geöffneten Ordner?' });
            } else {
                suggestions.push({ label: '📁 Ordnerstruktur anzeigen', prompt: 'Erkläre mir die Google Drive Ordnerstruktur für dieses Projekt.' });
            }
            suggestions.push({ label: '👥 Mieter dieser Liegenschaft', prompt: 'Wer sind die aktuellen Mieter dieser Liegenschaft und wie hoch sind die Mieten?' });
            suggestions.push({ label: '➕ Pendenz erfassen', prompt: 'Erfasse eine Pendenz: Dokumente in diesem Ordner prüfen bis nächsten Freitag' });
            suggestions.push({ label: '💰 Soll-Mietertrag', prompt: 'Wie hoch ist der monatliche und jährliche Mietertrag dieser Liegenschaft?' });
        } else if (path.includes('mieterspiegel.php')) {
            suggestions.push({ label: '📊 Mieter & Mietzinse', prompt: 'Fasse mir den Mieterspiegel dieser Liegenschaft zusammen.' });
            suggestions.push({ label: '⚠️ Leerstände prüfen', prompt: 'Gibt es in dieser Liegenschaft aktuell freie oder leerstehende Wohnungen?' });
            suggestions.push({ label: '📝 Neuen Vertrag erstellen', prompt: 'Wie bereite ich am schnellsten einen neuen Mietvertrag vor?' });
        } else if (path.includes('pendenzen.php')) {
            suggestions.push({ label: '🚨 Dringende Aufgaben', prompt: 'Welche Pendenzen haben aktuell die höchste Priorität und müssen erledigt werden?' });
            suggestions.push({ label: '🎙️ Pendenz diktieren', prompt: 'Erfasse eine Pendenz: Heizung prüfen und Service aufbieten' });
            suggestions.push({ label: '📈 Pendenzen nach Liegenschaft', prompt: 'Gib mir eine Übersicht über offene Aufgaben sortiert nach Liegenschaften.' });
        } else if (path.includes('liegenschaftsabrechnung')) {
            suggestions.push({ label: '📊 Abrechnungs-Status', prompt: 'Wie ist der aktuelle Stand der Liegenschaftsabrechnung?' });
            suggestions.push({ label: '🧾 Steuerabzug Pauschale vs Effektiv', prompt: 'Erkläre mir die optimale Schweizer Steuerabzug-Strategie (10%/20% Pauschale vs. effektive Kosten).' });
        } else {
            suggestions.push({ label: '📈 Portfolio-Status', prompt: 'Gib mir einen kompakten Überblick über alle meine Liegenschaften und Einheiten.' });
            suggestions.push({ label: '💰 Monatlicher Mietertrag', prompt: 'Wie hoch ist der gesamte monatliche Soll-Mietertrag aller Liegenschaften?' });
            suggestions.push({ label: '🚨 Offene Pendenzen', prompt: 'Welche Aufgaben sind aktuell im gesamten Portfolio offen?' });
            suggestions.push({ label: '🎙️ Pendenz diktieren', prompt: 'Erfasse eine neue Pendenz' });
        }

        suggestions.forEach(s => {
            const btn = document.createElement('button');
            btn.className = 'gimi-chip';
            btn.innerText = s.label;
            btn.onclick = (e) => {
                e.preventDefault();
                handleGimiInput(s.prompt);
            };
            chipsContainer.appendChild(btn);
        });
    }

    async function toggleGimi(forceOpen = null) {
        const panel = document.getElementById('ai-copilot-container');
        if (!panel) return;

        if (forceOpen === true) panel.classList.add('active');
        else if (forceOpen === false) panel.classList.remove('active');
        else panel.classList.toggle('active');

        const isActive = panel.classList.contains('active');
        localStorage.setItem('gimi-sidebar-active', isActive ? '1' : '0');

        // Blue legacy chat-widget ausblenden/einblenden um visuelle Kollision zu verhindern
        const chatWidget = document.getElementById('chat-widget');
        if (chatWidget) {
            chatWidget.style.display = isActive ? 'none' : '';
        }

        const navBtn = document.getElementById('ai-toggle-btn');
        if (navBtn) {
            if (isActive) navBtn.classList.add('active');
            else navBtn.classList.remove('active');
        }

        if (isActive) {
            renderSuggestionChips();
            const input = document.getElementById('gimi-input');
            if (input) input.focus();
            if (!gimiLoaded) loadGimiHistory();
        }
    }

    async function loadGimiHistory() {
        if (gimiLoaded) return;

        const cached = sessionStorage.getItem(getGimiStorageKey());
        if (cached) {
            try {
                const data = JSON.parse(cached);
                const log = document.getElementById('gimi-messages');
                if (log && Array.isArray(data.messages) && data.messages.length > 0) {
                    log.innerHTML = '';
                    // Header chip & quick chips wiederherstellen
                    const chipDiv = document.createElement('div');
                    chipDiv.className = 'ai-context-chip';
                    chipDiv.innerText = '📍 ' + document.title;
                    log.appendChild(chipDiv);

                    const qcDiv = document.createElement('div');
                    qcDiv.id = 'gimi-quick-chips';
                    qcDiv.className = 'gimi-quick-chips';
                    log.appendChild(qcDiv);
                    renderSuggestionChips();

                    data.messages.forEach(m => renderMessage(m.role, m.content));
                    gimiChatId = parseInt(data.chat_id || 0, 10) || 0;
                    gimiLoaded = true;
                    return;
                }
            } catch (err) {
                console.warn('gimi cache ist ungültig', err);
            }
        }

        try {
            const res = await fetch('<?= site_prefix() ?>api/ai_query.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    prompt: 'GIMI_LOAD_CONTEXT',
                    context: { url: getCleanUrl() }
                })
            });
            const data = await res.json();
            if (data.success) {
                const log = document.getElementById('gimi-messages');
                if (log) {
                    log.innerHTML = '';
                    const chipDiv = document.createElement('div');
                    chipDiv.className = 'ai-context-chip';
                    chipDiv.innerText = '📍 ' + document.title;
                    log.appendChild(chipDiv);

                    const qcDiv = document.createElement('div');
                    qcDiv.id = 'gimi-quick-chips';
                    qcDiv.className = 'gimi-quick-chips';
                    log.appendChild(qcDiv);
                    renderSuggestionChips();
                }
                gimiChatId = parseInt(data.chat_id || 0, 10) || 0;

                if (Array.isArray(data.messages) && data.messages.length > 0) {
                    data.messages.forEach(m => renderMessage(m.role, m.content));
                } else {
                    renderMessage('assistant', 'Grüezi! Ich bin **gimi**, dein intelligenter PropTech KI-Assistent. Ich habe den Kontext dieser Seite analysiert. Wie kann ich dir helfen?');
                }

                saveGimiState();
                gimiLoaded = true;
            }
        } catch (err) {
            console.error('History Error:', err);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        renderSuggestionChips();
        if (localStorage.getItem('gimi-sidebar-active') === '1') {
            toggleGimi(true);
        }
    });

    async function handleGimiInput(textOverride = null) {
        const input = document.getElementById('gimi-input');
        const log = document.getElementById('gimi-messages');
        const typing = document.getElementById('gimi-typing');
        const text = textOverride || (input && input.value ? input.value.trim() : '');
        if (!text || !log) return;
        if (!textOverride && input) input.value = '';

        renderMessage('user', text);
        saveGimiState();

        if (typing) {
            typing.style.display = 'flex';
            log.scrollTop = log.scrollHeight;
        }

        const urlParams = new URLSearchParams(window.location.search);
        const pid = urlParams.get('projekt_id') || '';
        const wid = urlParams.get('wohnung_id') || '';
        const pathParam = urlParams.get('path') || '';

        const mEl = document.querySelector('main') || document.body;
        let pageContent = (mEl && mEl.innerText) ? mEl.innerText.substring(0, 2200) : '';
        const tableContext = Array.from(document.querySelectorAll('table')).slice(0, 8).map((table, index) => ({
            index,
            headers: Array.from(table.querySelectorAll('thead th')).map(th => th.innerText.trim()),
            rows: Array.from(table.querySelectorAll('tbody tr')).slice(0, 12).map(tr => Array.from(tr.cells).map(td => td.innerText.trim()))
        }));
        const activeControls = Array.from(document.querySelectorAll('select,input')).filter(el => el.value).slice(0, 20).map(el => ({name: el.name || el.id, value: el.value}));

        try {
            const res = await fetch('<?= site_prefix() ?>api/ai_query.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    prompt: text,
                    chat_id: gimiChatId,
                    context: {
                        url: getCleanUrl(),
                        title: document.title,
                        projekt_id: pid,
                        wohnung_id: wid,
                        path: pathParam,
                        content: pageContent,
                        tables: tableContext,
                        active_controls: activeControls,
                        capabilities: ['navigate','filter_table','sort_table','prepare_create_pendenz']
                    }
                })
            });
            const data = await res.json();
            if (typing) typing.style.display = 'none';

            if (data.success) {
                gimiChatId = parseInt(data.chat_id || 0, 10) || 0;
                renderMessage('assistant', data.answer || '');
                saveGimiState();
            } else {
                renderMessage('bot', '⚠️ ' + (data.error || 'Es gab einen Fehler bei der KI-Anfrage.'));
            }
        } catch (err) {
            if (typing) typing.style.display = 'none';
            renderMessage('bot', '⚠️ Verbindungsfehler: Bitte Internetverbindung prüfen.');
            console.error(err);
        }
    }

    function startGimiVoice() {
        const mic = document.getElementById('gimi-mic');
        const SpeechRec = window.SpeechRecognition || window.webkitSpeechRecognition;
        if (!SpeechRec) {
            alert("Spracherkennung wird von diesem Browser nicht unterstützt. Bitte nutze die Mikrofon-Taste der Smartphone-Tastatur oder Google Chrome.");
            return;
        }

        try {
            const recognition = new SpeechRec();
            // de-DE ist auf allen Mobilgeräten (iOS, iPadOS, Android) und PCs universell unterstützt
            recognition.lang = 'de-DE';
            recognition.continuous = false;
            recognition.interimResults = false;

            recognition.onstart = () => {
                if (mic) mic.classList.add('active');
            };

            recognition.onresult = (event) => {
                if (event.results && event.results[0] && event.results[0][0]) {
                    const text = event.results[0][0].transcript;
                    if (text && text.trim() !== '') {
                        handleGimiInput(text.trim());
                    }
                }
            };

            recognition.onerror = (e) => {
                console.warn('Speech recognition error:', e);
                if (mic) mic.classList.remove('active');
                if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
                    renderMessage('bot', '⚠️ Mikrofonzugriff nicht gestattet. Bitte Berechtigung im Browser prüfen oder Frage direkt eintippen (auch die Smartphone-Tastatur hat eine Diktierfunktion).');
                } else if (e.error === 'no-speech') {
                    // Stille/kein Ton gehört
                } else {
                    renderMessage('bot', '⚠️ Spracherkennung unterbrochen. Du kannst Deine Frage auch direkt eintippen.');
                }
            };

            recognition.onend = () => {
                if (mic) mic.classList.remove('active');
            };

            recognition.start();
        } catch (e) {
            console.warn('Gimi voice error:', e);
            if (mic) mic.classList.remove('active');
        }
    }

    window.confirmGimiAction = async function(token, button) {
        if (!token || !button) return;
        button.disabled = true; button.textContent = '⏳ Speichere…';
        try {
            const res = await fetch('<?= site_prefix() ?>api/ai_confirm.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({token})});
            const data = await res.json();
            if (!data.ok) throw new Error(data.error || 'Aktion fehlgeschlagen');
            button.parentElement.innerHTML = '✅ Pendenz #' + data.id + ' gespeichert.';
        } catch (e) { button.disabled = false; button.textContent = '✅ Jetzt speichern'; alert('gimi konnte die Aktion nicht speichern: ' + e.message); }
    };
    const gsBtn = document.getElementById('gimi-send');
    if (gsBtn) gsBtn.addEventListener('click', () => handleGimiInput());
    const gI = document.getElementById('gimi-input');
    if (gI) gI.addEventListener('keypress', (e) => { if (e.key === 'Enter') handleGimiInput(); });
</script>

<script>
    (function () {
        function init() {
            const toggle = document.getElementById('chat-toggle');
            const arrow = document.getElementById('chat-arrow');
            const wnd = document.getElementById('chat-window');
            if (!toggle || !arrow || !wnd) return;

            let open = false;
            function setOpen(v) {
                open = !!v;
                wnd.style.display = open ? 'flex' : 'none';
                wnd.setAttribute('aria-hidden', open ? 'false' : 'true');
                arrow.textContent = open ? '▼' : '▲';
                if (open) { const msgs = document.getElementById('chat-messages'); msgs && msgs.focus(); }
            }
            toggle.addEventListener('click', () => setOpen(!open));
        }
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
    })();
</script>

<!-- Offline Sync & PWA -->
<script src="<?= h(asset_url('js/offline_sync.js?v=2.5')) ?>"></script>

<?php include_once __DIR__ . '/quick_capture.php'; ?>

<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('<?= $__prefix ?>sw.js').then(reg => {
                console.log('SW registered', reg);
            }).catch(err => {
                console.log('SW registration failed', err);
            });
        });
    }
</script>
</body>

</html>
