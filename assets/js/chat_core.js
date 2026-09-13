// assets/js/chat_core.js
export class ChatAPI {
  constructor({ prefix = '/', csrf = '' } = {}) {
    this.prefix = prefix.endsWith('/') ? prefix : prefix + '/';
    this.csrf =
      csrf ||
      document.querySelector('meta[name="csrf-token"]')?.content ||
      document.getElementById('chat-app')?.dataset.csrf ||
      '';
  }
  _headers() { const h = {}; if (this.csrf) h['X-CSRF-Token'] = this.csrf; return h; }

  async messages(roomId, sinceId = 0, limit = 100) {
    const u = new URL(this.prefix + 'api/chat/messages.php', window.location.origin);
    u.searchParams.set('room_id', String(roomId));
    if (sinceId) u.searchParams.set('since_id', String(sinceId));
    u.searchParams.set('limit', String(limit));
    const r = await fetch(u, { credentials: 'same-origin' });
    const j = await r.json().catch(() => null);
    return j || { ok: false, error: 'non-json' };
  }
  async send(roomId, text) {
    const fd = new FormData();
    fd.set('csrf', this.csrf);
    fd.set('room_id', String(roomId));
    fd.set('message', String(text));
    const r = await fetch(this.prefix + 'api/chat/messages.php', {
      method: 'POST', credentials: 'same-origin', body: fd, headers: this._headers(),
    });
    const j = await r.json().catch(() => null);
    return j || { ok: false, error: 'non-json' };
  }
  async markRead(roomId, lastId) {
    const fd = new FormData();
    fd.set('csrf', this.csrf);
    fd.set('room_id', String(roomId));
    fd.set('last_id', String(lastId));
    try {
      const r = await fetch(this.prefix + 'api/chat/read.php', {
        method: 'POST', credentials: 'same-origin', body: fd, headers: this._headers(),
      });
      return await r.json().catch(() => ({ ok: false }));
    } catch {
      return { ok: false };
    }
  }
  async typing(roomId) {
    const fd = new FormData();
    fd.set('csrf', this.csrf);
    fd.set('room_id', String(roomId));
    try {
      await fetch(this.prefix + 'api/chat/typing.php', {
        method: 'POST', credentials: 'same-origin', body: fd, headers: this._headers(),
      });
    } catch {}
  }
}

export class ChatPoller {
  constructor(api, { roomId, onNewMessages, interval = 3000 }) {
    this.api = api;
    this.roomId = roomId;
    this.onNewMessages = onNewMessages;
    this.interval = interval;
    this._since = 0;
    this._timer = null;
    this._stop = false;
  }
  // Neue Methode: nach „Senden“ den since-Zeiger anheben, damit der nächste Poll nichts doppelt holt
  bumpSince(id) {
    const v = Number(id)||0;
    if (v > this._since) this._since = v;
  }
  async _tick() {
    if (this._stop) return;
    const sinceSnapshot = this._since;
    try {
      const res = await this.api.messages(this.roomId, sinceSnapshot, 100);
      let msgs = (res?.ok && Array.isArray(res.messages)) ? res.messages : [];
      // Dedupe: nur wirklich neue IDs > sinceSnapshot
      msgs = msgs.filter(m => Number(m.id) > sinceSnapshot);
      if (msgs.length) {
        this._since = Number(msgs[msgs.length - 1].id) || this._since;
        this.onNewMessages(msgs);
      }
    } finally {
      if (!this._stop) this._timer = setTimeout(() => this._tick(), this.interval);
    }
  }
  start() {
    this._stop = false;
    if (this._timer) clearTimeout(this._timer);
    this._tick();
  }
  destroy() {
    this._stop = true;
    if (this._timer) clearTimeout(this._timer);
  }
}
