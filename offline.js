/**
 * offline.js
 * Handles IndexedDB interactions for "Store & Sync" functionality.
 */

const OfflineManager = {
    dbName: 'tech_portal_db',
    storeName: 'job_outbox',
    dbVersion: 1,

    // Open Database
    openDB: function () {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(this.dbName, this.dbVersion);

            request.onerror = (event) => reject('DB Error: ' + event.target.error);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(this.storeName)) {
                    db.createObjectStore(this.storeName, { keyPath: 'id', autoIncrement: true });
                }
            };

            request.onsuccess = (event) => resolve(event.target.result);
        });
    },

    // Save a job to the Outbox
    saveJob: async function (jobData) {
        const db = await this.openDB();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);

            // Add timestamp for reference
            jobData._created_at = new Date().toISOString();

            const request = store.add(jobData);

            request.onsuccess = () => resolve(true);
            request.onerror = (e) => reject(e.target.error);
        });
    },

    // Get all pending jobs
    getPendingJobs: async function () {
        const db = await this.openDB();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction([this.storeName], 'readonly');
            const store = transaction.objectStore(this.storeName);
            const request = store.getAll();

            request.onsuccess = () => resolve(request.result);
            request.onerror = (e) => reject(e.target.error);
        });
    },

    // Delete a specific job (after successful sync)
    deleteJob: async function (id) {
        const db = await this.openDB();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction([this.storeName], 'readwrite');
            const store = transaction.objectStore(this.storeName);
            const request = store.delete(id);

            request.onsuccess = () => resolve(true);
            request.onerror = (e) => reject(e.target.error);
        });
    },

    // Count pending items
    getOutboxCount: async function () {
        const db = await this.openDB();
        return new Promise((resolve, reject) => {
            const transaction = db.transaction([this.storeName], 'readonly');
            const store = transaction.objectStore(this.storeName);
            const request = store.count();

            request.onsuccess = () => resolve(request.result);
            request.onerror = (e) => reject(e.target.error);
        });
    },

    // Sync Logic
    syncNow: async function () {
        if (!navigator.onLine) return { success: false, message: 'Still offline' };

        const jobs = await this.getPendingJobs();
        if (jobs.length === 0) return { success: true, count: 0 };

        let syncedCount = 0;
        let errors = [];

        for (const job of jobs) {
            try {
                // Send to API
                const response = await fetch('api_sync.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(job)
                });

                const result = await response.json();

                if (result.success) {
                    await this.deleteJob(job.id);
                    syncedCount++;
                } else {
                    console.error('Sync failed for job', job.id, result.message);
                    errors.push(`Job ${job.id}: ${result.message}`);
                }
            } catch (e) {
                console.error('Network error during sync for job', job.id, e);
                errors.push(`Network error: ${e.message}`);
            }
        }

        return {
            success: errors.length === 0,
            synced: syncedCount,
            total: jobs.length,
            errors: errors
        };
    }
};
