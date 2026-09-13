<?php
// pages/ai_assistant.php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_login();

$userId = current_user_id();
$title = "gimi-Zentrale | Triple-View";

// Aktionen verarbeiten (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_training') {
        $title_t = trim($_POST['title'] ?? '');
        $content_t = trim($_POST['content'] ?? '');
        $scope_t = trim($_POST['scope_url'] ?? '');
        if ($title_t !== '' && $content_t !== '') {
            $stmt = $mysqli->prepare("INSERT INTO ai_training (title, content, category, user_id, scope_url) VALUES (?, ?, 'user', ?, ?)");
            $stmt->bind_param("ssis", $title_t, $content_t, $userId, $scope_t);
            $stmt->execute();
            $stmt->close();
        }
    } elseif ($_POST['action'] === 'delete_training') {
        $tid = (int)($_POST['id'] ?? 0);
        if ($tid > 0) $mysqli->query("DELETE FROM ai_training WHERE id = $tid AND (user_id = $userId OR user_id IS NULL)");
    }
    header("Location: ai_assistant.php");
    exit;
}

// Aktionen verarbeiten (GET - für maximale Zuverlässigkeit beim Löschen)
if (isset($_GET['action']) && $_GET['action'] === 'delete_chat') {
    $cid = (int)($_GET['chat_id'] ?? 0);
    if ($cid > 0) {
        $mysqli->query("DELETE FROM ai_messages WHERE chat_id = $cid");
        $mysqli->query("DELETE FROM ai_chats WHERE id = $cid AND user_id = $userId");
    }
    header("Location: ai_assistant.php");
    exit;
}

// Daten laden
$training_items = [];
$res = $mysqli->query("SELECT * FROM ai_training WHERE user_id = $userId OR user_id IS NULL ORDER BY scope_url DESC, created_at DESC");
if ($res) while($row = $res->fetch_assoc()) $training_items[] = $row;

$chats = [];
$res = $mysqli->query("SELECT * FROM ai_chats WHERE user_id = $userId ORDER BY created_at DESC");
if ($res) while($row = $res->fetch_assoc()) $chats[] = $row;

// DEBUG - Nur vorübergehend
// echo "<!-- DEBUG: UserID=$userId, Chats=".count($chats).", Training=".count($training_items)." -->";

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/nav_dispatch.php';
?>

<div class="main-content ai-triple-view" id="ai-center-root">
    <div class="ai-triple-grid">
        
        <!-- LINKS: Chat History -->
        <div class="ai-history-section sidebar-card">
            <div class="sidebar-header">
                <h3>💬 Deine Themen</h3>
                <button class="btn-new-chat" onclick="newChat()">+ Neu</button>
            </div>
            <div class="chat-list" id="chat-list">
                <?php if(empty($chats)) echo '<div class="muted small p-15">Noch keine Chats vorhanden.</div>'; ?>
