/**
 * Tech Portal - Progressive Enhancements
 * Vanilla JS improvements for better UX
 */

// ============================================
// 1. TOAST NOTIFICATION SYSTEM
// ============================================

const Toast = {
    container: null,
    
    init() {
        // Create toast container if it doesn't exist
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'toast-container';
            this.container.setAttribute('aria-live', 'polite');
            document.body.appendChild(this.container);
        }
    },
    
    show(message, type = 'info', duration = 4000) {
        this.init();
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        
        // Icon based on type
        const icons = {
            success: '✓',
            error: '✕',
            warning: '⚠',
            info: 'ℹ'
        };
        
        toast.innerHTML = `
            <span class="toast-icon">${icons[type] || icons.info}</span>
            <span class="toast-message">${message}</span>
            <button class="toast-close" aria-label="Dismiss">×</button>
            <div class="toast-progress"></div>
        `;
        
        // Close button
        toast.querySelector('.toast-close').onclick = () => this.dismiss(toast);
        
        // Add to container
        this.container.appendChild(toast);
        
        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('toast-show');
            toast.querySelector('.toast-progress').style.animationDuration = duration + 'ms';
        });
        
        // Auto dismiss
        if (duration > 0) {
            setTimeout(() => this.dismiss(toast), duration);
        }
        
        return toast;
    },
    
    dismiss(toast) {
        if (!toast || toast.classList.contains('toast-hide')) return;
        
        toast.classList.remove('toast-show');
        toast.classList.add('toast-hide');
        
        setTimeout(() => {
            if (toast.parentNode) {
                toast.parentNode.removeChild(toast);
            }
        }, 300);
    },
    
    success(message, duration) { return this.show(message, 'success', duration); },
    error(message, duration) { return this.show(message, 'error', duration); },
    warning(message, duration) { return this.show(message, 'warning', duration); },
    info(message, duration) { return this.show(message, 'info', duration); }
};


// ============================================
// 2. AUTO-SAVE DRAFTS (Job Entry Form)
// ============================================

