/* assets/js/ai_assistant.js */

class AiCopilot {
  constructor() {
    this.container = null;
    this.messagesEl = null;
    this.inputEl = null;
    this.isActive = localStorage.getItem('ai-assistant-active') === '1';
    
    this.init();
  }

  init() {
    this.createDom();
    this.bindEvents();
    this.updateVisibility();
    
    // Initial message if active
    if (this.isActive && this.messagesEl.children.length === 0) {
      this.addMessage('Guten Tag! Ich bin <strong>gimi</strong>. Wie kann ich Ihnen heute bei der Verwaltung von <strong>' + document.title + '</strong> helfen?', 'bot');
    }
  }

  createDom() {
    const html = `
      <div id="ai-copilot-container">
        <div class="ai-copilot-header">
          <h3><span class="status-dot"></span> gimi</h3>
          <button class="btn-icon" onclick="toggleAiAssistant()" style="color:#fff; opacity:0.6;">✕</button>
        </div>
        <div class="ai-messages" id="ai-copilot-messages"></div>
        <div class="ai-copilot-input">
          <div class="ai-context-chip">📍 Aktuelle Seite: ${this.getShortTitle()}</div>
          <div class="ai-input-wrapper">
            <input type="text" id="ai-copilot-input-field" placeholder="Fragen Sie mich etwas...">
            <button class="ai-send-btn" id="ai-copilot-send">🚀</button>
          </div>
          <div class="ai-typing" id="ai-copilot-typing">gimi denkt nach...</div>
        </div>
      </div>
    `;
    document.body.insertAdjacentHTML('beforeend', html);
    this.container = document.getElementById('ai-copilot-container');
    this.messagesEl = document.getElementById('ai-copilot-messages');
    this.inputEl = document.getElementById('ai-copilot-input-field');
  }

  getShortTitle() {
    return document.title.split('|')[0].trim();
  }

  bindEvents() {
    window.addEventListener('aiStateChanged', (e) => {
      this.isActive = e.detail.active;
      this.updateVisibility();
    });

    document.getElementById('ai-copilot-send').addEventListener('click', () => this.handleSend());
    this.inputEl.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') this.handleSend();
    });
  }

  updateVisibility() {
    if (this.isActive) {
      this.container.classList.add('active');
    } else {
      this.container.classList.remove('active');
    }
  }

  addMessage(text, role) {
    const msg = document.createElement('div');
    msg.className = `ai-msg ${role}`;
    msg.innerHTML = text;
    this.messagesEl.appendChild(msg);
    this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
  }

  async handleSend() {
    const text = this.inputEl.value.trim();
    if (!text) return;

    this.inputEl.value = '';
    this.addMessage(text, 'user');

    const typing = document.getElementById('ai-copilot-typing');
    typing.style.display = 'block';

    try {
      const response = await this.callApi(text);
      typing.style.display = 'none';
      this.addMessage(response, 'bot');
    } catch (err) {
      typing.style.display = 'none';
      this.addMessage('Entschuldigung, es gab einen Fehler bei der Kommunikation mit dem Server.', 'bot');
      console.error(err);
    }
  }

  async callApi(prompt) {
    // Sammle Kontext der aktuellen Seite
    const context = {
      url: window.location.href,
      title: document.title,
      content: this.getPageContext()
    };

    const res = await fetch('/pendenz.com/api/ai_query.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ prompt, context })
    });

    if (!res.ok) throw new Error('API Error');
    const data = await res.json();
    return data.answer || 'Keine Antwort erhalten.';
  }

  getPageContext() {
    // Versuche relevante Daten von der Seite zu extrahieren
    const data = {};
    
    // Aktuelle Projekt-ID aus URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('projekt_id')) data.projekt_id = urlParams.get('projekt_id');
    
    // Tabellen-Inhalte (nur Header und erste paar Zeilen als Teaser)
    const tables = document.querySelectorAll('table');
    if (tables.length > 0) {
      data.tables = Array.from(tables).map(t => {
        const headers = Array.from(t.querySelectorAll('th')).map(th => th.innerText.trim());
        return { headers };
      });
    }

    return data;
  }
}

// Global initialisieren
document.addEventListener('DOMContentLoaded', () => {
  window.aiCopilot = new AiCopilot();
});