<?php foreach ($chats as $chat): ?>
                    <div class="chat-item" id="chat-item-<?= $chat['id'] ?>">
                        <div class="chat-click-area" onclick="loadChat(<?= $chat['id'] ?>, event)">
                            <span class="chat-title"><?= h($chat['title']) ?></span>
                            <span class="chat-date"><?= date('d.m.H:i', strtotime($chat['created_at'])) ?></span>
                        </div>
                        <a href="ai_assistant.php?action=delete_chat&chat_id=<?= $chat['id'] ?>" class="btn-chat-del" onclick="event.stopPropagation(); return confirm('Diesen Chat wirklich löschen?')" title="Chat löschen">✕</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- MITTE: Aktiver Chat -->
        <div class="ai-chat-section card">
            <div class="chat-header">
                <div class="ai-status">
                    <span class="pulse-dot"></span>
                    <strong>gimi</strong> <span class="muted" id="active-chat-title">| System Assistant</span>
                </div>
                <div class="chat-actions">
                    <button class="btn-icon" title="Kontext aktualisieren" onclick="location.reload()">🔄</button>
                    <button class="btn-icon" title="Vollbild" onclick="document.body.requestFullscreen()">📺</button>
                </div>
            </div>
            
            <div class="chat-messages" id="chat-messages">
                <div class="message ai">
                    Guten Tag, <?= h($_SESSION['name'] ?? 'Nedim') ?>. Ich bin bereit. Wähle ein Thema oder schreibe mir einfach.
                </div>
            </div>

            <div class="chat-input-area">
                <div class="input-wrapper">
                    <input type="text" placeholder="Frage gimi..." id="ai-input">
                    <button class="send-btn" onclick="handleInput()">🚀</button>
                </div>
            </div>
        </div>

        <!-- RECHTS: System-Gedächtnis -->
        <div class="ai-tools-section">
            <div class="card training-card">
                <h3>🧠 Dein Gedächtnis</h3>
                <p class="small muted">Speichere hier Regeln oder Wissen.</p>
                <div class="training-items">
                    <?php if(empty($training_items)) echo '<div class="muted small text-center p-10">Keine Einträge.</div>'; ?>
                    <?php foreach ($training_items as $item): ?>
                        <div class="t-item <?= (empty($item['scope_url']) || $item['scope_url'] === '*') ? 'global' : 'specific' ?>" onclick="openMemory(<?= h(json_encode($item['title'])) ?>, <?= h(json_encode($item['content'])) ?>)">
                            <div class="t-content">
                                <strong><?= h($item['title']) ?></strong>
                                <span class="scope-badge"><?= empty($item['scope_url']) ? 'Global' : h($item['scope_url']) ?></span>
                                <span class="t-text"><?= h(substr($item['content'], 0, 70)) ?>...</span>
                            </div>
                            <?php if ($item['user_id'] == $userId || is_superadmin()): ?>
                            <form method="POST" style="margin:0;" onclick="event.stopPropagation()">
                                <input type="hidden" name="action" value="delete_training">
                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                <button type="submit" class="btn-delete" title="Löschen">✕</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button class="btn-outline-full" onclick="showAddTraining()">+ Etwas merken</button>
                
                <div id="add-training-form" style="display:none; margin-top:10px; border-top: 1px solid rgba(255,255,255,0.1); padding-top: 10px;">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_training">
                        <input type="text" name="title" placeholder="Titel (z.B. Abnahme-Regel)" required class="form-input-small">
                        <textarea name="content" placeholder="Was soll gimi sich merken?" required class="form-input-small" style="height:60px;"></textarea>
                        
                        <label class="small muted" style="display:block; margin-bottom:2px;">Geltungsbereich (leer = überall):</label>
                        <input type="text" name="scope_url" placeholder="z.B. abnahmen.php" class="form-input-small">
                        <div style="display:flex; gap:5px;">
                            <button type="submit" class="send-btn" style="flex:1;">Merken</button>
                            <button type="button" class="btn-outline-full" style="flex:1;" onclick="hideAddTraining()">Abbruch</button>
                        </div>
                    </form>
                </div>
            </div>
            <div class="card analytics-card small-stats">
                <h3>📊 Info</h3>
                <div style="padding: 5px 0;">
                    <div style="font-size: 11px; opacity: 0.6; margin-bottom: 2px;">Aktueller Benutzer:</div>
                    <div style="font-weight: 700; font-size: 13px; color: #3b82f6; display: flex; align-items: center; gap: 8px;">
                        <span style="width: 8px; height: 8px; background: #22c55e; border-radius: 50%; display: inline-block; box-shadow: 0 0 8px #22c55e;"></span>
                        <?= h($_SESSION['name'] ?? 'Nedim Bajramoski') ?>
                    </div>
                </div>
                <div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid rgba(255,255,255,0.05); font-size: 10px; color: #64748b; letter-spacing: 0.5px;">
                    GIMI AI COMMAND CENTER <br> VERSION 2.8 PREMIUM
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* No-Scroll Logic */
body, html { overflow: hidden !important; height: 100vh; width: 100vw; margin: 0; padding: 0; }
#ai-center-root { background: #ffffff !important; height: 100vh; overflow: hidden; position: relative; }

/* Premium AI Command Center Design */
.main-content.ai-triple-view { 
    padding: 15px; 
    height: calc(100vh - 70px) !important; /* Exakter Viewport-Schnitt */
    background: #ffffff !important; 
    font-family: 'Outfit', 'Inter', sans-serif;
    overflow: hidden; 
    margin-top: 0;
}

