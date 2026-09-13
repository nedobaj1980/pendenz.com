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
        dbName: 'PendenzOfflineDB',
        dbVersion: 1,
        storeName: 'syncQueue',
        db: null,
        isSyncing: false,

        async init() {
            return new Promise((resolve, reject) => {
                const request = indexedDB.open(this.dbName, this.dbVersion);
                request.onerror = () => reject('Database error');
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

            const item = {
                url,
                data,
                files,
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
                    return;
                }

                // Separate valid items from invalid (data: URLs, missing URLs, etc.)
                const validItems = [];
                const invalidIds = [];
                for (const item of items) {
                    if (this.isValidUrl(item.url)) {
                        validItems.push(item);
                    } else {
                        console.warn('OfflineSync: Purging invalid queue item', item.id, 'url:', item.url);
                        invalidIds.push(item.id);
                    }
                }

                // Purge invalid items immediately without retrying
                if (invalidIds.length > 0) {
                    try {
                        const purgeTx = this.db.transaction([this.storeName], 'readwrite');
                        const purgeStore = purgeTx.objectStore(this.storeName);
                        invalidIds.forEach(id => purgeStore.delete(id));
                        await new Promise((res, rej) => {
                            purgeTx.oncomplete = res;
                            purgeTx.onerror = rej;
                        });
                        console.log(`OfflineSync: Purged ${invalidIds.length} invalid item(s) from queue.`);
                    } catch (e) {
                        console.error('OfflineSync: Purge failed', e);
                    }
                }

                if (validItems.length === 0) {
                    this.isSyncing = false;
                    return;
                }

                console.log(`Syncing ${validItems.length} items...`);
                this.showNotification(`${validItems.length} Pendenzen werden synchronisiert...`, 'sync');

                let idsToDelete = [];
                for (const item of validItems) {
                    try {
                        const formData = new FormData();
                        for (let key in item.data) formData.append(key, item.data[key]);
                        for (let f of (item.files || [])) {
                            try {
                                // Convert base64 data URL to Blob directly – no fetch() needed, no CSP issues
                                const blob = dataURLtoBlob(f.data);
                                if (blob) formData.append(f.key, blob, f.name);
                            } catch (e) { console.warn('File blob conversion failed', e); }
                        }

                        const response = await fetch(item.url, {
                            method: 'POST',
                            body: formData,
                            signal: AbortSignal.timeout(30000)
                        });

                        if (response.ok) {
                            idsToDelete.push(item.id);
                        }
                    } catch (err) {
                        console.error('Sync failed for item', item.id, err);
                    }
                }

                if (idsToDelete.length > 0) {
                    try {
                        const deleteTx = this.db.transaction([this.storeName], 'readwrite');
                        const deleteStore = deleteTx.objectStore(this.storeName);
                        idsToDelete.forEach(id => deleteStore.delete(id));
                        await new Promise((res, rej) => {
                            deleteTx.oncomplete = res;
                            deleteTx.onerror = rej;
                        });

                        this.showNotification('Synchronisierung abgeschlossen!', 'success');
                        window.postMessage({ type: 'pendenz_synced' }, '*');
                        if (window.parent !== window) {
                            window.parent.postMessage({ type: 'pendenz_synced' }, '*');
                        }
                    } catch (e) {
                        console.error('Delete transaction failed', e);
                    }
                }
                this.isSyncing = false;
            };

            request.onerror = () => {
                this.isSyncing = false;
            };
        },

        showNotification(msg, type) {
            const id = 'offline-notif';
            let el = document.getElementById(id);
            if (!el) {
                el = document.createElement('div');
                el.id = id;
                el.style.cssText = `
                    position: fixed; bottom: 20px; right: 20px; z-index: 10001;
                    padding: 12px 20px; border-radius: 12px; color: #fff;
                    font-family: sans-serif; font-weight: bold; box-shadow: 0 10px 25px rgba(0,0,0,0.2);
                    transition: transform 0.3s ease, opacity 0.3s ease;
                    display: flex; align-items: center; gap: 10px;
                `;
                document.body.appendChild(el);
            }

            const colors = { info: '#3b82f6', sync: '#f59e0b', success: '#10b981', error: '#ef4444' };
            el.style.background = colors[type] || colors.info;
            el.innerHTML = `<span>${msg}</span>`;
            el.style.opacity = '1';
            el.style.transform = 'translateY(0)';

            if (type !== 'sync') {
                setTimeout(() => {
                    el.style.opacity = '0';
                    el.style.transform = 'translateY(20px)';
                }, 5000);
            }
        },

        attachToForm(form) {
            if (!form || form.dataset.offlineAttached) return;
            form.dataset.offlineAttached = 'true';

            form.addEventListener('submit', async (e) => {
                // Only intercept if offline
                if (navigator.onLine) return; // let the form submit normally when online

                e.preventDefault();

                const targetUrl = form.action && this.isValidUrl(form.action)
                    ? form.action
                    : window.location.href;

                // Safety check: do not queue if URL is still invalid
                if (!this.isValidUrl(targetUrl)) {
                    console.warn('OfflineSync: Cannot queue – invalid form action URL:', targetUrl);
                    return;
                }

                const btn = form.querySelector('button[type="submit"]');
                const originalHtml = btn ? btn.innerHTML : '';
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '⚡ Speichere lokal...';
                }

                try {
                    const formData = new FormData(form);
                    await this.addToQueue(formData, targetUrl);

                    if (btn) {
                        btn.innerHTML = '✅ Lokal gesichert';
                        setTimeout(() => {
                            btn.disabled = false;
                            btn.innerHTML = originalHtml;
                        }, 1000);
                    }

                    window.postMessage({ type: 'pendenz_saved_offline' }, '*');
                    if (window.parent !== window) {
                        window.parent.postMessage({ type: 'pendenz_saved_offline' }, '*');
                    }

                    this.sync();
                } catch (err) {
                    console.error('Offline save failed', err);
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = '❌ Fehler';
                    }
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
        OfflineSync.init().then(() => {
            OfflineSync.sync(); // This will auto-purge invalid items on first run
            document.querySelectorAll('form[data-offline-sync]').forEach(f => OfflineSync.attachToForm(f));
        });
    };

    if (document.readyState === 'loading') {
        window.addEventListener('load', initOffline);
    } else {
        initOffline();
    }
}
