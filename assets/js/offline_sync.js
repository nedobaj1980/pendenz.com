// offline_sync.js v1.3 – Guard against double-load, purge invalid queue items, no CSP violations
if (typeof window.OfflineSyncInitialized === 'undefined') {
    window.OfflineSyncInitialized = true;

    // Convert a data: URL to Blob without using fetch() – avoids CSP violations
    function dataURLtoBlob(dataUrl) {
        try {
            const parts = dataUrl.split(',');
            if (parts.length < 2) return null;
            const mimeMatch = parts[0].match(/:(.*?);/);
            if (!mimeMatch) return null;
            const mime = mimeMatch[1];
            const bstr = atob(parts[1]);
            let n = bstr.length;
            const u8arr = new Uint8Array(n);
            while (n--) u8arr[n] = bstr.charCodeAt(n);
            return new Blob([u8arr], { type: mime });
        } catch (e) {
            console.warn('dataURLtoBlob failed:', e);
            return null;
        }
    }

    const OfflineSync = {
        dbName: 'PendenzOfflineDB_v3',
        dbVersion: 1,
        storeName: 'syncQueue',
        db: null,
        isSyncing: false,
        initPromise: null,

        async init() {
            if (this.initPromise) return this.initPromise;
            this.initPromise = new Promise((resolve, reject) => {
                const request = indexedDB.open(this.dbName, this.dbVersion);
                request.onerror = (err) => { 
                    console.error('IDB error', err);
                    this.initPromise = null; 
                    reject('Database error'); 
                };
                request.onblocked = () => {
                    console.warn('IDB blocked');
                    this.initPromise = null;
                    reject('Database blocked');
                };
                request.onsuccess = (e) => {
                    this.db = e.target.result;
                    resolve();
                };
                request.onupgradeneeded = (e) => {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains(this.storeName)) {
                        db.createObjectStore(this.storeName, { keyPath: 'id', autoIncrement: true });
                    }
                };
            });
            return this.initPromise;
        },

        // Validate that a URL is a real HTTP(S) URL – not a data: URL or empty
        isValidUrl(url) {
            if (!url || typeof url !== 'string') return false;
            return url.startsWith('http://') || url.startsWith('https://') || url.startsWith('/');
        },

        async addToQueue(formData, url) {
            // Never queue if URL is invalid
            if (!this.isValidUrl(url)) {
                console.warn('OfflineSync: Rejected invalid URL:', url);
                return;
            }

            const data = {};
            const files = [];

            for (let [key, value] of formData.entries()) {
                if (value instanceof File && value.name) {
                    const reader = new FileReader();
                    const fileData = await new Promise(res => {
                        reader.onload = () => res(reader.result);
                        reader.readAsDataURL(value);
                    });
                    files.push({ key, name: value.name, type: value.type, data: fileData });
                } else {
                    data[key] = value;
                }
            }

            const displayName = data['titel'] || data['name'] || 'Neue Pendenz';
            const item = {
                url,
                data,
                files,
                display_name: displayName,
                timestamp: Date.now(),
                status: 'pending'
            };

            return new Promise((resolve, reject) => {
                const transaction = this.db.transaction([this.storeName], 'readwrite');
                const store = transaction.objectStore(this.storeName);
                const request = store.add(item);
                request.onsuccess = () => {
                    this.showNotification('Pendenz lokal gespeichert (Offline-Modus)', 'info');
                    resolve();
                };
                request.onerror = () => reject('Failed to add to queue');
            });
        },

        async setMeta(key, value) {
            if (!this.db) await this.init();
            return new Promise((resolve, reject) => {
                const transaction = this.db.transaction([this.storeName], 'readwrite');
                const store = transaction.objectStore(this.storeName);
                store.put({ id: 'meta_' + key, value, timestamp: Date.now() });
                transaction.oncomplete = () => resolve();
                transaction.onerror = () => reject();
            });
        },

        async getMeta(key) {
            if (!this.db) await this.init();
            return new Promise((resolve) => {
                const transaction = this.db.transaction([this.storeName], 'readonly');
                const store = transaction.objectStore(this.storeName);
                const request = store.get('meta_' + key);
                request.onsuccess = () => resolve(request.result ? request.result.value : null);
                request.onerror = () => resolve(null);
            });
        },

        async sync() {
            if (!navigator.onLine || !this.db || this.isSyncing) return;
            this.isSyncing = true;

            const transaction = this.db.transaction([this.storeName], 'readonly');
            const store = transaction.objectStore(this.storeName);
            const request = store.getAll();

            request.onsuccess = async () => {
                const items = request.result;
                if (items.length === 0) {
                    this.isSyncing = false;
                    this.updateSyncCenter();
                    return;
                }

                const validItems = items.filter(item => this.isValidUrl(item.url));
                const invalidIds = items.filter(item => !this.isValidUrl(item.url)).map(i => i.id);

                if (invalidIds.length > 0) {
                    const purgeTx = this.db.transaction([this.storeName], 'readwrite');
                    invalidIds.forEach(id => purgeTx.objectStore(this.storeName).delete(id));
                }

                if (validItems.length === 0) {
                    this.isSyncing = false;
                    this.updateSyncCenter();
                    return;
                }

                const total = validItems.length;
                this.updateSyncCenter(true, 0, total, 'Starte Synchronisierung...');

                let successCount = 0;
                let failCount = 0;

                for (let i = 0; i < validItems.length; i++) {
                    const item = validItems[i];
                    this.updateSyncCenter(true, i + 1, total, item.display_name);

                    try {
                        const formData = new FormData();
                        for (let key in item.data) formData.append(key, item.data[key]);
                        for (let f of (item.files || [])) {
                            const blob = dataURLtoBlob(f.data);
                            if (blob) formData.append(f.key, blob, f.name);
                        }

                        // Robust timeout fallback for older browsers
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 45000);

                        const response = await fetch(item.url, {
                            method: 'POST',
                            body: formData,
                            signal: controller.signal
                        });
                        clearTimeout(timeoutId);

                        if (response.ok) {
                            // Immediate delete on success to prevent duplicates if subsequent items fail
                            const deleteTx = this.db.transaction([this.storeName], 'readwrite');
                            deleteTx.objectStore(this.storeName).delete(item.id);
                            await new Promise(res => { deleteTx.oncomplete = res; });
                            
                            successCount++;
                            // Short throttle to let the server breathe
                            await new Promise(res => setTimeout(res, 500));
                        } else {
                            failCount++;
                        }
                    } catch (err) {
                        console.error('Sync failed for item', item.id, err);
                        failCount++;
                    }
                }

                if (successCount > 0 && failCount === 0) {
                    this.showNotification('Alle Pendenzen erfolgreich synchronisiert!', 'success', 100);
                } else if (successCount > 0) {
                    this.showNotification(`${successCount} synchronisiert, ${failCount} fehlgeschlagen.`, 'info', 100);
                } else if (failCount > 0) {
                    // Only show notification if sync center is NOT visible or we want to be explicit
                    this.showNotification('Synchronisierung fehlgeschlagen. Versuche es später erneut.', 'error', 0);
                }

                if (successCount > 0) {
                    window.postMessage({ type: 'pendenz_synced' }, '*');
                }
                this.isSyncing = false;
                this.updateSyncCenter();
            };

            request.onerror = () => { this.isSyncing = false; };
        },

        showNotification(msg, type, progress = null) {
            const id = 'offline-notif';
            let el = document.getElementById(id);
            if (!el) {
                el = document.createElement('div');
                el.id = id;
                el.style.cssText = `
                    position: fixed; bottom: 20px; right: 20px; z-index: 10001;
                    padding: 16px 20px; border-radius: 16px; color: #fff;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    font-weight: 600; box-shadow: 0 15px 35px rgba(0,0,0,0.25);
                    transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
                    display: flex; flex-direction: column; gap: 8px; min-width: 280px;
                    backdrop-filter: blur(10px);
                `;
                document.body.appendChild(el);
            }

            const colors = { 
                info: 'rgba(51, 65, 85, 0.95)', 
                sync: 'rgba(37, 99, 235, 0.95)', 
                success: 'rgba(16, 185, 129, 0.95)', 
                error: 'rgba(239, 68, 68, 0.95)' 
            };
            el.style.background = colors[type] || colors.info;
            
            let html = `<div style="display:flex; align-items:center; gap:10px;">
                <span style="font-size:18px;">${type === 'sync' ? '⏳' : (type === 'success' ? '✅' : 'ℹ️')}</span>
                <span style="flex:1;">${msg}</span>
            </div>`;

            if (progress !== null && type === 'sync') {
                html += `
                    <div style="width:100%; height:6px; background:rgba(255,255,255,0.2); border-radius:3px; overflow:hidden; margin-top:4px;">
                        <div style="width:${progress}%; height:100%; background:#fff; transition:width 0.3s ease;"></div>
                    </div>
                `;
            }

            el.innerHTML = html;
            el.style.opacity = '1';
            el.style.transform = 'translateY(0) scale(1)';

            if (type !== 'sync') {
                setTimeout(() => {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(20px) scale(0.95)';
                    setTimeout(() => { if (el.parentNode) el.parentNode.removeChild(el); }, 500);
                }, 6000);
            }
        },

        async updateSyncCenter(active = false, current = 0, total = 0, currentName = '') {
            const id = 'offline-sync-center-master'; 
            
            // Clean up old ID if present from previous versions
            const oldId = 'offline-sync-center';
            const oldEl = document.getElementById(oldId);
            if (oldEl) oldEl.remove();
            
            const count = await new Promise(res => {
                const tx = this.db.transaction([this.storeName], 'readonly');
                const store = tx.objectStore(this.storeName);
                const req = store.count();
                req.onsuccess = () => res(req.result);
            });

            if (count === 0 && !active) {
                const existing = document.getElementById(id);
                if (existing) {
                    existing.style.opacity = '0';
                    existing.style.transform = 'translateY(20px) scale(0.95)';
                    setTimeout(() => { existing.style.display = 'none'; }, 300);
                }
                return;
            }

            let el = document.getElementById(id);
            if (!el) {
                el = document.createElement('div');
                el.id = id;
                el.style.cssText = `
                    position: fixed; bottom: 80px; right: 20px; z-index: 10000;
                    background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px);
                    color: #1e293b; padding: 24px; border-radius: 32px;
                    box-shadow: 0 30px 60px rgba(0,0,0,0.2); width: 320px;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    border: 1px solid rgba(255,255,255,0.5);
                    transition: all 0.6s cubic-bezier(0.34, 1.56, 0.64, 1);
                    opacity: 0; transform: translateY(40px) scale(0.9);
                `;
                document.body.appendChild(el);
            }

            el.style.display = 'block';
            setTimeout(() => { el.style.opacity = '1'; el.style.transform = 'translateY(0) scale(1)'; }, 10);

            let content = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <div style="width:32px; height:32px; background:#0ea5e9; border-radius:10px; display:flex; align-items:center; justify-content:center; color:#fff;">
                            ${active ? '<span style="animation: spin 2s linear infinite;">🔄</span>' : '📦'}
                        </div>
                        <div>
                            <strong style="font-size:16px; display:block; line-height:1;">Sync-Center</strong>
                            <span style="font-size:11px; color:#64748b; font-weight:600;">${count} in der Queue</span>
                        </div>
                    </div>
                    <button onclick="this.parentElement.parentElement.style.opacity=0; setTimeout(()=>OfflineSync.updateSyncCenter(), 500)" style="background:none; border:none; color:#94a3b8; cursor:pointer; font-size:18px;">✕</button>
                </div>
            `;

            if (active) {
                const prog = total > 0 ? (current / total) * 100 : 0;
                content += `
                    <div style="font-size:13px; color:#475569; margin-bottom:6px; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        ${currentName}
                    </div>
                    <div style="width:100%; height:10px; background:rgba(0,0,0,0.05); border-radius:20px; overflow:hidden; margin-bottom:6px;">
                        <div style="width:${prog}%; height:100%; background:linear-gradient(90deg, #0ea5e9, #2563eb); transition:width 0.4s ease; border-radius:20px;"></div>
                    </div>
                    <div style="font-size:11px; color:#94a3b8; font-weight:500;">Fortschritt: ${current} von ${total} erledigt</div>
                `;
            } else {
                content += `
                    <div style="font-size:13px; color:#64748b; line-height:1.5; margin-bottom:16px;">
                        Deine Daten sind lokal gesichert und warten auf die Übertragung.
                    </div>
                    <button onclick="OfflineSync.sync()" style="
                        width:100%; padding:14px; background:linear-gradient(135deg, #0f172a 0%, #1e293b 100%); color:#fff; 
                        border:none; border-radius:16px; cursor:pointer; font-weight:800; 
                        font-size:14px; transition:0.3s; display:flex; align-items:center; justify-content:center; gap:10px;
                        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
                    ">
                        <span>🚀</span> Jetzt synchronisieren
                    </button>
                `;
            }

            // CSS for animation if not present
            if (!document.getElementById('sync-style')) {
                const s = document.createElement('style');
                s.id = 'sync-style';
                s.innerHTML = '@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }';
                document.head.appendChild(s);
            }

            el.innerHTML = content;
        },

        attachToForm(form) {
            if (!form || form.dataset.offlineAttached) return;
            form.dataset.offlineAttached = 'true';

            form.addEventListener('submit', async (e) => {
                e.preventDefault(); // Always intercept to provide premium UI feedback

                const targetUrl = form.action && this.isValidUrl(form.action) ? form.action : window.location.href;
                if (!this.isValidUrl(targetUrl)) return;

                const btn = form.querySelector('button[type="submit"]');
                const originalHtml = btn ? btn.innerHTML : '';
                if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Speichere...'; }

                const formData = new FormData(form);

                // Strategy: Try to send immediately if browser thinks we are online
                if (navigator.onLine) {
                    try {
                        const controller = new AbortController();
                        const timeoutId = setTimeout(() => controller.abort(), 10000); // 10s for immediate UI response

                        const response = await fetch(targetUrl, {
                            method: 'POST',
                            body: formData,
                            signal: controller.signal
                        });
                        clearTimeout(timeoutId);

                        if (response.ok) {
                            // Try to parse JSON to trigger success event with data if possible
                            let responseData = null;
                            try { responseData = await response.clone().json(); } catch(e) {}
                            
                            form.dispatchEvent(new CustomEvent('offlineSyncSuccess', { 
                                detail: { online: true, response: responseData } 
                            }));

                            if (btn) {
                                btn.innerHTML = '✅ Erfolgreich';
                                setTimeout(() => {
                                    if (form.dataset.redirect) {
                                        window.location.href = form.dataset.redirect;
                                    } else if (!form.dataset.noReload) {
                                        if (response.redirected) {
                                            window.location.href = response.url;
                                        } else {
                                            window.location.reload();
                                        }
                                    } else {
                                        // If no reload, reset button
                                        btn.disabled = false;
                                        btn.innerHTML = originalHtml;
                                    }
                                }, 800);
                            }
                            return; // Success!
                        }
                    } catch (err) {
                        console.warn('Immediate send failed, queuing instead...', err);
                    }
                }

                try {
                    await this.addToQueue(formData, targetUrl);
                    
                    form.dispatchEvent(new CustomEvent('offlineSyncSuccess', { 
                        detail: { online: false } 
                    }));

                    if (btn) {
                        btn.innerHTML = '📦 Lokal in Warteschlange';
                        setTimeout(() => { 
                            btn.disabled = false; 
                            btn.innerHTML = originalHtml;
                            if (window.location.pathname.includes('pendenz_neu.php') && !form.dataset.noRedirect) {
                                window.location.href = 'pendenzen.php';
                            }
                        }, 1500);
                    }
                    this.updateSyncCenter();
                    this.sync();
                } catch (err) {
                    console.error('Offline save failed', err);
                    if (btn) { btn.disabled = false; btn.innerHTML = '❌ Fehler'; }
                }
            });
        }
    };

    // Global access
    window.OfflineSync = OfflineSync;

    // Sync when back online
    window.addEventListener('online', () => {
        OfflineSync.sync();
    });

    const initOffline = () => {
        // Attach immediately, regardless of DB state
        document.querySelectorAll('form[data-offline-sync]').forEach(f => OfflineSync.attachToForm(f));

        OfflineSync.init().then(() => {
            OfflineSync.sync(); // Auto-purge invalid items on first run
            OfflineSync.updateSyncCenter();
        }).catch(err => {
            console.error('OfflineSync init failed', err);
        });
    };

    if (document.readyState === 'loading') {
        window.addEventListener('load', initOffline);
    } else {
        initOffline();
    }
}