const AutoSave = {
    key: 'tech_portal_draft',
    form: null,
    debounceTimer: null,
    indicator: null,
    
    init(formSelector = 'form[method="post"]') {
        // Find the job entry form (the one with install_type)
        const forms = document.querySelectorAll(formSelector);
        this.form = Array.from(forms).find(f => f.querySelector('[name="install_type"]'));
        
        if (!this.form) return;
        
        // Check for existing draft
        const draft = this.loadDraft();
        if (draft && Object.keys(draft).length > 0) {
            this.showRestorePrompt(draft);
        }
        
        // Listen for changes
        this.form.addEventListener('input', () => this.scheduleSave());
        this.form.addEventListener('change', () => this.scheduleSave());
        
        // Clear draft on submit
        this.form.addEventListener('submit', () => this.clearDraft());
        
        // Create save indicator
        this.createIndicator();
    },
    
    createIndicator() {
        this.indicator = document.createElement('div');
        this.indicator.id = 'autosave-indicator';
        this.indicator.innerHTML = '💾 Draft saved';
        document.body.appendChild(this.indicator);
    },
    
    scheduleSave() {
        clearTimeout(this.debounceTimer);
        this.debounceTimer = setTimeout(() => this.save(), 1000);
    },
    
    save() {
        if (!this.form) return;
        
        const data = {};
        const formData = new FormData(this.form);
        
        for (const [key, value] of formData.entries()) {
            // Skip CSRF token and file inputs
            if (key === 'csrf_token' || value instanceof File) continue;
            if (value) data[key] = value;
        }
        
        // Only save if there's actual content
        if (Object.keys(data).length > 1) { // More than just the date
            localStorage.setItem(this.key, JSON.stringify(data));
            this.showSaveIndicator();
        }
    },
    
    showSaveIndicator() {
        if (!this.indicator) return;
        this.indicator.classList.add('show');
        setTimeout(() => this.indicator.classList.remove('show'), 2000);
    },
    
    loadDraft() {
        try {
            const saved = localStorage.getItem(this.key);
            return saved ? JSON.parse(saved) : null;
        } catch {
            return null;
        }
    },
    
    showRestorePrompt(draft) {
        const ticketInfo = draft.ticket_number ? ` (#${draft.ticket_number})` : '';
        const typeInfo = draft.install_type || 'job';
        
        Toast.init();
        const toast = Toast.show(
            `Found draft: ${typeInfo}${ticketInfo}. <a href="#" id="restore-draft">Restore</a> | <a href="#" id="discard-draft">Discard</a>`,
            'info',
            0 // Don't auto-dismiss
        );
        
        toast.querySelector('#restore-draft')?.addEventListener('click', (e) => {
            e.preventDefault();
            this.restoreDraft(draft);
            Toast.dismiss(toast);
            Toast.success('Draft restored!');
        });
        
        toast.querySelector('#discard-draft')?.addEventListener('click', (e) => {
            e.preventDefault();
            this.clearDraft();
            Toast.dismiss(toast);
        });
    },
    
    restoreDraft(draft) {
        if (!this.form || !draft) return;
        
        for (const [key, value] of Object.entries(draft)) {
            const field = this.form.querySelector(`[name="${key}"]`);
            if (field) {
                if (field.type === 'checkbox') {
                    field.checked = value === '1' || value === 'on';
                } else {
                    field.value = value;
                }
                // Trigger change event for conditional logic
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }
    },
    
    clearDraft() {
        localStorage.removeItem(this.key);
    }
};


// ============================================
// 3. LIVE SEARCH / FILTER
// ============================================

const LiveFilter = {
    init(inputSelector, tableSelector, options = {}) {
        const input = document.querySelector(inputSelector);
        const table = document.querySelector(tableSelector);
        
        if (!input || !table) return;
        
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        
        // Add counter element
        let counter = null;
        if (options.showCounter) {
            counter = document.createElement('span');
            counter.className = 'filter-counter';
            input.parentNode.appendChild(counter);
        }
        
        const filter = () => {
            const query = input.value.toLowerCase().trim();
            const rows = tbody.querySelectorAll('tr');
            let visible = 0;
            
            rows.forEach(row => {
                if (row.querySelector('td[colspan]')) return; // Skip "no data" rows
                
                const text = row.textContent.toLowerCase();
                const matches = !query || text.includes(query);
                
                row.style.display = matches ? '' : 'none';
                if (matches) visible++;
                
                // Highlight matches
                if (options.highlight && query) {
                    row.querySelectorAll('td').forEach(td => {
                        const original = td.getAttribute('data-original') || td.innerHTML;
                        td.setAttribute('data-original', original);
                        
                        if (matches && query) {
                            const regex = new RegExp(`(${query})`, 'gi');
                            td.innerHTML = original.replace(regex, '<mark>$1</mark>');
                        } else {
                            td.innerHTML = original;
                        }
                    });
                }
            });
            
            if (counter) {
                counter.textContent = query ? `${visible} results` : '';
            }
        };
        
        input.addEventListener('input', filter);
        input.addEventListener('keyup', filter);
    }
};


// ============================================
// 4. KEYBOARD SHORTCUTS
// ============================================

const Shortcuts = {
    bindings: {},
    helpModal: null,
    
    init() {
        document.addEventListener('keydown', (e) => this.handleKey(e));
        
        // Default shortcuts
        this.bind('Escape', () => this.closeModals());
        this.bind('?', () => this.showHelp(), { shift: true });
    },
    
    bind(key, callback, options = {}) {
        const id = this.getKeyId(key, options);
        this.bindings[id] = { key, callback, options, description: options.description || '' };
    },
    
    getKeyId(key, options = {}) {
        let id = '';
        if (options.ctrl) id += 'ctrl+';
        if (options.shift) id += 'shift+';
        if (options.alt) id += 'alt+';
        id += key.toLowerCase();
        return id;
    },
    
    handleKey(e) {
        // Don't trigger in input fields (except for specific shortcuts)
        const inInput = ['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName);
        
        const id = this.getKeyId(e.key, {
            ctrl: e.ctrlKey || e.metaKey,
            shift: e.shiftKey,
            alt: e.altKey
        });
        
        const binding = this.bindings[id];
        if (binding) {
            // Allow Escape in inputs, block others
            if (inInput && e.key !== 'Escape' && !binding.options.allowInInput) {
                return;
            }
            
            e.preventDefault();
            binding.callback(e);
        }
    },
    
    closeModals() {
        // Close any open modals
        document.querySelectorAll('.modal, [id*="Modal"]').forEach(modal => {
            if (modal.style.display !== 'none') {
                modal.style.display = 'none';
                document.body.style.overflow = '';
            }
        });
        
        // Close nav drawer
        const drawer = document.getElementById('navDrawer');
        if (drawer) drawer.classList.remove('open');
    },
    
    showHelp() {
        if (!this.helpModal) {
            this.helpModal = document.createElement('div');
            this.helpModal.id = 'shortcutsModal';
            this.helpModal.className = 'modal';
            this.helpModal.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(0,0,0,0.6); z-index:1000; padding:20px;';
            this.helpModal.onclick = (e) => { if (e.target === this.helpModal) this.helpModal.style.display = 'none'; };
            
            let html = '<div style="background:var(--bg-card); max-width:400px; margin:60px auto; border-radius:16px; padding:24px; box-shadow:var(--shadow-lg);">';
            html += '<h3 style="margin:0 0 16px;">⌨️ Keyboard Shortcuts</h3>';
            html += '<table style="width:100%; font-size:0.9rem;">';
            
            for (const binding of Object.values(this.bindings)) {
                if (binding.description) {
                    let keyLabel = '';
                    if (binding.options.ctrl) keyLabel += 'Ctrl+';
                    if (binding.options.shift) keyLabel += 'Shift+';
                    keyLabel += binding.key.toUpperCase();
                    
                    html += `<tr><td style="padding:6px 0;"><kbd style="background:var(--bg-input); padding:4px 8px; border-radius:4px; font-family:inherit;">${keyLabel}</kbd></td><td style="padding:6px 0; color:var(--text-muted);">${binding.description}</td></tr>`;
                }
            }
            
            html += '</table></div>';
            this.helpModal.innerHTML = html;
            document.body.appendChild(this.helpModal);
        }
        
        this.helpModal.style.display = 'block';
    }
};


// ============================================
// AUTO-INITIALIZE ON DOM READY
// ============================================

document.addEventListener('DOMContentLoaded', () => {
    // Initialize toast system
    Toast.init();
    
    // Initialize auto-save on pages with job entry forms
    if (document.querySelector('[name="install_type"]')) {
        AutoSave.init();
    }
    
    // Initialize keyboard shortcuts
    Shortcuts.init();
    Shortcuts.bind('Escape', () => Shortcuts.closeModals(), { description: 'Close modals' });
    
    // Convert existing PHP messages to toasts
    const alerts = document.querySelectorAll('.alert');
    alerts.forEach((alert, i) => {
        const isError = alert.style.background?.includes('danger') || alert.classList.contains('alert-error');
        setTimeout(() => {
            Toast.show(alert.textContent.trim(), isError ? 'error' : 'success');
        }, i * 200);
        alert.style.display = 'none';
    });
});

// Export for global use
window.Toast = Toast;
window.AutoSave = AutoSave;
window.LiveFilter = LiveFilter;
window.Shortcuts = Shortcuts;
