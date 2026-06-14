/**
 * BillManage App JS — MD3 Light Theme
 */

document.addEventListener('DOMContentLoaded', function() {

    // ─── 1. SIDEBAR COLLAPSE TOGGLE ─────────────────────────────
    const sidebar      = document.getElementById('sidebar');
    const mainContent  = document.querySelector('.main-content');
    const collapseBtn  = document.getElementById('sidebar-collapse-btn');

    function setSidebarState(collapsed) {
        if (!sidebar) return;
        sidebar.classList.toggle('collapsed', collapsed);
        if (mainContent) mainContent.classList.toggle('collapsed', collapsed);
        localStorage.setItem('sidebarCollapsed', collapsed ? '1' : '0');
    }

    // Restore saved state
    if (sidebar && localStorage.getItem('sidebarCollapsed') === '1') {
        setSidebarState(true);
    }

    if (collapseBtn) {
        collapseBtn.addEventListener('click', () => {
            setSidebarState(!sidebar.classList.contains('collapsed'));
        });
    }

    // ─── 2. MOBILE SIDEBAR TOGGLE ───────────────────────────────
    const mobileToggle = document.getElementById('mobile-toggle');
    const overlay      = document.createElement('div');
    overlay.className  = 'sidebar-overlay';
    document.body.appendChild(overlay);

    if (mobileToggle && sidebar) {
        mobileToggle.addEventListener('click', () => {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('active');
        });

        overlay.addEventListener('click', () => {
            sidebar.classList.remove('open');
            overlay.classList.remove('active');
        });
    }

    // ─── 3. GLOBAL SEARCH (Ctrl+K / /) ──────────────────────────
    const globalSearchInput = document.getElementById('global-search-input');

    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey && e.key === 'k') || (e.key === '/' && document.activeElement.tagName !== 'INPUT' && document.activeElement.tagName !== 'TEXTAREA')) {
            e.preventDefault();
            if (globalSearchInput) globalSearchInput.focus();
        }
        if (e.key === 'Escape' && globalSearchInput && document.activeElement === globalSearchInput) {
            globalSearchInput.blur();
            globalSearchInput.value = '';
        }
    });

    // ─── 4. CONFIRM DELETE / ACTIONS ────────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            if (!confirm(btn.getAttribute('data-confirm') || 'Are you sure you want to perform this action?')) {
                e.preventDefault();
            }
        });
    });

    // ─── 5. MODAL HANDLERS ──────────────────────────────────────
    window.openModal = function(id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.add('active');
    };

    window.closeModal = function(id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.remove('active');
    };

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) overlay.classList.remove('active');
        });
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.active').forEach(modal => {
                modal.classList.remove('active');
            });
        }
    });

    // ─── 6. BARCODE SCANNER DETECTION ───────────────────────────
    let barcodeBuffer = '';
    let lastKeyTime   = Date.now();

    document.querySelectorAll('.barcode-scan').forEach(input => {
        input.addEventListener('keydown', (e) => {
            const now = Date.now();
            if (now - lastKeyTime > 50) barcodeBuffer = '';

            if (e.key === 'Enter') {
                if (barcodeBuffer.length > 2) {
                    input.value = barcodeBuffer;
                    input.dispatchEvent(new Event('change'));
                    if (typeof window.onBarcodeScanned === 'function') {
                        window.onBarcodeScanned(barcodeBuffer);
                    }
                }
                barcodeBuffer = '';
            } else if (e.key.length === 1) {
                barcodeBuffer += e.key;
            }

            lastKeyTime = now;
        });
    });

    // ─── 7. TABLE CLIENT-SIDE FILTER ────────────────────────────
    window.filterTable = function(inputId, tableId) {
        const input = document.getElementById(inputId);
        const table = document.getElementById(tableId);
        if (!input || !table) return;

        const filter = input.value.toLowerCase();
        const rows   = table.getElementsByTagName('tr');

        for (let i = 1; i < rows.length; i++) {
            let found = false;
            const cells = rows[i].getElementsByTagName('td');
            for (let j = 0; j < cells.length; j++) {
                if (cells[j].innerText.toLowerCase().includes(filter)) {
                    found = true;
                    break;
                }
            }
            rows[i].style.display = found ? '' : 'none';
        }
    };

    // ─── 8. CURRENCY INPUT FORMATTING ───────────────────────────
    document.querySelectorAll('.currency-input').forEach(input => {
        input.addEventListener('blur', () => {
            const value = parseFloat(input.value);
            if (!isNaN(value)) input.value = value.toFixed(2);
        });
    });

    // ─── 9. NOTIFICATION DROPDOWN ───────────────────────────────
    window.toggleNotifDropdown = function() {
        const dropdown = document.getElementById('notif-content');
        if (!dropdown) return;
        dropdown.classList.toggle('show');
        if (dropdown.classList.contains('show')) fetchLatestNotifs();
    };

    window.fetchLatestNotifs = function() {
        const container = document.getElementById('notif-items');
        if (!container) return;

        fetch(BASE_URL + '/modules/notifications/get_latest.php')
            .then(res => res.json())
            .then(data => {
                if (!data.length) {
                    container.innerHTML = '<div style="text-align:center;padding:24px;font-size:13px;color:var(--on-surface-muted)"><i class="fas fa-bell-slash" style="font-size:24px;color:var(--border);display:block;margin-bottom:8px"></i>No new notifications</div>';
                    return;
                }

                let html = '';
                data.forEach(n => {
                    const icon = getNotifIcon(n.type);
                    html += `
                        <div class="notif-drop-item" onclick="location.href='${BASE_URL}/modules/notifications/index.php'">
                            <div class="notif-drop-icon ${icon.cls}">
                                <i class="fas ${icon.icon}"></i>
                            </div>
                            <div class="notif-drop-content">
                                <div class="notif-drop-title">${n.title}</div>
                                <div class="notif-drop-msg">${n.message}</div>
                                <div class="notif-drop-time">${n.time_ago}</div>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;
            })
            .catch(() => {
                container.innerHTML = '<div style="text-align:center;padding:20px;font-size:13px;color:var(--on-surface-muted)">Could not load notifications</div>';
            });
    };

    function getNotifIcon(type) {
        const icons = {
            low_stock:  { icon: 'fa-box-open',        cls: 'warning' },
            dead_stock: { icon: 'fa-skull-crossbones', cls: 'danger'  },
            credit_due: { icon: 'fa-user-clock',       cls: 'info'    },
            transfer:   { icon: 'fa-right-left',       cls: 'primary' },
            system:     { icon: 'fa-circle-info',      cls: 'muted'   },
        };
        return icons[type] || icons.system;
    }

    // Close notification dropdown on outside click
    document.addEventListener('click', (e) => {
        const dropdown = document.getElementById('notif-dropdown');
        const content  = document.getElementById('notif-content');
        if (dropdown && !dropdown.contains(e.target) && content) {
            content.classList.remove('show');
        }
    });

    // ─── 10. TOAST FLASH MESSAGES ───────────────────────────────
    // Auto-dismiss .alert-auto-dismiss after 4s
    const flashEl = document.querySelector('.alert-auto-dismiss');
    if (flashEl) {
        setTimeout(() => {
            flashEl.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
            flashEl.style.opacity    = '0';
            flashEl.style.transform  = 'translateX(20px)';
            setTimeout(() => flashEl.remove(), 400);
        }, 4000);
    }

    // ─── 11. TABS (data-tab pattern) ────────────────────────────
    document.querySelectorAll('[data-tab-target]').forEach(btn => {
        btn.addEventListener('click', () => {
            const target     = btn.dataset.tabTarget;
            const group      = btn.dataset.tabGroup || 'default';
            const allBtns    = document.querySelectorAll(`[data-tab-group="${group}"]`);
            const allPanels  = document.querySelectorAll(`[data-tab-panel][data-tab-group="${group}"]`);

            allBtns.forEach(b => b.classList.remove('active'));
            allPanels.forEach(p => p.style.display = 'none');

            btn.classList.add('active');
            const panel = document.querySelector(`[data-tab-panel="${target}"][data-tab-group="${group}"]`);
            if (panel) panel.style.display = '';
        });
    });

    // Activate first tab in each group on load
    const tabGroups = new Set();
    document.querySelectorAll('[data-tab-group]').forEach(el => tabGroups.add(el.dataset.tabGroup));
    tabGroups.forEach(group => {
        const first = document.querySelector(`[data-tab-target][data-tab-group="${group}"]`);
        if (first && !document.querySelector(`[data-tab-group="${group}"].active`)) {
            first.click();
        }
    });

});