.ai-triple-grid { 
    display: grid; 
    grid-template-columns: 280px minmax(0, 1fr) 300px; 
    gap: 15px; 
    height: 100%; 
    width: 100%; 
    max-height: 100%;
}

/* Glassmorphism Sidebars */
.sidebar-card { 
    background: linear-gradient(145deg, #111b2d, #0f172a) !important; 
    color: #f8fafc;
    border: 1px solid rgba(255,255,255,0.08); 
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    backdrop-filter: blur(10px);
    height: 100%; 
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.chat-list {
    flex: 1;
    overflow-y: auto;
    scrollbar-width: thin;
}

.training-card { 
    background: linear-gradient(145deg, #111b2d, #0f172a) !important; 
    color: #f8fafc;
    border: 1px solid rgba(255,255,255,0.08); 
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    backdrop-filter: blur(10px);
    flex: 1; 
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.ai-tools-section {
    display: flex;
    flex-direction: column;
    gap: 15px;
    height: 100%;
    overflow: hidden;
}

.analytics-card { 
    background: linear-gradient(145deg, #111b2d, #0f172a) !important; 
    color: #f8fafc;
    border: 1px solid rgba(255,255,255,0.08); 
    border-radius: 20px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.15);
    backdrop-filter: blur(10px);
    height: auto; 
    padding: 20px;
}

/* Mittlerer Chat (Dunkle Premium-Konsole) */
.ai-chat-section { 
    background: #0b1220 !important; 
    border: 1px solid rgba(255,255,255,0.05); 
    border-radius: 24px;
    display: flex;
    flex-direction: column;
    height: 100%;
    overflow: hidden;
    position: relative;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
}

.chat-header { 
    padding: 18px 25px; 
    border-bottom: 1px solid rgba(255,255,255,0.05); 
    display: flex; 
    justify-content: space-between; 
    align-items: center; 
    background: rgba(255,255,255,0.02); 
    backdrop-filter: blur(5px);
}
.chat-messages { 
    flex: 1; 
    padding: 30px; 
    overflow-y: auto; 
    background: transparent; 
    display: flex; 
    flex-direction: column; 
    gap: 20px; 
    scrollbar-width: thin; 
}

/* Nachrichten Sprechblasen */
.message { 
    max-width: 80%; 
    padding: 15px 22px; 
    border-radius: 20px; 
    font-size: 14.5px; 
    line-height: 1.6; 
    transition: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.message.ai, .message.assistant { 
    background: #ffffff; 
    color: #1e293b !important; 
    border: 1px solid rgba(0,0,0,0.05); 
    align-self: flex-start; 
    border-bottom-left-radius: 4px; 
    box-shadow: 0 8px 25px rgba(0,0,0,0.15);
}
.message.user { 
    background: linear-gradient(135deg, #3b82f6, #2563eb); 
    color: white !important; 
    align-self: flex-end; 
    border-bottom-right-radius: 4px;
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.3);
}

/* Input Area GANZ UNTEN */
.chat-input-area { 
    padding: 20px 25px 80px 25px; /* Höher gesetzt für bessere Ergonomie */
    background: #0b1220;
    border-top: 1px solid rgba(255,255,255,0.05);
}
.input-wrapper { 
    display: flex; 
    gap: 12px; 
    background: rgba(255,255,255,0.04); 
    border: 1px solid rgba(255,255,255,0.1); 
    padding: 12px 18px; 
    border-radius: 18px; 
    transition: 0.3s;
}
.input-wrapper:focus-within { 
    border-color: #3b82f6; 
    background: rgba(255,255,255,0.07); 
    box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15); 
}
.input-wrapper input { 
    flex: 1; 
    border: 0; 
    background: transparent; 
    outline: none; 
    font-size: 15px; 
    color: #fff; 
}

/* Buttons & Badges */
.btn-new-chat { background: #3b82f6; color: #fff; border: 0; padding: 8px 18px; border-radius: 12px; font-weight: 700; cursor: pointer; transition: 0.2s; }
.btn-new-chat:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(59, 130, 246, 0.4); }

.chat-item { 
    padding: 0; 
    border-bottom: 1px solid rgba(255,255,255,0.03); 
    transition: 0.2s; 
    display: flex; 
    align-items: center; 
    position: relative;
    overflow: hidden;
}
.chat-click-area { 
    flex: 1; 
    padding: 15px 18px; 
    cursor: pointer; 
    display: flex; 
    flex-direction: column; 
    overflow: hidden; 
}
.chat-item:hover .chat-click-area { background: rgba(255,255,255,0.03); }
.chat-item.active { background: rgba(59, 130, 246, 0.15); border-left: 4px solid #3b82f6; }

.chat-title { display: block; font-size: 13px; font-weight: 600; color: #f8fafc; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.chat-date { font-size: 10px; color: #94a3b8; margin-top: 2px; }

.btn-chat-del { 
    background: transparent; 
    border: 0; 
    color: #94a3b8; 
    cursor: pointer; 
    padding: 10px 15px; 
    transition: 0.2s; 
    font-size: 16px; 
    opacity: 0.5; /* IMMER SICHTBAR FÜR SICHERHEIT */
    z-index: 999; /* ABSOLUTER VORRANG */
    display: flex;
    align-items: center;
    justify-content: center;
}
.chat-item:hover .btn-chat-del { opacity: 1; color: #f87171; }
.btn-chat-del:hover { background: rgba(248, 113, 113, 0.1); border-radius: 50%; }

.scope-badge { background: rgba(59, 130, 246, 0.1); color: #60a5fa; border-radius: 8px; border: 1px solid rgba(59, 130, 246, 0.2); }
.send-btn { background: #3b82f6; color: #fff; border: 0; border-radius: 12px; width: 40px; height: 40px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: 0.2s; }
.send-btn:hover { transform: scale(1.1); }

/* Sidebar History (BLEIBT DUNKEL) */
.chat-item { border-bottom: 1px solid rgba(255,255,255,0.03); }
.chat-title { color: #f8fafc; }
.chat-item:hover { background: rgba(59, 130, 246, 0.1); }
.chat-item.active { background: rgba(59, 130, 246, 0.25); border-left: 4px solid #3b82f6; }

/* Gedächtnisbereich */
.training-items { flex: 1; overflow-y: auto; }
.t-item { padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; }
.t-item:hover { background: rgba(255,255,255,0.02); }
.scope-badge { background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); }
.t-item strong { color: #f1f5f9; font-size: 13px; }
.t-text { color: #94a3b8; }
.btn-delete:hover { color: #f87171; }
.form-input-small { background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.1); color: #fff; }
.form-input-small:focus { border-color: #3b82f6; background: rgba(255,255,255,0.08); }
.btn-outline-full { width: 100%; border: 1px dashed rgba(255,255,255,0.1); background: transparent; padding: 10px; border-radius: 12px; color: #94a3b8; font-size: 11px; cursor: pointer; margin-top: 10px; transition: 0.2s; }
.btn-outline-full:hover { background: rgba(255,255,255,0.05); color: #fff; }

.t-item { cursor: pointer; transition: 0.2s; position: relative; }
.t-item:hover { background: rgba(59, 130, 246, 0.1) !important; transform: translateX(5px); }

/* Premium Pop-Up für Gedächtnis-Details */
.g-popup-overlay { 
    position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
    background: rgba(0,0,0,0.85); backdrop-filter: blur(12px); 
    display: none; justify-content: center; align-items: center; z-index: 10000; 
}
.g-popup-content { 
    background: #0f172a; border: 1px solid rgba(255,255,255,0.1); 
    border-radius: 28px; width: 90%; max-width: 550px; 
    box-shadow: 0 40px 100px rgba(0,0,0,0.6); overflow: hidden;
    animation: popupFade 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
@keyframes popupFade { from { transform: scale(0.9); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.g-popup-header { padding: 25px; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; justify-content: space-between; align-items: center; }
.g-popup-header h2 { margin: 0; color: #fff; font-size: 20px; font-weight: 800; }
.g-popup-body { padding: 30px; color: #cbd5e1; line-height: 1.7; font-size: 15px; white-space: pre-wrap; max-height: 60vh; overflow-y: auto; }
.g-popup-footer { padding: 20px 25px; background: rgba(0,0,0,0.2); }
.btn-close { background: transparent; border: 0; color: #94a3b8; font-size: 20px; cursor: pointer; }
.p-15 { padding: 15px; }
.p-10 { padding: 10px; }
</style>

<!-- Popup HTML -->
<div id="memory-popup" class="g-popup-overlay" onclick="closeMemory()">
    <div class="g-popup-content" onclick="event.stopPropagation()">
        <div class="g-popup-header">
            <h2 id="pop-title">Regel</h2>
            <button class="btn-close" onclick="closeMemory()">✕</button>
        </div>
        <div id="pop-body" class="g-popup-body">Text</div>
        <div class="g-popup-footer">
            <button class="btn-new-chat" style="width:100%;" onclick="closeMemory()">Verstanden</button>
        </div>
    </div>
</div>

<script>
function openMemory(title, text) {
    document.getElementById('pop-title').textContent = title;
    document.getElementById('pop-body').textContent = text;
    document.getElementById('memory-popup').style.display = 'flex';
}

function closeMemory() {
    document.getElementById('memory-popup').style.display = 'none';
}

async function deleteChatAsync(id, event) {
    if (event) event.stopPropagation();
    if (!confirm('Möchtest du diesen Chat wirklich löschen?')) return;

    try {
        const formData = new FormData();
        formData.append('action', 'delete_chat');
        formData.append('chat_id', id);

        const res = await fetch('ai_assistant.php', {
            method: 'POST',
            body: formData
        });

        if (res.ok) {
            const item = document.getElementById('chat-item-' + id);
            if (item) {
                item.style.opacity = '0';
                item.style.transform = 'translateX(-20px)';
                setTimeout(() => item.remove(), 300);
            }
            if (currentChatId === id) newChat();
        }
    } catch (err) {
        console.error('Löschen fehlgeschlagen:', err);
        alert('Fehler beim Löschen des Chats.');
    }
}

let currentChatId = 0;
let aiInput = null;
let chatMessages = null;

function getAssistantStableContextUrl() {
    const u = new URL(window.location.href);
    const params = new URLSearchParams();
    const keep = ['projekt_id', 'id', 'wohnung_id', 'objekt_id'];

    u.searchParams.forEach((val, key) => {
        if (keep.includes(key) && val !== '' && val !== '0') {
            params.append(key, val);
        }
    });

    params.sort();
    return u.origin + u.pathname + (params.toString() ? '?' + params.toString() : '');
}

function setActiveChatTitle(text) {
    const target = document.getElementById('active-chat-title');
    if (!target) return;
    target.textContent = text ? '| ' + text : '| System Assistant';
}

function setActiveChatItem(id) {
    document.querySelectorAll('.chat-item').forEach(el => el.classList.remove('active'));
    if (id > 0) {
        document.getElementById('chat-item-' + id)?.classList.add('active');
    }
}

function appendMessage(role, text) {
    if (!chatMessages) return;
    const div = document.createElement('div');
    div.className = 'message ' + (role === 'user' ? 'user' : 'ai');
    div.innerHTML = String(text || '').replace(/\n/g, '<br>');
    chatMessages.appendChild(div);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function updateHistoryUrl() {
    const url = currentChatId > 0 ? 'ai_assistant.php?chat_id=' + currentChatId : 'ai_assistant.php';
    window.history.replaceState({ chat_id: currentChatId }, '', url);
}

function buildChatListItem(id, titleText) {
    const wrapper = document.createElement('div');
    wrapper.className = 'chat-item active';
    wrapper.id = 'chat-item-' + id;
    wrapper.innerHTML = `
        <div class="chat-click-area" onclick="loadChat(${id}, event)">
            <span class="chat-title"></span>
            <span class="chat-date">Gerade eben</span>
        </div>
        <a href="ai_assistant.php?action=delete_chat&chat_id=${id}" class="btn-chat-del" onclick="event.stopPropagation(); return confirm('Diesen Chat wirklich löschen?')" title="Chat löschen">✕</a>
    `;
    wrapper.querySelector('.chat-title').textContent = titleText;
    return wrapper;
}

function ensureChatListEntry(id, titleText) {
    const chatList = document.getElementById('chat-list');
    if (!chatList) return;

    const emptyMsg = chatList.querySelector('.muted');
    if (emptyMsg) emptyMsg.remove();

    let item = document.getElementById('chat-item-' + id);
    if (!item) {
        item = buildChatListItem(id, titleText);
        chatList.prepend(item);
    } else {
        const title = item.querySelector('.chat-title');
        if (title && titleText) {
            title.textContent = titleText;
        }
    }
}

function newChat() {
    currentChatId = 0;
    if (chatMessages) {
        chatMessages.innerHTML = '<div class="message ai">Hier kannst du ein neues Thema starten.</div>';
    }
    setActiveChatItem(0);
    setActiveChatTitle('System Assistant');
    updateHistoryUrl();
}

async function loadChat(id, event) {
    if (event && event.target.closest('.btn-chat-del')) return;
    if (!chatMessages || !id) return;

    currentChatId = parseInt(id, 10) || 0;
    setActiveChatItem(currentChatId);

    const activeItem = document.getElementById('chat-item-' + currentChatId);
    const activeTitle = activeItem?.querySelector('.chat-title')?.textContent?.trim() || 'Thema';
    setActiveChatTitle(activeTitle);
    updateHistoryUrl();

    chatMessages.innerHTML = '<div class="message ai"><i>Lade Verlauf...</i></div>';

    try {
        const res = await fetch('../api/ai_chat_messages.php?chat_id=' + encodeURIComponent(currentChatId));
        const data = await res.json();

        chatMessages.innerHTML = '';
        if (data.success && Array.isArray(data.messages)) {
            if (data.messages.length === 0) {
                appendMessage('assistant', 'Dieser Chat ist noch leer.');
                return;
            }

            data.messages.forEach((m) => appendMessage(m.role, m.content));
        } else {
            appendMessage('assistant', 'Fehler: ' + (data.error || 'Chat konnte nicht geladen werden.'));
        }
    } catch (err) {
        console.error(err);
        chatMessages.innerHTML = '';
        appendMessage('assistant', 'Verbindungsfehler beim Laden des Chats.');
    }
}

async function handleInput() {
    if (!aiInput || !chatMessages) return;

    const text = aiInput.value.trim();
    if (!text) return;
    aiInput.value = '';

    appendMessage('user', text);

    const loadingDiv = document.createElement('div');
    loadingDiv.className = 'message ai';
    loadingDiv.innerHTML = '<i>gimi denkt nach...</i>';
    chatMessages.appendChild(loadingDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;

    try {
        const res = await fetch('../api/ai_query.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                prompt: text,
                chat_id: currentChatId,
                context: {
                    url: getAssistantStableContextUrl(),
                    title: document.title,
                    content: document.querySelector('main')?.innerText?.substring(0, 1500) || ''
                }
            })
        });

        if (!res.ok) {
            throw new Error('HTTP Status ' + res.status);
        }

        const data = await res.json();
        loadingDiv.remove();

        if (data.success) {
            const firstMessageInChat = currentChatId === 0;
            currentChatId = parseInt(data.chat_id || currentChatId, 10) || 0;
            updateHistoryUrl();

            const titleText = text.length > 30 ? text.substring(0, 30) + '...' : text;
            ensureChatListEntry(currentChatId, titleText);
            setActiveChatItem(currentChatId);
            setActiveChatTitle(titleText);

            appendMessage('assistant', data.answer || '');

            if (firstMessageInChat && currentChatId > 0) {
                const item = document.getElementById('chat-item-' + currentChatId);
                if (item) item.scrollIntoView({ block: 'nearest' });
            }
        } else {
            appendMessage('assistant', 'Fehler: ' + (data.error || 'Unbekannter Fehler'));
        }
    } catch (err) {
        console.error('Verbindungsfehler:', err);
        if (loadingDiv.parentNode) {
            loadingDiv.remove();
        }
        appendMessage('assistant', 'Verbindungsfehler: gimi ist gerade nicht erreichbar.');
    }
}

function showAddTraining() {
    document.getElementById('add-training-form').style.display = 'block';
}

function hideAddTraining() {
    document.getElementById('add-training-form').style.display = 'none';
}

window.addEventListener('DOMContentLoaded', () => {
    aiInput = document.getElementById('ai-input');
    chatMessages = document.getElementById('chat-messages');

    if (aiInput) {
        aiInput.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') {
                handleInput();
            }
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    const chatId = parseInt(urlParams.get('chat_id') || '0', 10);
    if (chatId > 0) {
        loadChat(chatId);
    } else {
        setActiveChatTitle('System Assistant');
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
