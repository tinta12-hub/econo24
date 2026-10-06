/**
 * 24/7 - 24-Hour Business Operations Management System
 * Core Application Engine & SPA Controller
 */

const App = {
    state: {
        user: null,
        branches: [],
        activeBranchId: 'all',
        currentRoute: 'dashboard',
        dashboardData: null,
        clockInterval: null,
        notificationsInterval: null,
        unreadNotifications: 0
    },

    async init() {
        try {
            // Check current authentication session
            const authRes = await API.get('auth.php', { action: 'me' });
            if (authRes.authenticated && authRes.user) {
                this.state.user = authRes.user;
                await this.bootstrapApp();
            } else {
                this.showLoginView();
            }
        } catch (error) {
            console.error('Initialization error:', error);
            this.showLoginView();
        }

        // Global listeners
        window.addEventListener('hashchange', () => this.handleRouting());
        window.addEventListener('auth:expired', () => {
            API.toast('Session expired. Please log in again.', 'warning');
            this.showLoginView();
        });
    },

    async bootstrapApp() {
        document.getElementById('login-screen').style.display = 'none';
        document.getElementById('app-container').style.display = 'flex';

        // Load branches for global selector
        await this.loadBranches();

        // Update user profile snippet in sidebar
        this.updateUserSnippet();

        // Start live 24h clock
        this.startLiveClock();

        // Start notifications polling
        this.startNotificationsPolling();

        // Route to initial hash or dashboard
        const initialRoute = window.location.hash.replace('#', '') || 'dashboard';
        this.navigate(initialRoute);
    },

    updateUserSnippet() {
        const u = this.state.user;
        if (!u) return;
        const initial = (u.first_name ? u.first_name[0] : (u.username ? u.username[0] : 'U')).toUpperCase();
        const avatarEl = document.getElementById('user-avatar-badge');
        if (avatarEl) {
            avatarEl.textContent = initial;
            avatarEl.style.backgroundColor = u.avatar_color || '#06b6d4';
        }
        const nameEl = document.getElementById('user-display-name');
        if (nameEl) nameEl.textContent = (u.first_name && u.last_name) ? `${u.first_name} ${u.last_name}` : u.username;
        const roleEl = document.getElementById('user-display-role');
        if (roleEl) roleEl.textContent = u.role_title || u.role.toUpperCase();
    },

    async loadBranches() {
        try {
            const res = await API.get('branches.php', { action: 'list' });
            if (res.success) {
                this.state.branches = res.branches;
                const select = document.getElementById('global-branch-select');
                if (select) {
                    select.innerHTML = '<option value="all">🏢 All Branches (Global 24/7)</option>';
                    res.branches.forEach(b => {
                        const opt = document.createElement('option');
                        opt.value = b.id;
                        opt.textContent = `${b.name} (${b.code})`;
                        select.appendChild(opt);
                    });
                    select.value = this.state.activeBranchId;
                    select.onchange = (e) => {
                        this.state.activeBranchId = e.target.value;
                        API.toast(`Filtering operations by ${select.options[select.selectedIndex].text}`, 'info');
                        this.refreshCurrentRoute();
                    };
                }
            }
        } catch (e) {
            console.error('Failed to load branches', e);
        }
    },

    startLiveClock() {
        if (this.state.clockInterval) clearInterval(this.state.clockInterval);

        const updateClock = () => {
            const now = new Date();
            const hours = String(now.getHours()).padStart(2, '0');
            const mins = String(now.getMinutes()).padStart(2, '0');
            const secs = String(now.getSeconds()).padStart(2, '0');
            const timeStr = `${hours}:${mins}:${secs}`;

            const clockDigitsEl = document.getElementById('live-clock-digits');
            if (clockDigitsEl) clockDigitsEl.textContent = timeStr;

            // Shift Phase calculation
            const h = now.getHours();
            let phase = 'morning';
            let phaseText = 'Morning Shift';
            let phaseClass = 'phase-morning';

            if (h >= 22 || h < 6) {
                phase = 'night';
                phaseText = '🌙 Overnight Graveyard Shift (22:00–06:00)';
                phaseClass = 'phase-night';
            } else if (h >= 14 && h < 22) {
                phase = 'afternoon';
                phaseText = '☀️ Afternoon / Swing Shift (14:00–22:00)';
                phaseClass = 'phase-afternoon';
            } else {
                phase = 'morning';
                phaseText = '🌅 Morning Shift (06:00–14:00)';
                phaseClass = 'phase-morning';
            }

            const phaseEl = document.getElementById('live-clock-phase');
            if (phaseEl) {
                phaseEl.className = `clock-phase-badge ${phaseClass}`;
                phaseEl.textContent = phaseText;
            }
        };

        updateClock();
        this.state.clockInterval = setInterval(updateClock, 1000);
    },

    async startNotificationsPolling() {
        const checkNotifications = async () => {
            try {
                const res = await API.get('notifications.php', { action: 'list', branch_id: this.state.activeBranchId });
                if (res.success) {
                    this.state.unreadNotifications = res.unread_count || 0;
                    const badge = document.getElementById('notif-badge');
                    if (badge) {
                        badge.textContent = this.state.unreadNotifications;
                        badge.style.display = this.state.unreadNotifications > 0 ? 'flex' : 'none';
                    }
                }
            } catch (e) {
                // Silent catch on poll
            }
        };

        checkNotifications();
        if (this.state.notificationsInterval) clearInterval(this.state.notificationsInterval);
        this.state.notificationsInterval = setInterval(checkNotifications, 20000);
    },

    handleRouting() {
        const route = window.location.hash.replace('#', '') || 'dashboard';
        this.navigate(route);
    },

    navigate(route) {
        this.state.currentRoute = route;
        window.location.hash = route;

        // Update active sidebar link
        document.querySelectorAll('.nav-link').forEach(link => {
            const linkRoute = link.getAttribute('data-route');
            if (linkRoute === route) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });

        // Close mobile sidebar if open
        const sidebar = document.getElementById('sidebar');
        if (sidebar) sidebar.classList.remove('mobile-open');

        const container = document.getElementById('content-container');
        if (!container) return;

        // Route dispatcher
        switch (route) {
            case 'dashboard':
                this.renderDashboard(container);
                break;
            case 'employees':
                this.renderEmployees(container);
                break;
            case 'shifts':
                this.renderShifts(container);
                break;
            case 'attendance':
                this.renderAttendance(container);
                break;
            case 'tasks':
                this.renderTasks(container);
                break;
            case 'customer_service':
                this.renderCustomerService(container);
                break;
            case 'handover':
                this.renderHandover(container);
                break;
            case 'analytics':
                this.renderAnalytics(container);
                break;
            case 'reports':
                this.renderReports(container);
                break;
            case 'branches':
                this.renderBranches(container);
                break;
            case 'audit':
                this.renderAudit(container);
                break;
            case 'settings':
                this.renderSettings(container);
                break;
            default:
                this.renderDashboard(container);
        }
    },

    refreshCurrentRoute() {
        this.navigate(this.state.currentRoute);
    },

    // =========================================================================
    // Module 1: Operations Dashboard
    // =========================================================================
    async renderDashboard(container) {
        container.innerHTML = `
            <div style="display:flex; justify-content:center; align-items:center; min-height:300px;">
                <div class="pulse-badge-live">Connecting to 24/7 Real-Time Telemetry...</div>
            </div>
        `;

        try {
            const res = await API.get('dashboard.php', { branch_id: this.state.activeBranchId });
            if (!res.success) throw new Error(res.message);
            const data = res.data;
            this.state.dashboardData = data;

            const m = data.metrics;
            const handoverBanner = data.active_handover ? `
                <div class="handover-callout">
                    <div class="handover-callout-info">
                        <div class="handover-icon-glow">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>
                        </div>
                        <div>
                            <div class="handover-title">Operational Shift Handover In Progress</div>
                            <div class="handover-desc">${escapeHtml(data.active_handover.outgoing_supervisor)} is handing over the <b>${escapeHtml(data.active_handover.outgoing_shift_name)}</b> to incoming supervisor. Pending acknowledgment.</div>
                        </div>
                    </div>
                    <button class="btn btn-warning" onclick="App.navigate('handover')">Review & Sign Handover</button>
                </div>
            ` : '';

            container.innerHTML = `
                <!-- 24/7 Operations Pulse Hero -->
                <div class="ops-pulse-banner">
                    <div class="pulse-banner-content">
                        <div class="pulse-badge-live">
                            <span class="pulse-dot"></span> Live Continuous Operations Pulse
                        </div>
                        <div class="pulse-banner-title">${escapeHtml(data.active_shift_name)}</div>
                        <div class="pulse-banner-desc">
                            All operations synchronized across workforce, overnight transitions, customer desk and continuous facility readiness.
                        </div>
                    </div>
                    <div class="pulse-banner-stats">
                        <div class="pulse-stat-box">
                            <div class="pulse-stat-num" style="color:var(--brand-cyan);">${m.on_duty_count}</div>
                            <div class="pulse-stat-lbl">Active On Duty</div>
                        </div>
                        <div class="pulse-stat-box">
                            <div class="pulse-stat-num" style="color:var(--status-success);">${m.shift_coverage_pct}%</div>
                            <div class="pulse-stat-lbl">Shift Coverage</div>
                        </div>
                        <div class="pulse-stat-box">
                            <div class="pulse-stat-num" style="color:${m.critical_tickets_count > 0 ? 'var(--status-danger)' : 'var(--status-warning)'};">${m.open_tickets_count}</div>
                            <div class="pulse-stat-lbl">Active CS Tickets</div>
                        </div>
                    </div>
                </div>

                ${handoverBanner}

                <!-- KPI Metric Cards Grid -->
                <div class="stat-grid">
                    <div class="stat-card stat-cyan">
                        <div class="stat-info">
                            <span class="stat-label">Workforce On Duty</span>
                            <span class="stat-value">${m.on_duty_count}</span>
                            <span class="stat-sub" style="color:var(--brand-cyan);">● Clocked in right now</span>
                        </div>
                        <div class="stat-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                    </div>

                    <div class="stat-card stat-emerald">
                        <div class="stat-info">
                            <span class="stat-label">Shift Coverage</span>
                            <span class="stat-value">${m.active_shifts_today} / ${m.total_scheduled_today}</span>
                            <span class="stat-sub" style="color:var(--status-success);">✓ ${m.shift_coverage_pct}% scheduled staff active</span>
                        </div>
                        <div class="stat-icon" style="color:var(--status-success);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        </div>
                    </div>

                    <div class="stat-card stat-amber">
                        <div class="stat-info">
                            <span class="stat-label">Operational Tasks</span>
                            <span class="stat-value">${m.open_tasks_count}</span>
                            <span class="stat-sub">${m.overdue_tasks_count > 0 ? `<b style="color:var(--status-danger);">⚠ ${m.overdue_tasks_count} overdue</b>` : '✓ All tasks on schedule'}</span>
                        </div>
                        <div class="stat-icon" style="color:var(--status-warning);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                        </div>
                    </div>

                    <div class="stat-card stat-rose">
                        <div class="stat-info">
                            <span class="stat-label">Customer Support Tickets</span>
                            <span class="stat-value">${m.open_tickets_count}</span>
                            <span class="stat-sub">${m.critical_tickets_count > 0 ? `<b style="color:var(--status-danger);">🚨 ${m.critical_tickets_count} Critical 24/7</b>` : 'Active 24/7 desk'}</span>
                        </div>
                        <div class="stat-icon" style="color:var(--status-danger);">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        </div>
                    </div>
                </div>

                <!-- Two-Column Operational Section -->
                <div class="dashboard-grid">
                    <!-- Left Column: Who's On Duty Right Now -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <span class="pulse-dot"></span> Live "Who's On Duty Right Now" Board
                            </div>
                            <div style="display:flex; gap:8px;">
                                <button class="btn btn-secondary btn-sm" onclick="App.openKioskModal()">⏱️ Kiosk Clock-In</button>
                                <button class="btn btn-secondary btn-sm" onclick="App.navigate('attendance')">View All Attendance</button>
                            </div>
                        </div>

                        <div class="duty-board-list" id="duty-board-container">
                            ${data.on_duty_employees && data.on_duty_employees.length > 0 ? data.on_duty_employees.map(emp => `
                                <div class="duty-card">
                                    <div class="duty-emp-meta">
                                        <div class="duty-avatar" style="background-color:${emp.avatar_color || '#06b6d4'};">
                                            ${(emp.first_name[0] || 'U')}${(emp.last_name[0] || '')}
                                            <span class="duty-status-dot ${emp.current_break ? 'on-break' : ''}"></span>
                                        </div>
                                        <div class="duty-emp-details">
                                            <div class="duty-emp-name">
                                                ${escapeHtml(emp.first_name)} ${escapeHtml(emp.last_name)}
                                                ${emp.current_break ? `<span class="badge badge-warning">On ${escapeHtml(emp.current_break.replace('_', ' '))}</span>` : ''}
                                            </div>
                                            <div class="duty-emp-role">${escapeHtml(emp.role_title)} • <span class="font-mono">${escapeHtml(emp.employee_code)}</span></div>
                                            <div class="duty-emp-branch">${escapeHtml(emp.branch_name)} • ${escapeHtml(emp.department_name || 'Frontline')}</div>
                                        </div>
                                    </div>
                                    <div class="duty-timing-meta">
                                        <span class="duty-shift-pill" style="background:rgba(6, 182, 212, 0.15); color:var(--brand-cyan);">
                                            ${escapeHtml(emp.shift_name || 'Active Shift')}
                                        </span>
                                        <div class="duty-time-worked">${emp.time_worked} worked</div>
                                        <div class="duty-clock-in-time">In at ${emp.clock_in_formatted}</div>
                                    </div>
                                </div>
                            `).join('') : `
                                <div style="text-align:center; padding:2rem; color:var(--text-muted);">
                                    No staff currently clocked in for this branch filter. Use the Kiosk to clock in.
                                </div>
                            `}
                        </div>
                    </div>

                    <!-- Right Column: Today's Shift Roster Coverage Timeline -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                24-Hour Shift Coverage Timeline
                            </div>
                            <button class="btn btn-secondary btn-sm" onclick="App.navigate('shifts')">View Schedule</button>
                        </div>

                        <div class="roster-timeline">
                            ${data.shift_coverage.map(st => {
                                const sched = parseInt(st.scheduled_count) || 0;
                                const minStaff = parseInt(st.min_staff_required) || 3;
                                const isSatisfied = sched >= minStaff;
                                const pct = Math.min(100, Math.round((sched / minStaff) * 100));
                                return `
                                    <div class="roster-shift-row">
                                        <div class="roster-row-header">
                                            <div class="roster-row-title">
                                                <span style="width:12px; height:12px; border-radius:50%; background:${st.color};"></span>
                                                ${escapeHtml(st.name)}
                                                ${st.is_overnight ? '<span class="badge badge-overnight">🌙 Overnight</span>' : ''}
                                            </div>
                                            <div class="roster-row-time">${st.start_time.substring(0, 5)} - ${st.end_time.substring(0, 5)}</div>
                                        </div>
                                        <div class="roster-progress-bar-bg">
                                            <div class="roster-progress-fill" style="width:${pct}%; background:${st.color};"></div>
                                        </div>
                                        <div class="roster-row-footer">
                                            <span>Staffing: <b>${sched}</b> / ${minStaff} minimum</span>
                                            <span style="color:${isSatisfied ? 'var(--status-success)' : 'var(--status-warning)'}; font-weight:700;">
                                                ${isSatisfied ? '✓ Fully Staffed' : `⚠ Need ${minStaff - sched} more`}
                                            </span>
                                        </div>
                                    </div>
                                `;
                            }).join('')}
                        </div>
                    </div>
                </div>

                <!-- 24-Hour Operations Pulse Chart -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="card-title">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                            24-Hour Continuous Operations Activity Pulse (00:00 to 23:00)
                        </div>
                        <div class="chart-legend">
                            <div class="legend-item"><span class="legend-color-dot" style="background:#06b6d4;"></span> Attendance Clock-ins</div>
                            <div class="legend-item"><span class="legend-color-dot" style="background:#10b981;"></span> Tasks Completed</div>
                            <div class="legend-item"><span class="legend-color-dot" style="background:#f59e0b;"></span> Customer Tickets</div>
                        </div>
                    </div>
                    <div id="pulse-chart-container" class="svg-chart-container"></div>
                </div>

                <!-- Operations Audit Feed -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                            Recent Operational Event Log
                        </div>
                        <button class="btn btn-secondary btn-sm" onclick="App.navigate('audit')">Full Audit Log</button>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Timestamp</th>
                                    <th>Actor / Operator</th>
                                    <th>Action Type</th>
                                    <th>Entity</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${data.recent_feed.map(f => `
                                    <tr>
                                        <td class="font-mono" style="font-size:0.8rem; color:var(--text-muted);">${f.created_at}</td>
                                        <td><b>${escapeHtml(f.username || 'System')}</b> <span class="badge badge-info" style="font-size:0.65rem;">${f.role || 'sys'}</span></td>
                                        <td><span class="badge badge-night">${escapeHtml(f.action)}</span></td>
                                        <td>${escapeHtml(f.entity_type)}</td>
                                        <td style="font-size:0.82rem; color:var(--text-secondary); max-width:320px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                            ${escapeHtml(f.details || '')}
                                        </td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;

            // Render interactive SVG pulse chart
            Charts.render24HourPulseChart('pulse-chart-container', data.activity_pulse);

        } catch (err) {
            container.innerHTML = `
                <div class="card" style="text-align:center; padding:3rem;">
                    <h3 style="color:var(--status-danger);">Failed to load Operations Dashboard</h3>
                    <p style="color:var(--text-secondary); margin:1rem 0;">${escapeHtml(err.message)}</p>
                    <button class="btn btn-primary" onclick="App.renderDashboard(document.getElementById('content-container'))">Retry Connection</button>
                </div>
            `;
        }
    },

    // =========================================================================
    // Module 2: Employee Management
    // =========================================================================
    async renderEmployees(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>👥 Employee Management</h1>
                    <p>Continuous workforce directory, shift allocation readiness, and night specialist scheduling.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="App.openEmployeeModal()">+ Add New Employee</button>
                </div>
            </div>

            <!-- Search and Filter Bar -->
            <div class="card" style="margin-bottom:1.5rem; padding:1rem;">
                <div style="display:flex; gap:1rem; flex-wrap:wrap; align-items:center;">
                    <div style="flex:1; min-width:240px;">
                        <input type="text" id="emp-search-input" class="form-control" placeholder="Search by name, employee code, role, email..." oninput="App.filterEmployees()">
                    </div>
                    <div>
                        <select id="emp-shift-pref-select" class="form-control" onchange="App.filterEmployees()">
                            <option value="all">Shift Preference: All</option>
                            <option value="night">🌙 Night Specialist</option>
                            <option value="morning">🌅 Morning Shift</option>
                            <option value="afternoon">☀️ Afternoon Shift</option>
                            <option value="any">🔄 Any 24/7 Shift</option>
                        </select>
                    </div>
                    <div>
                        <select id="emp-status-select" class="form-control" onchange="App.filterEmployees()">
                            <option value="all">Status: All</option>
                            <option value="active" selected>Active Only</option>
                            <option value="inactive">Inactive</option>
                            <option value="on_leave">On Leave</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="data-table" id="employees-table">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Employee Name</th>
                                <th>Role & Department</th>
                                <th>Branch</th>
                                <th>Shift Preference</th>
                                <th>Hourly Rate</th>
                                <th>Duty Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="employees-tbody">
                            <tr><td colspan="8" style="text-align:center; padding:2rem;">Loading employees...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        this.filterEmployees();
    },

    async filterEmployees() {
        const search = document.getElementById('emp-search-input')?.value || '';
        const shiftPref = document.getElementById('emp-shift-pref-select')?.value || 'all';
        const status = document.getElementById('emp-status-select')?.value || 'all';
        const tbody = document.getElementById('employees-tbody');
        if (!tbody) return;

        try {
            const res = await API.get('employees.php', {
                search,
                shift_preference: shiftPref,
                status,
                branch_id: this.state.activeBranchId
            });

            if (!res.success || !res.employees.length) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:2rem; color:var(--text-muted);">No employees found matching filters.</td></tr>`;
                return;
            }

            tbody.innerHTML = res.employees.map(e => `
                <tr>
                    <td class="font-mono"><b>${escapeHtml(e.employee_code)}</b></td>
                    <td>
                        <div style="display:flex; align-items:center; gap:10px;">
                            <div class="avatar-circle" style="background-color:${e.avatar_color || '#06b6d4'}; width:32px; height:32px; font-size:0.8rem;">
                                ${(e.first_name[0] || 'U')}${(e.last_name[0] || '')}
                            </div>
                            <div>
                                <div style="font-weight:700;">${escapeHtml(e.first_name)} ${escapeHtml(e.last_name)}</div>
                                <div style="font-size:0.75rem; color:var(--text-muted);">${escapeHtml(e.email)}</div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div><b>${escapeHtml(e.role_title)}</b></div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">${escapeHtml(e.department_name || 'Frontline')}</div>
                    </td>
                    <td>${escapeHtml(e.branch_name)}</td>
                    <td>
                        <span class="badge ${e.shift_preference === 'night' ? 'badge-night' : 'badge-info'}">
                            ${e.shift_preference === 'night' ? '🌙 Night Specialist' : escapeHtml(e.shift_preference.toUpperCase())}
                        </span>
                    </td>
                    <td class="font-mono">$${Number(e.hourly_rate).toFixed(2)}/h</td>
                    <td>
                        ${e.live_duty_status ? `
                            <span class="badge badge-success"><span class="pulse-dot"></span> ON DUTY</span>
                        ` : `
                            <span class="badge ${e.status === 'active' ? 'badge-info' : 'badge-danger'}">${e.status.toUpperCase()}</span>
                        `}
                    </td>
                    <td>
                        <div style="display:flex; gap:6px;">
                            <button class="btn btn-secondary btn-sm" onclick="App.viewEmployeeDetails(${e.id})">Profile</button>
                            <button class="btn btn-secondary btn-sm" onclick="App.openEmployeeModal(${e.id})">Edit</button>
                            <button class="btn ${e.status === 'active' ? 'btn-danger' : 'btn-success'} btn-sm" onclick="App.toggleEmployeeStatus(${e.id})">
                                ${e.status === 'active' ? 'Deactivate' : 'Activate'}
                            </button>
                        </div>
                    </td>
                </tr>
            `).join('');
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:2rem; color:var(--status-danger);">Failed to load employees: ${escapeHtml(e.message)}</td></tr>`;
        }
    },

    async openEmployeeModal(employeeId = null) {
        let emp = null;
        if (employeeId) {
            const res = await API.get('employees.php', { id: employeeId });
            if (res.success) emp = res.employee;
        }

        const modalHtml = `
            <div class="modal-backdrop active" id="employee-modal">
                <div class="modal-dialog">
                    <div class="modal-header">
                        <div class="modal-title">${emp ? 'Edit Employee Profile' : 'Enroll New 24/7 Workforce Member'}</div>
                        <button class="modal-close" onclick="App.closeModal('employee-modal')">&times;</button>
                    </div>
                    <form id="employee-form" onsubmit="App.saveEmployee(event, ${emp ? emp.id : 'null'})">
                        <div class="modal-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">First Name *</label>
                                    <input type="text" name="first_name" class="form-control" required value="${emp ? escapeHtml(emp.first_name) : ''}">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Last Name *</label>
                                    <input type="text" name="last_name" class="form-control" required value="${emp ? escapeHtml(emp.last_name) : ''}">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Email Address *</label>
                                    <input type="email" name="email" class="form-control" required value="${emp ? escapeHtml(emp.email) : ''}">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Phone Number</label>
                                    <input type="text" name="phone" class="form-control" value="${emp ? escapeHtml(emp.phone || '') : ''}">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Role / Job Title *</label>
                                    <input type="text" name="role_title" class="form-control" required placeholder="e.g. Overnight Dispatch Supervisor" value="${emp ? escapeHtml(emp.role_title) : ''}">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Branch Assignment *</label>
                                    <select name="branch_id" class="form-control">
                                        ${this.state.branches.map(b => `
                                            <option value="${b.id}" ${emp && emp.branch_id == b.id ? 'selected' : ''}>${escapeHtml(b.name)}</option>
                                        `).join('')}
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Shift Preference *</label>
                                    <select name="shift_preference" class="form-control">
                                        <option value="any" ${emp && emp.shift_preference === 'any' ? 'selected' : ''}>🔄 Any 24/7 Shift</option>
                                        <option value="night" ${emp && emp.shift_preference === 'night' ? 'selected' : ''}>🌙 Night Specialist (22:00 - 06:00)</option>
                                        <option value="morning" ${emp && emp.shift_preference === 'morning' ? 'selected' : ''}>🌅 Morning Shift (06:00 - 14:00)</option>
                                        <option value="afternoon" ${emp && emp.shift_preference === 'afternoon' ? 'selected' : ''}>☀️ Afternoon Shift (14:00 - 22:00)</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Hourly Base Rate (ZMW) *</label>
                                    <input type="number" step="0.5" name="hourly_rate" class="form-control" required value="${emp ? emp.hourly_rate : '85.00'}">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Employment Type</label>
                                    <select name="employment_type" class="form-control">
                                        <option value="full_time" ${emp && emp.employment_type === 'full_time' ? 'selected' : ''}>Full Time</option>
                                        <option value="part_time" ${emp && emp.employment_type === 'part_time' ? 'selected' : ''}>Part Time</option>
                                        <option value="night_specialist" ${emp && emp.employment_type === 'night_specialist' ? 'selected' : ''}>Night Specialist (24/7 Premium)</option>
                                        <option value="contractor" ${emp && emp.employment_type === 'contractor' ? 'selected' : ''}>Contractor</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Emergency Contact Info</label>
                                    <input type="text" name="emergency_contact" class="form-control" placeholder="Contact Name & Phone" value="${emp ? escapeHtml(emp.emergency_contact || '') : ''}">
                                </div>
                            </div>

                            ${!emp ? `
                                <div style="background:var(--bg-surface); padding:1rem; border-radius:var(--radius-md); border:1px solid var(--border-subtle); margin-top:0.5rem;">
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                        <input type="checkbox" name="create_user_account" value="1" checked>
                                        <span style="font-weight:700; font-size:0.88rem;">Automatically generate 24/7 Portal User Account</span>
                                    </label>
                                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                                        Employee can immediately log in with their email and default temporary password <b>admin123</b>.
                                    </div>
                                </div>
                            ` : ''}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="App.closeModal('employee-modal')">Cancel</button>
                            <button type="submit" class="btn btn-primary">${emp ? 'Save Changes' : 'Enroll Employee'}</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    async saveEmployee(event, id) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);
        const data = Object.fromEntries(formData.entries());

        try {
            let res;
            if (id) {
                data.id = id;
                res = await API.post('employees.php?action=update', data);
            } else {
                res = await API.post('employees.php', data);
            }

            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('employee-modal');
                App.filterEmployees();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (err) {
            API.toast(err.message, 'error');
        }
    },

    async toggleEmployeeStatus(id) {
        if (!confirm('Are you sure you want to change this employee status?')) return;
        try {
            const res = await API.post('employees.php?action=toggle_status', { id });
            if (res.success) {
                API.toast(res.message, 'success');
                this.filterEmployees();
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async viewEmployeeDetails(id) {
        try {
            const res = await API.get('employees.php', { id });
            if (!res.success) throw new Error(res.message);
            const emp = res.employee;

            const modalHtml = `
                <div class="modal-backdrop active" id="emp-details-modal">
                    <div class="modal-dialog" style="max-width:700px;">
                        <div class="modal-header">
                            <div class="modal-title">${escapeHtml(emp.first_name)} ${escapeHtml(emp.last_name)} (${escapeHtml(emp.employee_code)})</div>
                            <button class="modal-close" onclick="App.closeModal('emp-details-modal')">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div style="display:flex; align-items:center; gap:1.25rem; padding-bottom:1rem; border-bottom:1px solid var(--border-subtle);">
                                <div class="avatar-circle" style="width:64px; height:64px; font-size:1.5rem; background:${emp.avatar_color || '#06b6d4'};">
                                    ${emp.first_name[0]}${emp.last_name[0]}
                                </div>
                                <div>
                                    <h3 style="font-size:1.25rem;">${escapeHtml(emp.first_name)} ${escapeHtml(emp.last_name)}</h3>
                                    <div style="color:var(--brand-cyan); font-weight:700;">${escapeHtml(emp.role_title)}</div>
                                    <div style="font-size:0.8rem; color:var(--text-muted);">${escapeHtml(emp.branch_name)} • ${escapeHtml(emp.department_name)}</div>
                                </div>
                                <div style="margin-left:auto; text-align:right;">
                                    <span class="badge ${emp.status === 'active' ? 'badge-success' : 'badge-danger'}">${emp.status.toUpperCase()}</span>
                                    <div style="font-family:'JetBrains Mono'; font-size:1.1rem; font-weight:800; margin-top:6px;">$${Number(emp.hourly_rate).toFixed(2)}/h</div>
                                </div>
                            </div>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:1rem; margin-top:0.5rem; font-size:0.88rem;">
                                <div><b>Email:</b> ${escapeHtml(emp.email)}</div>
                                <div><b>Phone:</b> ${escapeHtml(emp.phone || 'N/A')}</div>
                                <div><b>Shift Preference:</b> ${escapeHtml(emp.shift_preference.toUpperCase())}</div>
                                <div><b>Employment Type:</b> ${escapeHtml(emp.employment_type.replace('_', ' ').toUpperCase())}</div>
                                <div><b>Emergency Contact:</b> ${escapeHtml(emp.emergency_contact || 'None')}</div>
                                <div><b>Hire Date:</b> ${emp.hire_date}</div>
                            </div>

                            <div style="margin-top:1.5rem;">
                                <h4 style="font-size:0.95rem; font-weight:800; margin-bottom:0.75rem; color:var(--text-primary);">Recent 24/7 Shift Assignments</h4>
                                <div class="table-responsive">
                                    <table class="data-table" style="font-size:0.8rem;">
                                        <thead><tr><th>Date</th><th>Shift</th><th>Hours</th><th>Status</th></tr></thead>
                                        <tbody>
                                            ${emp.recent_shifts && emp.recent_shifts.length > 0 ? emp.recent_shifts.map(s => `
                                                <tr>
                                                    <td>${s.shift_date}</td>
                                                    <td><b>${escapeHtml(s.shift_name)}</b> ${s.is_overnight ? '🌙' : ''}</td>
                                                    <td class="font-mono">${s.start_time.substring(0, 5)} - ${s.end_time.substring(0, 5)}</td>
                                                    <td><span class="badge badge-info">${s.status.toUpperCase()}</span></td>
                                                </tr>
                                            `).join('') : '<tr><td colspan="4" style="text-align:center;">No recent shifts</td></tr>'}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" onclick="App.closeModal('emp-details-modal')">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    // =========================================================================
    // Module 3: Shift Scheduling & 24/7 Roster
    // =========================================================================
    async renderShifts(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>📅 Shift Scheduling & 24-Hour Roster</h1>
                    <p>Continuous around-the-clock shift assignments with automated overnight date resolution and conflict protection.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-secondary" onclick="App.openShiftTemplatesModal()">⚙️ Shift Templates</button>
                    <button class="btn btn-primary" onclick="App.openScheduleShiftModal()">+ Schedule Shift</button>
                </div>
            </div>

            <!-- View & Filter Controls -->
            <div class="card" style="margin-bottom:1.5rem; padding:1rem;">
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
                    <div style="display:flex; gap:0.75rem; align-items:center;">
                        <label class="form-label" style="margin:0;">Date Range:</label>
                        <input type="date" id="roster-start-date" class="form-control" style="width:auto;" value="${this.formatDate(new Date(Date.now() - 2 * 864e5))}">
                        <span>to</span>
                        <input type="date" id="roster-end-date" class="form-control" style="width:auto;" value="${this.formatDate(new Date(Date.now() + 5 * 864e5))}">
                        <button class="btn btn-secondary btn-sm" onclick="App.loadRosterData()">Filter</button>
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button class="btn btn-secondary btn-sm" onclick="App.openSwapsModal()">🔄 Shift Swap Requests</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="data-table" id="shifts-roster-table">
                        <thead>
                            <tr>
                                <th>Shift Date</th>
                                <th>Shift Template</th>
                                <th>Operational Hours</th>
                                <th>Assigned Employee</th>
                                <th>Branch</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="shifts-tbody">
                            <tr><td colspan="7" style="text-align:center; padding:2rem;">Loading schedules...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        this.loadRosterData();
    },

    async loadRosterData() {
        const startDate = document.getElementById('roster-start-date')?.value || this.formatDate(new Date(Date.now() - 2 * 864e5));
        const endDate = document.getElementById('roster-end-date')?.value || this.formatDate(new Date(Date.now() + 5 * 864e5));
        const tbody = document.getElementById('shifts-tbody');
        if (!tbody) return;

        try {
            const res = await API.get('shifts.php', {
                start_date: startDate,
                end_date: endDate,
                branch_id: this.state.activeBranchId
            });

            if (!res.success || !res.assignments.length) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:2rem; color:var(--text-muted);">No shifts scheduled in this timeframe. Click "+ Schedule Shift" to allocate workforce.</td></tr>`;
                return;
            }

            tbody.innerHTML = res.assignments.map(s => `
                <tr>
                    <td class="font-mono"><b>${s.shift_date}</b></td>
                    <td>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <span style="width:10px; height:10px; border-radius:50%; background:${s.shift_color || '#06b6d4'};"></span>
                            <b>${escapeHtml(s.shift_name)}</b>
                            ${s.is_overnight ? '<span class="badge badge-overnight">🌙 Overnight</span>' : ''}
                        </div>
                    </td>
                    <td class="font-mono">
                        ${s.start_time.substring(0, 5)} → ${s.end_time.substring(0, 5)}
                        <span style="color:var(--text-muted); font-size:0.75rem;">(${s.duration_hours}h)</span>
                    </td>
                    <td>
                        <div style="display:flex; align-items:center; gap:8px;">
                            <div class="avatar-circle" style="width:28px; height:28px; font-size:0.75rem; background:${s.avatar_color || '#06b6d4'};">
                                ${s.first_name[0]}${s.last_name[0]}
                            </div>
                            <div>
                                <b>${escapeHtml(s.first_name)} ${escapeHtml(s.last_name)}</b>
                                <div style="font-size:0.72rem; color:var(--text-muted);">${escapeHtml(s.employee_code)} • ${escapeHtml(s.role_title)}</div>
                            </div>
                        </div>
                    </td>
                    <td>${escapeHtml(s.branch_name)}</td>
                    <td>
                        <span class="badge ${s.status === 'completed' ? 'badge-success' : (s.status === 'in_progress' ? 'badge-warning' : 'badge-info')}">
                            ${s.status.toUpperCase()}
                        </span>
                    </td>
                    <td>
                        <button class="btn btn-danger btn-sm" onclick="App.deleteShiftAssignment(${s.id})">Cancel</button>
                    </td>
                </tr>
            `).join('');
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:2rem; color:var(--status-danger);">Failed to load shift assignments: ${escapeHtml(e.message)}</td></tr>`;
        }
    },

    async openScheduleShiftModal() {
        // Fetch active templates & employees
        const [templatesRes, empRes] = await Promise.all([
            API.get('shifts.php', { action: 'templates' }),
            API.get('employees.php', { status: 'active', branch_id: this.state.activeBranchId })
        ]);

        const templates = templatesRes.templates || [];
        const employees = empRes.employees || [];

        const modalHtml = `
            <div class="modal-backdrop active" id="schedule-shift-modal">
                <div class="modal-dialog">
                    <div class="modal-header">
                        <div class="modal-title">Schedule 24/7 Operational Shift</div>
                        <button class="modal-close" onclick="App.closeModal('schedule-shift-modal')">&times;</button>
                    </div>
                    <form onsubmit="App.saveShiftAssignment(event)">
                        <div class="modal-body">
                            <div class="form-group">
                                <label class="form-label">Shift Date *</label>
                                <input type="date" name="shift_date" class="form-control" required value="${this.formatDate(new Date())}">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Shift Template *</label>
                                <select name="shift_template_id" class="form-control" required onchange="App.onShiftTemplateSelected(this)">
                                    <option value="">Select shift template...</option>
                                    ${templates.map(t => `
                                        <option value="${t.id}" data-overnight="${t.is_overnight}" data-start="${t.start_time}" data-end="${t.end_time}">
                                            ${escapeHtml(t.name)} (${t.start_time.substring(0, 5)} - ${t.end_time.substring(0, 5)}) ${t.is_overnight ? '🌙 Overnight' : ''}
                                        </option>
                                    `).join('')}
                                </select>
                            </div>

                            <div id="shift-overnight-notice" style="display:none; background:rgba(139,92,246,0.15); border:1px solid rgba(139,92,246,0.3); padding:0.75rem; border-radius:var(--radius-md); font-size:0.82rem; color:#c084fc;">
                                🌙 <b>Continuous Overnight Shift Notice:</b> This shift starts at night and concludes on the following morning. Automated calculation will correctly attribute hours across midnight without conflict.
                            </div>

                            <div class="form-group">
                                <label class="form-label">Assign Employee *</label>
                                <select name="employee_id" class="form-control" required>
                                    <option value="">Select active employee...</option>
                                    ${employees.map(e => `
                                        <option value="${e.id}">
                                            ${escapeHtml(e.first_name)} ${escapeHtml(e.last_name)} (${e.employee_code}) - ${escapeHtml(e.role_title)} [Pref: ${e.shift_preference.toUpperCase()}]
                                        </option>
                                    `).join('')}
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Branch Assignment *</label>
                                <select name="branch_id" class="form-control" required>
                                    ${this.state.branches.map(b => `
                                        <option value="${b.id}">${escapeHtml(b.name)}</option>
                                    `).join('')}
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Operational Notes</label>
                                <textarea name="notes" class="form-control" rows="2" placeholder="e.g. Lead duty officer, primary radio channel monitor"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="App.closeModal('schedule-shift-modal')">Cancel</button>
                            <button type="submit" class="btn btn-primary">Confirm & Allocate</button>
                        </div>
                    </form>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    onShiftTemplateSelected(select) {
        const opt = select.options[select.selectedIndex];
        const isOvernight = opt.dataset.overnight === '1';
        const notice = document.getElementById('shift-overnight-notice');
        if (notice) notice.style.display = isOvernight ? 'block' : 'none';
    },

    async saveShiftAssignment(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.target).entries());

        try {
            const res = await API.post('shifts.php?action=create_assignment', data);
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('schedule-shift-modal');
                App.loadRosterData();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async deleteShiftAssignment(id) {
        if (!confirm('Are you sure you want to cancel this shift assignment?')) return;
        try {
            const res = await API.post('shifts.php?action=delete_assignment', { id });
            if (res.success) {
                API.toast(res.message, 'success');
                this.loadRosterData();
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async openShiftTemplatesModal() {
        const res = await API.get('shifts.php', { action: 'templates' });
        const templates = res.templates || [];

        const modalHtml = `
            <div class="modal-backdrop active" id="templates-modal">
                <div class="modal-dialog" style="max-width:750px;">
                    <div class="modal-header">
                        <div class="modal-title">Master Shift Templates</div>
                        <button class="modal-close" onclick="App.closeModal('templates-modal')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Name & Code</th>
                                        <th>Times</th>
                                        <th>Overnight</th>
                                        <th>Duration</th>
                                        <th>Min Staff</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${templates.map(t => `
                                        <tr>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:8px;">
                                                    <span style="width:12px; height:12px; border-radius:50%; background:${t.color};"></span>
                                                    <b>${escapeHtml(t.name)}</b>
                                                    <span class="badge badge-info font-mono">${escapeHtml(t.shift_code)}</span>
                                                </div>
                                            </td>
                                            <td class="font-mono">${t.start_time.substring(0, 5)} - ${t.end_time.substring(0, 5)}</td>
                                            <td>${t.is_overnight ? '<span class="badge badge-overnight">🌙 Yes (+1 day)</span>' : '<span style="color:var(--text-muted);">No</span>'}</td>
                                            <td class="font-mono"><b>${t.duration_hours}h</b></td>
                                            <td><b>${t.min_staff_required}</b> required</td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" onclick="App.closeModal('templates-modal')">Close</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    async openSwapsModal() {
        const res = await API.get('shifts.php', { action: 'swaps' });
        const swaps = res.swaps || [];

        const modalHtml = `
            <div class="modal-backdrop active" id="swaps-modal">
                <div class="modal-dialog" style="max-width:700px;">
                    <div class="modal-header">
                        <div class="modal-title">Shift Swap Requests</div>
                        <button class="modal-close" onclick="App.closeModal('swaps-modal')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Shift Details</th>
                                        <th>Requester</th>
                                        <th>Proposed Swap</th>
                                        <th>Reason</th>
                                        <th>Status</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${swaps.length > 0 ? swaps.map(s => `
                                        <tr>
                                            <td><b>${escapeHtml(s.shift_name)}</b><div class="font-mono" style="font-size:0.75rem;">${s.shift_date}</div></td>
                                            <td>${escapeHtml(s.req_first)} ${escapeHtml(s.req_last)}</td>
                                            <td>${escapeHtml(s.tar_first)} ${escapeHtml(s.tar_last)}</td>
                                            <td style="font-size:0.8rem;">${escapeHtml(s.reason || 'None provided')}</td>
                                            <td><span class="badge ${s.status === 'approved' ? 'badge-success' : (s.status === 'rejected' ? 'badge-danger' : 'badge-warning')}">${s.status.toUpperCase()}</span></td>
                                            <td>
                                                ${s.status === 'pending' ? `
                                                    <div style="display:flex; gap:4px;">
                                                        <button class="btn btn-success btn-sm" onclick="App.reviewSwap(${s.id}, 'approved')">Approve</button>
                                                        <button class="btn btn-danger btn-sm" onclick="App.reviewSwap(${s.id}, 'rejected')">Reject</button>
                                                    </div>
                                                ` : '-'}
                                            </td>
                                        </tr>
                                    `).join('') : '<tr><td colspan="6" style="text-align:center; padding:1.5rem; color:var(--text-muted);">No shift swap requests currently logged.</td></tr>'}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" onclick="App.closeModal('swaps-modal')">Close</button>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    async reviewSwap(swapId, status) {
        try {
            const res = await API.post('shifts.php?action=swaps', {
                sub_action: 'review',
                swap_id: swapId,
                status
            });
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('swaps-modal');
                App.openSwapsModal();
                App.loadRosterData();
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    // =========================================================================
    // Module 4: Attendance & Kiosk Terminal
    // =========================================================================
    async renderAttendance(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>⏱️ Attendance Tracking & Duty Operations</h1>
                    <p>Continuous clock-in/out tracking with grace period calculations, night shift hours, and live break management.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="App.openKioskModal()">⏱️ Open Kiosk Terminal</button>
                </div>
            </div>

            <!-- Live Duty Cards -->
            <div class="card" style="margin-bottom:1.5rem;">
                <div class="card-header">
                    <div class="card-title">
                        <span class="pulse-dot"></span> Live Clocked-In Personnel (Active Operations)
                    </div>
                    <button class="btn btn-secondary btn-sm" onclick="App.loadAttendanceData()">🔄 Refresh</button>
                </div>
                <div id="live-duty-cards-grid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(310px, 1fr)); gap:1rem;">
                    Loading active personnel...
                </div>
            </div>

            <!-- Attendance History Table -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title">Attendance & Shift Records</div>
                    <div style="display:flex; gap:0.5rem; align-items:center;">
                        <input type="date" id="att-start-date" class="form-control" style="width:auto;" value="${this.formatDate(new Date(Date.now() - 7 * 864e5))}">
                        <span>to</span>
                        <input type="date" id="att-end-date" class="form-control" style="width:auto;" value="${this.formatDate(new Date())}">
                        <button class="btn btn-secondary btn-sm" onclick="App.loadAttendanceData()">Filter</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Employee</th>
                                <th>Shift</th>
                                <th>Clock In</th>
                                <th>Clock Out</th>
                                <th>Regular</th>
                                <th>Overtime</th>
                                <th>Night Hours</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody id="att-history-tbody">
                            <tr><td colspan="9" style="text-align:center; padding:2rem;">Loading attendance records...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        this.loadAttendanceData();
    },

    async loadAttendanceData() {
        const dutyGrid = document.getElementById('live-duty-cards-grid');
        const historyTbody = document.getElementById('att-history-tbody');
        const startDate = document.getElementById('att-start-date')?.value || this.formatDate(new Date(Date.now() - 7 * 864e5));
        const endDate = document.getElementById('att-end-date')?.value || this.formatDate(new Date());

        try {
            const [dutyRes, histRes] = await Promise.all([
                API.get('attendance.php', { action: 'live_duty', branch_id: this.state.activeBranchId }),
                API.get('attendance.php', { action: 'history', start_date: startDate, end_date: endDate, branch_id: this.state.activeBranchId })
            ]);

            // Render live duty cards
            const duty = dutyRes.on_duty || [];
            if (dutyGrid) {
                if (!duty.length) {
                    dutyGrid.innerHTML = `<div style="grid-column:1/-1; text-align:center; padding:1.5rem; color:var(--text-muted);">No staff currently on duty. Use the Kiosk terminal to clock in.</div>`;
                } else {
                    dutyGrid.innerHTML = duty.map(d => `
                        <div class="duty-card" style="padding:1rem;">
                            <div class="duty-emp-meta">
                                <div class="duty-avatar" style="background:${d.avatar_color || '#06b6d4'};">
                                    ${d.first_name[0]}${d.last_name[0]}
                                    <span class="duty-status-dot ${d.active_break_id ? 'on-break' : ''}"></span>
                                </div>
                                <div class="duty-emp-details">
                                    <div class="duty-emp-name">${escapeHtml(d.first_name)} ${escapeHtml(d.last_name)}</div>
                                    <div class="duty-emp-role">${escapeHtml(d.role_title)} • <span class="font-mono">${escapeHtml(d.employee_code)}</span></div>
                                    <div style="font-size:0.75rem; color:var(--brand-cyan); margin-top:2px;">
                                        ⏱️ Worked: <b>${d.time_worked}</b>
                                    </div>
                                </div>
                            </div>
                            <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-end;">
                                <button class="btn ${d.active_break_id ? 'btn-warning' : 'btn-secondary'} btn-sm" onclick="App.toggleBreak(${d.id})">
                                    ${d.active_break_id ? 'End Break' : 'Take Break'}
                                </button>
                                <button class="btn btn-danger btn-sm" onclick="App.clockOutEmployee(${d.id})">Clock Out</button>
                            </div>
                        </div>
                    `).join('');
                }
            }

            // Render history table
            const hist = histRes.history || [];
            if (historyTbody) {
                if (!hist.length) {
                    historyTbody.innerHTML = `<tr><td colspan="9" style="text-align:center; padding:2rem; color:var(--text-muted);">No historical records in this period.</td></tr>`;
                } else {
                    historyTbody.innerHTML = hist.map(h => `
                        <tr>
                            <td>
                                <b>${escapeHtml(h.first_name)} ${escapeHtml(h.last_name)}</b>
                                <div style="font-size:0.72rem; color:var(--text-muted);">${escapeHtml(h.employee_code)}</div>
                            </td>
                            <td>${escapeHtml(h.shift_name || 'Ad-hoc')} ${h.is_overnight ? '🌙' : ''}</td>
                            <td class="font-mono">${h.clock_in}</td>
                            <td class="font-mono">${h.clock_out ? h.clock_out : '<span class="badge badge-success">ON DUTY</span>'}</td>
                            <td class="font-mono">${h.regular_hours}h</td>
                            <td class="font-mono" style="color:var(--status-warning);">${h.overtime_hours > 0 ? `+${h.overtime_hours}h` : '0h'}</td>
                            <td class="font-mono" style="color:#c084fc;">${h.night_hours > 0 ? `🌙 ${h.night_hours}h` : '0h'}</td>
                            <td>
                                <span class="badge ${h.status === 'late' ? 'badge-danger' : (h.status === 'overtime' ? 'badge-warning' : 'badge-success')}">
                                    ${h.status.toUpperCase()} ${h.late_minutes ? `(${h.late_minutes}m late)` : ''}
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-secondary btn-sm" onclick="App.openManualAdjustmentModal(${h.id})">Adjust</button>
                            </td>
                        </tr>
                    `).join('');
                }
            }
        } catch (e) {
            console.error(e);
        }
    },

    async openKioskModal() {
        const empRes = await API.get('employees.php', { status: 'active', branch_id: this.state.activeBranchId });
        const employees = empRes.employees || [];

        const modalHtml = `
            <div class="modal-backdrop active" id="kiosk-modal">
                <div class="modal-dialog" style="max-width:500px;">
                    <div class="modal-header">
                        <div class="modal-title">⏱️ 24/7 Operations Duty Terminal</div>
                        <button class="modal-close" onclick="App.closeModal('kiosk-modal')">&times;</button>
                    </div>
                    <div class="modal-body">
                        <p style="font-size:0.85rem; color:var(--text-secondary); text-align:center;">
                            Select employee profile to clock in, clock out, or manage shift break.
                        </p>

                        <div class="form-group" style="margin-top:1rem;">
                            <label class="form-label">Select Employee</label>
                            <select id="kiosk-emp-select" class="form-control" onchange="App.onKioskEmployeeSelected(this.value)">
                                <option value="">-- Choose employee --</option>
                                ${employees.map(e => `
                                    <option value="${e.id}">${escapeHtml(e.first_name)} ${escapeHtml(e.last_name)} (${e.employee_code}) - ${escapeHtml(e.role_title)}</option>
                                `).join('')}
                            </select>
                        </div>

                        <div id="kiosk-status-box" style="margin-top:1rem; padding:1.25rem; border-radius:var(--radius-md); background:var(--bg-surface); text-align:center; display:none;">
                            <!-- Dynamic duty actions -->
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-secondary" onclick="App.closeModal('kiosk-modal')">Close Terminal</button>
                    </div>
                </div>
            </div>
        `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    async onKioskEmployeeSelected(empId) {
        const box = document.getElementById('kiosk-status-box');
        if (!empId) {
            if (box) box.style.display = 'none';
            return;
        }

        try {
            const res = await API.get('attendance.php', { action: 'employee_status', employee_id: empId });
            box.style.display = 'block';

            if (res.is_clocked_in && res.record) {
                const rec = res.record;
                box.innerHTML = `
                    <div style="font-size:1.1rem; font-weight:800; color:var(--status-success); margin-bottom:4px;">
                        ● Currently Clocked In
                    </div>
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1.25rem;">
                        Clock-in time: <b class="font-mono">${rec.clock_in}</b>
                    </div>
                    <div style="display:flex; justify-content:center; gap:10px;">
                        <button class="btn btn-danger" onclick="App.kioskClockOut(${rec.id})">Clock Out Now</button>
                        <button class="btn ${rec.active_break_id ? 'btn-warning' : 'btn-secondary'}" onclick="App.kioskBreakToggle(${rec.id})">
                            ${rec.active_break_id ? 'End Break' : 'Start Meal Break'}
                        </button>
                    </div>
                `;
            } else {
                box.innerHTML = `
                    <div style="font-size:1.1rem; font-weight:800; color:var(--text-primary); margin-bottom:4px;">
                        Ready for Duty
                    </div>
                    <div style="font-size:0.85rem; color:var(--text-muted); margin-bottom:1.25rem;">
                        Automated smart shift matching will associate with scheduled shift.
                    </div>
                    <button class="btn btn-success" style="font-size:1rem; padding:0.75rem 2rem;" onclick="App.kioskClockIn(${empId})">
                        Clock In For Shift
                    </button>
                `;
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async kioskClockIn(empId) {
        try {
            const res = await API.post('attendance.php?action=clock_in', { employee_id: empId });
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('kiosk-modal');
                App.refreshCurrentRoute();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async kioskClockOut(attendanceId) {
        try {
            const res = await API.post('attendance.php?action=clock_out', { attendance_id: attendanceId });
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('kiosk-modal');
                App.refreshCurrentRoute();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async clockOutEmployee(attendanceId) {
        if (!confirm('Clock out this employee session now?')) return;
        this.kioskClockOut(attendanceId);
    },

    async toggleBreak(attendanceId) {
        try {
            const res = await API.post('attendance.php?action=break_toggle', { attendance_id: attendanceId });
            if (res.success) {
                API.toast(res.message, 'info');
                this.loadAttendanceData();
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async kioskBreakToggle(attendanceId) {
        await this.toggleBreak(attendanceId);
        App.closeModal('kiosk-modal');
        App.refreshCurrentRoute();
    },

    async openManualAdjustmentModal(attendanceId) {
        const modalHtml = `
            <div class="modal-backdrop active" id="adj-modal">
                <div class="modal-dialog">
                    <div class="modal-header">
                        <div class="modal-title">Manual Attendance Adjustment</div>
                        <button class="modal-close" onclick="App.closeModal('adj-modal')">&times;</button>
                    </div>
                    <form onsubmit="App.saveManualAdjustment(event, ${attendanceId})">
                        <div class="modal-body">
                            <div class="form-group">
                                <label class="form-label">Clock In Datetime *</label>
                                <input type="text" name="clock_in" class="form-control font-mono" required placeholder="YYYY-MM-DD HH:MM:SS">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Clock Out Datetime</label>
                                <input type="text" name="clock_out" class="form-control font-mono" placeholder="YYYY-MM-DD HH:MM:SS">
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Regular Hours</label>
                                    <input type="number" step="0.1" name="regular_hours" class="form-control" value="8.0">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Night Hours (22:00-06:00)</label>
                                    <input type="number" step="0.1" name="night_hours" class="form-control" value="0.0">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Reason / Adjustment Note *</label>
                                <textarea name="notes" class="form-control" required placeholder="Manager explanation for audit log"></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="App.closeModal('adj-modal')">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save Adjustment</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    async saveManualAdjustment(event, id) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.target).entries());
        data.id = id;
        try {
            const res = await API.post('attendance.php?action=manual_adjustment', data);
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('adj-modal');
                App.loadAttendanceData();
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    // =========================================================================
    // Module 5: Task Management
    // =========================================================================
    async renderTasks(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>✅ 24-Hour Task Management</h1>
                    <p>Shift-based checklist execution, handover task roll-overs, and real-time operational workflows.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="App.openCreateTaskModal()">+ Create Task</button>
                </div>
            </div>

            <!-- Task Filters -->
            <div class="card" style="margin-bottom:1.5rem; padding:1rem;">
                <div style="display:flex; gap:1rem; flex-wrap:wrap; align-items:center;">
                    <div>
                        <select id="task-priority-filter" class="form-control" onchange="App.loadTasksData()">
                            <option value="all">Priority: All</option>
                            <option value="urgent">🚨 Urgent / Critical</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                    <div>
                        <select id="task-status-filter" class="form-control" onchange="App.loadTasksData()">
                            <option value="all">Status: All</option>
                            <option value="pending">Pending</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                        </select>
                    </div>
                    <div>
                        <select id="task-handover-filter" class="form-control" onchange="App.loadTasksData()">
                            <option value="">All Tasks</option>
                            <option value="1">🔄 Handover Tasks Only</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Task Title</th>
                                <th>Shift & Due</th>
                                <th>Priority</th>
                                <th>Assigned To</th>
                                <th>Checklist</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tasks-tbody">
                            <tr><td colspan="7" style="text-align:center; padding:2rem;">Loading tasks...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        this.loadTasksData();
    },

    async loadTasksData() {
        const priority = document.getElementById('task-priority-filter')?.value || 'all';
        const status = document.getElementById('task-status-filter')?.value || 'all';
        const isHandover = document.getElementById('task-handover-filter')?.value || '';
        const tbody = document.getElementById('tasks-tbody');
        if (!tbody) return;

        try {
            const res = await API.get('tasks.php', {
                priority,
                status,
                is_handover: isHandover,
                branch_id: this.state.activeBranchId
            });

            const tasks = res.tasks || [];
            if (!tasks.length) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:2rem; color:var(--text-muted);">No tasks found for current filter.</td></tr>`;
                return;
            }

            tbody.innerHTML = tasks.map(t => {
                const isOverdue = t.is_overdue;
                return `
                    <tr>
                        <td>
                            <div style="font-weight:700;">${escapeHtml(t.title)}</div>
                            <div style="font-size:0.75rem; color:var(--text-muted);">${escapeHtml(t.description || '')}</div>
                            ${t.is_handover_task ? '<span class="badge badge-warning" style="font-size:0.65rem; margin-top:4px;">🔄 Shift Handover Flagged</span>' : ''}
                        </td>
                        <td>
                            <div>${escapeHtml(t.shift_name || 'General Operations')}</div>
                            <div class="font-mono" style="font-size:0.75rem; color:${isOverdue ? 'var(--status-danger)' : 'var(--text-muted)'};">
                                Due: ${t.due_datetime.substring(0, 16)} ${isOverdue ? '<b>(OVERDUE)</b>' : ''}
                            </div>
                        </td>
                        <td>
                            <span class="badge ${t.priority === 'urgent' ? 'badge-danger' : (t.priority === 'high' ? 'badge-warning' : 'badge-info')}">
                                ${t.priority.toUpperCase()}
                            </span>
                        </td>
                        <td>${t.first_name ? `${escapeHtml(t.first_name)} ${escapeHtml(t.last_name)}` : '<span style="color:var(--text-muted);">Unassigned</span>'}</td>
                        <td>
                            ${t.checklist_total > 0 ? `
                                <span class="font-mono" style="font-weight:700;">${t.checklist_done}/${t.checklist_total}</span>
                                <div style="width:60px; height:4px; background:rgba(255,255,255,0.1); border-radius:4px; margin-top:2px;">
                                    <div style="width:${Math.round((t.checklist_done / t.checklist_total) * 100)}%; height:100%; background:var(--status-success); border-radius:4px;"></div>
                                </div>
                            ` : '<span style="color:var(--text-muted); font-size:0.75rem;">None</span>'}
                        </td>
                        <td>
                            <span class="badge ${t.status === 'completed' ? 'badge-success' : (t.status === 'in_progress' ? 'badge-warning' : 'badge-info')}">
                                ${t.status.toUpperCase()}
                            </span>
                        </td>
                        <td>
                            <div style="display:flex; gap:6px;">
                                <button class="btn btn-secondary btn-sm" onclick="App.viewTaskDetails(${t.id})">Details</button>
                                ${t.status !== 'completed' ? `
                                    <button class="btn btn-success btn-sm" onclick="App.setTaskStatus(${t.id}, 'completed')">Complete</button>
                                ` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:2rem; color:var(--status-danger);">Failed to load tasks: ${escapeHtml(e.message)}</td></tr>`;
        }
    },

    async openCreateTaskModal() {
        const [empRes, shiftRes] = await Promise.all([
            API.get('employees.php', { status: 'active', branch_id: this.state.activeBranchId }),
            API.get('shifts.php', { action: 'templates' })
        ]);

        const employees = empRes.employees || [];
        const templates = shiftRes.templates || [];

        const modalHtml = `
            <div class="modal-backdrop active" id="task-modal">
                <div class="modal-dialog">
                    <div class="modal-header">
                        <div class="modal-title">Create Operational Task</div>
                        <button class="modal-close" onclick="App.closeModal('task-modal')">&times;</button>
                    </div>
                    <form onsubmit="App.saveNewTask(event)">
                        <div class="modal-body">
                            <div class="form-group">
                                <label class="form-label">Task Title *</label>
                                <input type="text" name="title" class="form-control" required placeholder="e.g. Graveyard Perimeter Patrol & Vault Access Verification">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Description</label>
                                <textarea name="description" class="form-control" rows="2" placeholder="Specific operational guidelines..."></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Priority *</label>
                                    <select name="priority" class="form-control">
                                        <option value="medium">Medium</option>
                                        <option value="high">High</option>
                                        <option value="urgent">🚨 Urgent / Critical 24/7</option>
                                        <option value="low">Low</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Shift Template</label>
                                    <select name="shift_template_id" class="form-control">
                                        <option value="">General (No Shift)</option>
                                        ${templates.map(t => `<option value="${t.id}">${escapeHtml(t.name)} ${t.is_overnight ? '🌙' : ''}</option>`).join('')}
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Assignee</label>
                                    <select name="assigned_to_employee_id" class="form-control">
                                        <option value="">Unassigned (Team Task)</option>
                                        ${employees.map(e => `<option value="${e.id}">${escapeHtml(e.first_name)} ${escapeHtml(e.last_name)} (${e.employee_code})</option>`).join('')}
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Due Datetime *</label>
                                    <input type="datetime-local" name="due_datetime" class="form-control" required value="${this.formatDateTime(new Date(Date.now() + 4 * 3600 * 1000))}">
                                </div>
                            </div>

                            <div style="background:var(--bg-surface); padding:1rem; border-radius:var(--radius-md); border:1px solid var(--border-subtle);">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="is_handover_task" value="1">
                                    <span style="font-weight:700; font-size:0.88rem;">🔄 Flag for Shift Handover</span>
                                </label>
                                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                                    Task will automatically roll over to incoming shift's briefing log if incomplete.
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="App.closeModal('task-modal')">Cancel</button>
                            <button type="submit" class="btn btn-primary">Create Task</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    async saveNewTask(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.target).entries());
        data.branch_id = this.state.activeBranchId !== 'all' ? this.state.activeBranchId : 1;

        try {
            const res = await API.post('tasks.php?action=create', data);
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('task-modal');
                App.loadTasksData();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async setTaskStatus(taskId, status) {
        try {
            const res = await API.post('tasks.php?action=update_status', { task_id: taskId, status });
            if (res.success) {
                API.toast(res.message, 'success');
                this.loadTasksData();
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async viewTaskDetails(taskId) {
        try {
            const res = await API.get('tasks.php', { action: 'details', id: taskId });
            const task = res.task;
            const checklists = task.checklists || [];

            const modalHtml = `
                <div class="modal-backdrop active" id="task-view-modal">
                    <div class="modal-dialog">
                        <div class="modal-header">
                            <div class="modal-title">${escapeHtml(task.title)}</div>
                            <button class="modal-close" onclick="App.closeModal('task-view-modal')">&times;</button>
                        </div>
                        <div class="modal-body">
                            <p style="color:var(--text-secondary); margin-bottom:1rem;">${escapeHtml(task.description || 'No description provided.')}</p>
                            
                            <div style="display:flex; gap:8px; margin-bottom:1rem; flex-wrap:wrap;">
                                <span class="badge ${task.priority === 'urgent' ? 'badge-danger' : 'badge-warning'}">${task.priority.toUpperCase()}</span>
                                <span class="badge badge-info">${task.status.toUpperCase()}</span>
                                ${task.is_handover_task ? '<span class="badge badge-night">🔄 Handover Flagged</span>' : ''}
                            </div>

                            <h4 style="font-size:0.9rem; font-weight:800; margin-bottom:0.5rem;">Checklist Items</h4>
                            <div style="display:flex; flex-direction:column; gap:0.5rem;">
                                ${checklists.length > 0 ? checklists.map(chk => `
                                    <label style="display:flex; align-items:center; gap:8px; cursor:pointer; background:var(--bg-surface); padding:0.5rem 0.75rem; border-radius:var(--radius-sm);">
                                        <input type="checkbox" ${chk.is_completed ? 'checked' : ''} onchange="App.toggleChecklistItem(${chk.id})">
                                        <span style="${chk.is_completed ? 'text-decoration:line-through; color:var(--text-muted);' : ''}">${escapeHtml(chk.item_text)}</span>
                                    </label>
                                `).join('') : '<div style="color:var(--text-muted); font-size:0.8rem;">No checklist items attached.</div>'}
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" onclick="App.closeModal('task-view-modal')">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async toggleChecklistItem(itemId) {
        try {
            await API.post('tasks.php?action=toggle_checklist', { item_id: itemId });
            this.loadTasksData();
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    // =========================================================================
    // Module 6: Customer Service & 24/7 Incident Logging
    // =========================================================================
    async renderCustomerService(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>🎧 24/7 Customer Service & Incident Logging</h1>
                    <p>Continuous incident ticketing, emergency response SLA monitoring, and handover coordination.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="App.openLogTicketModal()">+ Log New Incident / Ticket</button>
                </div>
            </div>

            <!-- Filter Bar -->
            <div class="card" style="margin-bottom:1.5rem; padding:1rem;">
                <div style="display:flex; gap:1rem; flex-wrap:wrap; align-items:center;">
                    <div>
                        <select id="cs-severity-filter" class="form-control" onchange="App.loadTicketsData()">
                            <option value="all">Severity: All</option>
                            <option value="critical_247">🚨 Critical 24/7 Incident</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low Inquiry</option>
                        </select>
                    </div>
                    <div>
                        <select id="cs-status-filter" class="form-control" onchange="App.loadTicketsData()">
                            <option value="all">Status: All</option>
                            <option value="open">Open</option>
                            <option value="in_progress">In Progress</option>
                            <option value="pending_handover">Pending Handover</option>
                            <option value="resolved">Resolved</option>
                        </select>
                    </div>
                    <div>
                        <select id="cs-channel-filter" class="form-control" onchange="App.loadTicketsData()">
                            <option value="all">Channel: All</option>
                            <option value="phone">📞 Phone</option>
                            <option value="emergency_line">🚨 Emergency Line</option>
                            <option value="radio">📻 Dispatch Radio</option>
                            <option value="walk_in">🚶 Walk-In</option>
                            <option value="email">✉️ Email</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Ticket #</th>
                                <th>Severity</th>
                                <th>Customer & Channel</th>
                                <th>Subject</th>
                                <th>Status</th>
                                <th>Logged At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="tickets-tbody">
                            <tr><td colspan="7" style="text-align:center; padding:2rem;">Loading tickets...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        this.loadTicketsData();
    },

    async loadTicketsData() {
        const severity = document.getElementById('cs-severity-filter')?.value || 'all';
        const status = document.getElementById('cs-status-filter')?.value || 'all';
        const channel = document.getElementById('cs-channel-filter')?.value || 'all';
        const tbody = document.getElementById('tickets-tbody');
        if (!tbody) return;

        try {
            const res = await API.get('customer_service.php', {
                severity,
                status,
                channel,
                branch_id: this.state.activeBranchId
            });

            const tickets = res.tickets || [];
            if (!tickets.length) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:2rem; color:var(--text-muted);">No customer tickets found for selected criteria.</td></tr>`;
                return;
            }

            tbody.innerHTML = tickets.map(t => `
                <tr>
                    <td class="font-mono"><b>${escapeHtml(t.ticket_number)}</b></td>
                    <td>
                        <span class="badge ${t.severity === 'critical_247' ? 'badge-danger' : (t.severity === 'high' ? 'badge-warning' : 'badge-info')}">
                            ${t.severity === 'critical_247' ? '🚨 CRITICAL 24/7' : t.severity.toUpperCase()}
                        </span>
                    </td>
                    <td>
                        <div style="font-weight:700;">${escapeHtml(t.customer_name)}</div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">${escapeHtml(t.channel.toUpperCase())} • ${escapeHtml(t.customer_contact || '')}</div>
                    </td>
                    <td>
                        <div style="font-weight:600;">${escapeHtml(t.subject)}</div>
                        ${t.is_handover_flagged ? '<span class="badge badge-warning" style="font-size:0.65rem;">🔄 Shift Handover Flag</span>' : ''}
                    </td>
                    <td>
                        <span class="badge ${t.status === 'resolved' ? 'badge-success' : (t.status === 'in_progress' ? 'badge-info' : 'badge-warning')}">
                            ${t.status.toUpperCase()}
                        </span>
                    </td>
                    <td class="font-mono" style="font-size:0.8rem; color:var(--text-muted);">${t.created_at}</td>
                    <td>
                        <div style="display:flex; gap:6px;">
                            <button class="btn btn-secondary btn-sm" onclick="App.viewTicketDetails(${t.id})">Review</button>
                            ${t.status !== 'resolved' ? `
                                <button class="btn btn-success btn-sm" onclick="App.openResolveTicketModal(${t.id})">Resolve</button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `).join('');
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" style="text-align:center; padding:2rem; color:var(--status-danger);">Failed to load tickets: ${escapeHtml(e.message)}</td></tr>`;
        }
    },

    async openLogTicketModal() {
        const modalHtml = `
            <div class="modal-backdrop active" id="log-ticket-modal">
                <div class="modal-dialog">
                    <div class="modal-header">
                        <div class="modal-title">Log 24/7 Customer Service Interaction</div>
                        <button class="modal-close" onclick="App.closeModal('log-ticket-modal')">&times;</button>
                    </div>
                    <form onsubmit="App.saveTicket(event)">
                        <div class="modal-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Customer / Client Name *</label>
                                    <input type="text" name="customer_name" class="form-control" required placeholder="e.g. Horizon Healthcare Systems">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Contact (Phone / Email)</label>
                                    <input type="text" name="customer_contact" class="form-control" placeholder="Contact number or email">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Channel *</label>
                                    <select name="channel" class="form-control">
                                        <option value="phone">📞 Phone Call</option>
                                        <option value="emergency_line">🚨 Emergency Hotline</option>
                                        <option value="radio">📻 Dispatch Radio</option>
                                        <option value="walk_in">🚶 Walk-In Client</option>
                                        <option value="email">✉️ Email / Ticket</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Severity Level *</label>
                                    <select name="severity" class="form-control">
                                        <option value="low">Low (Standard Inquiry)</option>
                                        <option value="medium">Medium (Service Request)</option>
                                        <option value="high">High (Time Sensitive)</option>
                                        <option value="critical_247">🚨 Critical 24/7 Incident</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Subject / Incident Summary *</label>
                                <input type="text" name="subject" class="form-control" required placeholder="Brief summary of request or incident">
                            </div>

                            <div class="form-group">
                                <label class="form-label">Detailed Description</label>
                                <textarea name="description" class="form-control" rows="3" placeholder="Full context, caller notes, symptoms..."></textarea>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Immediate Action Taken</label>
                                <textarea name="action_taken" class="form-control" rows="2" placeholder="Dispatch instructions, escalations initiated..."></textarea>
                            </div>

                            <div style="background:var(--bg-surface); padding:1rem; border-radius:var(--radius-md); border:1px solid var(--border-subtle);">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                    <input type="checkbox" name="is_handover_flagged" value="1">
                                    <span style="font-weight:700; font-size:0.88rem;">🔄 Flag for Shift Handover</span>
                                </label>
                                <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">
                                    Highlights this unresolved incident in the next shift transition briefing.
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="App.closeModal('log-ticket-modal')">Cancel</button>
                            <button type="submit" class="btn btn-primary">Create Ticket</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    async saveTicket(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.target).entries());
        data.branch_id = this.state.activeBranchId !== 'all' ? this.state.activeBranchId : 1;

        try {
            const res = await API.post('customer_service.php?action=create', data);
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('log-ticket-modal');
                App.loadTicketsData();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async viewTicketDetails(ticketId) {
        try {
            const res = await API.get('customer_service.php', { action: 'details', id: ticketId });
            const t = res.ticket;

            const modalHtml = `
                <div class="modal-backdrop active" id="ticket-view-modal">
                    <div class="modal-dialog" style="max-width:650px;">
                        <div class="modal-header">
                            <div class="modal-title">Ticket #${escapeHtml(t.ticket_number)}</div>
                            <button class="modal-close" onclick="App.closeModal('ticket-view-modal')">&times;</button>
                        </div>
                        <div class="modal-body">
                            <h3 style="font-size:1.15rem; margin-bottom:0.5rem;">${escapeHtml(t.subject)}</h3>
                            <div style="display:flex; gap:8px; margin-bottom:1rem; flex-wrap:wrap;">
                                <span class="badge ${t.severity === 'critical_247' ? 'badge-danger' : 'badge-warning'}">${t.severity.toUpperCase()}</span>
                                <span class="badge badge-info">${t.status.toUpperCase()}</span>
                                <span class="badge badge-night">${escapeHtml(t.channel.toUpperCase())}</span>
                            </div>

                            <div style="background:var(--bg-surface); padding:1rem; border-radius:var(--radius-md); font-size:0.85rem; margin-bottom:1rem;">
                                <div><b>Customer:</b> ${escapeHtml(t.customer_name)} (${escapeHtml(t.customer_contact || 'N/A')})</div>
                                <div><b>Logged By:</b> ${escapeHtml(t.log_first)} ${escapeHtml(t.log_last)} (${escapeHtml(t.log_role)})</div>
                                <div><b>Logged At:</b> ${t.created_at}</div>
                            </div>

                            <div style="margin-bottom:1rem;">
                                <b>Description:</b>
                                <p style="color:var(--text-secondary); margin-top:4px;">${escapeHtml(t.description || 'No description.')}</p>
                            </div>

                            <div style="margin-bottom:1rem;">
                                <b>Immediate Action Taken:</b>
                                <p style="color:var(--text-secondary); margin-top:4px;">${escapeHtml(t.action_taken || 'None recorded.')}</p>
                            </div>

                            ${t.resolution_notes ? `
                                <div style="background:rgba(16,185,129,0.1); border:1px solid rgba(16,185,129,0.3); padding:1rem; border-radius:var(--radius-md);">
                                    <b style="color:var(--status-success);">Resolution Notes:</b>
                                    <p style="color:var(--text-primary); margin-top:4px;">${escapeHtml(t.resolution_notes)}</p>
                                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:6px;">Resolved at ${t.resolved_at} • Rating: ⭐ ${t.satisfaction_rating}/5</div>
                                </div>
                            ` : ''}
                        </div>
                        <div class="modal-footer">
                            <button class="btn btn-secondary" onclick="App.toggleTicketHandover(${t.id})">
                                ${t.is_handover_flagged ? 'Remove Handover Flag' : 'Flag for Handover'}
                            </button>
                            <button class="btn btn-secondary" onclick="App.closeModal('ticket-view-modal')">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async toggleTicketHandover(ticketId) {
        try {
            const res = await API.post('customer_service.php?action=flag_handover', { id: ticketId });
            if (res.success) {
                API.toast(res.message, 'info');
                App.closeModal('ticket-view-modal');
                App.loadTicketsData();
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async openResolveTicketModal(ticketId) {
        const modalHtml = `
            <div class="modal-backdrop active" id="resolve-modal">
                <div class="modal-dialog">
                    <div class="modal-header">
                        <div class="modal-title">Resolve Customer Service Ticket</div>
                        <button class="modal-close" onclick="App.closeModal('resolve-modal')">&times;</button>
                    </div>
                    <form onsubmit="App.saveTicketResolution(event, ${ticketId})">
                        <div class="modal-body">
                            <div class="form-group">
                                <label class="form-label">Resolution Notes *</label>
                                <textarea name="resolution_notes" class="form-control" rows="3" required placeholder="Describe how the customer issue was resolved or service restored..."></textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Resolution Time (mins)</label>
                                    <input type="number" name="resolution_time_minutes" class="form-control" value="25">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Customer Satisfaction CSAT (1-5)</label>
                                    <select name="satisfaction_rating" class="form-control">
                                        <option value="5">⭐⭐⭐⭐⭐ 5 - Highly Satisfied</option>
                                        <option value="4">⭐⭐⭐⭐ 4 - Satisfied</option>
                                        <option value="3">⭐⭐⭐ 3 - Neutral</option>
                                        <option value="2">⭐⭐ 2 - Dissatisfied</option>
                                        <option value="1">⭐ 1 - Unhappy</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="App.closeModal('resolve-modal')">Cancel</button>
                            <button type="submit" class="btn btn-success">Mark As Resolved</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    async saveTicketResolution(event, ticketId) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.target).entries());
        data.id = ticketId;

        try {
            const res = await API.post('customer_service.php?action=resolve', data);
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('resolve-modal');
                App.loadTicketsData();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    // =========================================================================
    // Module 7: Shift Handover Protocol
    // =========================================================================
    async renderHandover(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>🔄 Continuous Shift Handover Protocol</h1>
                    <p>Digital shift transitions connecting Outgoing and Incoming supervisors with dual digital sign-offs.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="App.openCreateHandoverModal()">+ Initiate Shift Handover</button>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Shift Date</th>
                                <th>Outgoing Shift</th>
                                <th>Incoming Shift</th>
                                <th>Outgoing Supervisor</th>
                                <th>Incoming Supervisor</th>
                                <th>Facility & Security</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="handovers-tbody">
                            <tr><td colspan="8" style="text-align:center; padding:2rem;">Loading handover records...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        this.loadHandoversData();
    },

    async loadHandoversData() {
        const tbody = document.getElementById('handovers-tbody');
        if (!tbody) return;

        try {
            const res = await API.get('handover.php', { action: 'list', branch_id: this.state.activeBranchId });
            const handovers = res.handovers || [];

            if (!handovers.length) {
                tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:2rem; color:var(--text-muted);">No shift handovers logged yet.</td></tr>`;
                return;
            }

            tbody.innerHTML = handovers.map(h => `
                <tr>
                    <td class="font-mono"><b>${h.shift_date}</b></td>
                    <td>
                        <span class="badge" style="background:rgba(6,182,212,0.15); color:var(--brand-cyan);">
                            ${escapeHtml(h.outgoing_shift_name)}
                        </span>
                    </td>
                    <td>
                        <span class="badge" style="background:rgba(139,92,246,0.15); color:#c084fc;">
                            ${escapeHtml(h.incoming_shift_name)}
                        </span>
                    </td>
                    <td><b>${escapeHtml(h.outgoing_supervisor_name)}</b></td>
                    <td>${h.incoming_supervisor_name ? `<b>${escapeHtml(h.incoming_supervisor_name)}</b>` : '<span style="color:var(--status-warning);">Pending Acceptance</span>'}</td>
                    <td>
                        <span class="badge ${h.facility_status === 'normal' ? 'badge-success' : 'badge-warning'}">Fac: ${h.facility_status}</span>
                        <span class="badge ${h.security_status === 'secure' ? 'badge-success' : 'badge-warning'}">Sec: ${h.security_status}</span>
                    </td>
                    <td>
                        <span class="badge ${h.status === 'completed' ? 'badge-success' : 'badge-warning'}">
                            ${h.status === 'completed' ? '✓ COMPLETED' : 'PENDING SIGNOFF'}
                        </span>
                    </td>
                    <td>
                        <div style="display:flex; gap:6px;">
                            <button class="btn btn-secondary btn-sm" onclick="App.viewHandoverDetails(${h.id})">Review Report</button>
                            ${h.status !== 'completed' ? `
                                <button class="btn btn-warning btn-sm" onclick="App.acknowledgeHandover(${h.id})">Acknowledge</button>
                            ` : ''}
                        </div>
                    </td>
                </tr>
            `).join('');
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="8" style="text-align:center; padding:2rem; color:var(--status-danger);">Failed to load handovers: ${escapeHtml(e.message)}</td></tr>`;
        }
    },

    async openCreateHandoverModal() {
        const [templatesRes, prefillRes, empRes] = await Promise.all([
            API.get('shifts.php', { action: 'templates' }),
            API.get('handover.php', { action: 'prefill_data', branch_id: this.state.activeBranchId !== 'all' ? this.state.activeBranchId : 1 }),
            API.get('employees.php', { status: 'active', branch_id: this.state.activeBranchId })
        ]);

        const templates = templatesRes.templates || [];
        const openTasks = prefillRes.open_tasks || [];
        const openTickets = prefillRes.open_tickets || [];
        const supervisors = (empRes.employees || []).filter(e => e.role_title.toLowerCase().includes('supervisor') || e.role_title.toLowerCase().includes('manager') || true);

        const modalHtml = `
            <div class="modal-backdrop active" id="handover-modal">
                <div class="modal-dialog" style="max-width:700px;">
                    <div class="modal-header">
                        <div class="modal-title">Initiate 24/7 Shift Handover</div>
                        <button class="modal-close" onclick="App.closeModal('handover-modal')">&times;</button>
                    </div>
                    <form onsubmit="App.saveHandover(event)">
                        <div class="modal-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Outgoing Shift *</label>
                                    <select name="outgoing_shift_template_id" class="form-control" required>
                                        ${templates.map(t => `<option value="${t.id}">${escapeHtml(t.name)} (${t.start_time.substring(0, 5)}-${t.end_time.substring(0, 5)})</option>`).join('')}
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Incoming Shift *</label>
                                    <select name="incoming_shift_template_id" class="form-control" required>
                                        ${templates.map((t, idx) => `<option value="${t.id}" ${idx === 1 ? 'selected' : ''}>${escapeHtml(t.name)} (${t.start_time.substring(0, 5)}-${t.end_time.substring(0, 5)})</option>`).join('')}
                                    </select>
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Incoming Supervisor</label>
                                    <select name="incoming_supervisor_id" class="form-control">
                                        <option value="">Any incoming shift supervisor</option>
                                        ${supervisors.map(s => `<option value="${s.id}">${escapeHtml(s.first_name)} ${escapeHtml(s.last_name)} (${escapeHtml(s.role_title)})</option>`).join('')}
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Shift Date *</label>
                                    <input type="date" name="shift_date" class="form-control" required value="${this.formatDate(new Date())}">
                                </div>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Facility Status</label>
                                    <select name="facility_status" class="form-control">
                                        <option value="normal">Normal (All operational)</option>
                                        <option value="needs_attention">Needs Attention</option>
                                        <option value="critical_issue">Critical Facility Issue</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Physical Security</label>
                                    <select name="security_status" class="form-control">
                                        <option value="secure">Secure & Armed</option>
                                        <option value="incident_logged">Security Incident Logged</option>
                                        <option value="patrol_needed">Patrol Needed</option>
                                    </select>
                                </div>
                            </div>

                            <div class="form-group">
                                <label class="form-label">Shift Operational Debrief Notes *</label>
                                <textarea name="handover_notes" class="form-control" rows="3" required placeholder="Provide comprehensive status for the incoming team..."></textarea>
                            </div>

                            <!-- Automatic roll-over items notice -->
                            <div style="background:var(--bg-surface); padding:1rem; border-radius:var(--radius-md); border:1px solid var(--border-subtle); font-size:0.82rem;">
                                <b>Auto-Included in Handover Dossier:</b>
                                <ul style="margin-left:1.25rem; margin-top:0.35rem; color:var(--text-secondary);">
                                    <li><b>${openTasks.length}</b> open/pending operational tasks</li>
                                    <li><b>${openTickets.length}</b> active customer service tickets</li>
                                    <li>Standard 5-point safety, cash, and facilities checklist</li>
                                </ul>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="App.closeModal('handover-modal')">Cancel</button>
                            <button type="submit" class="btn btn-primary">Sign & Submit Handover</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    async saveHandover(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.target).entries());
        data.branch_id = this.state.activeBranchId !== 'all' ? this.state.activeBranchId : 1;

        try {
            const res = await API.post('handover.php?action=create', data);
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('handover-modal');
                App.loadHandoversData();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async viewHandoverDetails(id) {
        try {
            const res = await API.get('handover.php', { action: 'details', id });
            const h = res.handover;

            const modalHtml = `
                <div class="modal-backdrop active" id="handover-details-modal">
                    <div class="modal-dialog" style="max-width:750px;">
                        <div class="modal-header">
                            <div class="modal-title">Shift Handover Dossier #${h.id} (${h.shift_date})</div>
                            <button class="modal-close" onclick="App.closeModal('handover-details-modal')">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div style="display:flex; justify-content:space-between; align-items:center; padding-bottom:1rem; border-bottom:1px solid var(--border-subtle); flex-wrap:wrap; gap:10px;">
                                <div>
                                    <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">Outgoing Shift</div>
                                    <b style="font-size:1.1rem; color:var(--brand-cyan);">${escapeHtml(h.outgoing_shift_name)}</b>
                                    <div style="font-size:0.8rem;">Signed by: <b>${escapeHtml(h.outgoing_supervisor_name)}</b></div>
                                </div>
                                <div style="font-size:1.5rem; color:var(--text-muted);">➔</div>
                                <div>
                                    <div style="font-size:0.75rem; color:var(--text-muted); text-transform:uppercase;">Incoming Shift</div>
                                    <b style="font-size:1.1rem; color:#c084fc;">${escapeHtml(h.incoming_shift_name)}</b>
                                    <div style="font-size:0.8rem;">Accepted by: <b>${h.incoming_supervisor_name || 'Pending'}</b></div>
                                </div>
                            </div>

                            <div style="margin-top:1rem;">
                                <b>Supervisor Briefing Notes:</b>
                                <p style="color:var(--text-secondary); margin-top:4px;">${escapeHtml(h.handover_notes || 'None')}</p>
                            </div>

                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:0.75rem; margin-top:1rem;">
                                <div style="background:var(--bg-surface); padding:0.75rem; border-radius:var(--radius-sm); text-align:center;">
                                    <div style="font-size:0.72rem; color:var(--text-muted);">FACILITY</div>
                                    <b style="color:var(--status-success);">${h.facility_status.toUpperCase()}</b>
                                </div>
                                <div style="background:var(--bg-surface); padding:0.75rem; border-radius:var(--radius-sm); text-align:center;">
                                    <div style="font-size:0.72rem; color:var(--text-muted);">SECURITY</div>
                                    <b style="color:var(--status-success);">${h.security_status.toUpperCase()}</b>
                                </div>
                                <div style="background:var(--bg-surface); padding:0.75rem; border-radius:var(--radius-sm); text-align:center;">
                                    <div style="font-size:0.72rem; color:var(--text-muted);">CASH/SAFE</div>
                                    <b style="color:var(--status-success);">${h.cash_status.toUpperCase()}</b>
                                </div>
                                <div style="background:var(--bg-surface); padding:0.75rem; border-radius:var(--radius-sm); text-align:center;">
                                    <div style="font-size:0.72rem; color:var(--text-muted);">EQUIPMENT</div>
                                    <b style="color:var(--status-success);">${h.equipment_status.toUpperCase()}</b>
                                </div>
                            </div>

                            <!-- Linked items -->
                            <div style="margin-top:1.5rem;">
                                <b>Carried-Forward Tasks:</b>
                                <ul style="margin-left:1.25rem; font-size:0.85rem; color:var(--text-secondary); margin-top:4px;">
                                    ${h.linked_tasks && h.linked_tasks.length > 0 ? h.linked_tasks.map(t => `
                                        <li>[${t.priority.toUpperCase()}] ${escapeHtml(t.title)} (${t.status})</li>
                                    `).join('') : '<li>None</li>'}
                                </ul>
                            </div>
                        </div>
                        <div class="modal-footer">
                            ${h.status !== 'completed' ? `
                                <button class="btn btn-success" onclick="App.acknowledgeHandover(${h.id})">Acknowledge & Accept Ownership</button>
                            ` : ''}
                            <button class="btn btn-secondary" onclick="App.closeModal('handover-details-modal')">Close</button>
                        </div>
                    </div>
                </div>
            `;
            document.body.insertAdjacentHTML('beforeend', modalHtml);
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async acknowledgeHandover(handoverId) {
        try {
            const res = await API.post('handover.php?action=acknowledge', { id: handoverId });
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('handover-details-modal');
                App.loadHandoversData();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    // =========================================================================
    // Module 8: Business Analytics
    // =========================================================================
    async renderAnalytics(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>📈 Business Analytics & 24-Hour Operational Telemetry</h1>
                    <p>Comparative shift performance, night wage differentials, operational health score, and department metrics.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-secondary btn-sm" onclick="App.loadAnalyticsData('7')">Last 7 Days</button>
                    <button class="btn btn-secondary btn-sm" onclick="App.loadAnalyticsData('30')">Last 30 Days</button>
                </div>
            </div>

            <div id="analytics-content-wrapper">
                <div style="text-align:center; padding:3rem; color:var(--text-muted);">Computing 24-hour business analytics...</div>
            </div>
        `;

        this.loadAnalyticsData('7');
    },

    async loadAnalyticsData(rangeDays = '7') {
        const wrapper = document.getElementById('analytics-content-wrapper');
        if (!wrapper) return;

        const endDate = this.formatDate(new Date());
        const startDate = this.formatDate(new Date(Date.now() - parseInt(rangeDays) * 864e5));

        try {
            const res = await API.get('analytics.php', {
                start_date: startDate,
                end_date: endDate,
                branch_id: this.state.activeBranchId
            });

            if (!res.success) throw new Error('Failed to load analytics');

            const sc = res.shift_comparison || [];
            const labor = res.labor_analytics;

            wrapper.innerHTML = `
                <!-- Top Row: Operational Health Score & Labor Costing -->
                <div style="display:grid; grid-template-columns:1fr 2fr; gap:1.5rem; margin-bottom:1.75rem;">
                    <!-- Health Score -->
                    <div class="card" style="display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:2rem;">
                        <div style="font-size:0.8rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; margin-bottom:0.5rem;">
                            24/7 Operations Health Index
                        </div>
                        <div style="position:relative; width:140px; height:140px; margin:0.5rem 0;">
                            <svg viewBox="0 0 36 36" style="width:100%; height:100%; transform:rotate(-90deg);">
                                <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="rgba(255,255,255,0.06)" stroke-width="3"></circle>
                                <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="var(--brand-cyan)" stroke-width="3" stroke-dasharray="${res.health_score} ${100 - res.health_score}"></circle>
                            </svg>
                            <div style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-family:'JetBrains Mono'; font-size:2rem; font-weight:800; color:#f8fafc;">
                                ${res.health_score}%
                            </div>
                        </div>
                        <div style="font-size:0.82rem; color:var(--text-secondary); max-width:240px;">
                            Calculated composite of shift punctuality, task completion rate, and ticket resolution.
                        </div>
                    </div>

                    <!-- Day vs Night Labor & Overtime Breakdown -->
                    <div class="card">
                        <div class="card-header">
                            <div class="card-title">Day vs Night Labor Expenditure & Hours Worked</div>
                        </div>
                        <div id="day-night-doughnut-container"></div>
                    </div>
                </div>

                <!-- Shift Comparison Table -->
                <div class="card" style="margin-bottom:1.75rem;">
                    <div class="card-header">
                        <div class="card-title">Shift Performance Matrix (Morning vs Afternoon vs Overnight Graveyard)</div>
                    </div>
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Shift Name</th>
                                    <th>Hours</th>
                                    <th>Scheduled Shifts</th>
                                    <th>Attendance Rate</th>
                                    <th>Punctuality Rate</th>
                                    <th>Tasks Completed</th>
                                    <th>CS Tickets</th>
                                    <th>Avg CSAT</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${sc.map(s => `
                                    <tr>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <span style="width:12px; height:12px; border-radius:50%; background:${s.color};"></span>
                                                <b>${escapeHtml(s.name)}</b>
                                                ${s.is_overnight ? '<span class="badge badge-overnight">🌙 Overnight</span>' : ''}
                                            </div>
                                        </td>
                                        <td class="font-mono">${s.start_time.substring(0, 5)} - ${s.end_time.substring(0, 5)}</td>
                                        <td class="font-mono">${s.scheduled_count}</td>
                                        <td class="font-mono"><b>${s.coverage_pct}%</b></td>
                                        <td class="font-mono" style="color:${s.punctuality_pct >= 90 ? 'var(--status-success)' : 'var(--status-warning)'};">
                                            <b>${s.punctuality_pct}%</b>
                                        </td>
                                        <td class="font-mono">${s.tasks_completed}</td>
                                        <td class="font-mono">${s.customer_tickets}</td>
                                        <td class="font-mono" style="color:#f59e0b;">⭐ ${s.avg_csat || '5.0'}</td>
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- 24-Hour Distribution Pulse -->
                <div class="chart-card">
                    <div class="chart-header">
                        <div class="card-title">24-Hour Continuous Operations Activity Curve (Aggregated)</div>
                    </div>
                    <div id="analytics-pulse-container" class="svg-chart-container"></div>
                </div>
            `;

            Charts.renderDayNightDoughnut('day-night-doughnut-container', labor);
            Charts.render24HourPulseChart('analytics-pulse-container', res.hourly_distribution);

        } catch (e) {
            wrapper.innerHTML = `<div style="color:var(--status-danger); text-align:center; padding:2rem;">Failed to render analytics: ${escapeHtml(e.message)}</div>`;
        }
    },

    // =========================================================================
    // Module 9: Operational Reports & CSV Export
    // =========================================================================
    async renderReports(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>📑 Operational Reports & Compliance Exports</h1>
                    <p>Generate auditable reports for attendance, shift fulfillment, tasks, incidents, and 24-hour labor costs.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-secondary" onclick="App.exportCurrentReport('csv')">📥 Download CSV</button>
                    <button class="btn btn-secondary" onclick="window.print()">🖨️ Print View</button>
                </div>
            </div>

            <!-- Report Configuration -->
            <div class="card" style="margin-bottom:1.5rem; padding:1.25rem;">
                <div style="display:flex; gap:1.25rem; flex-wrap:wrap; align-items:flex-end;">
                    <div class="form-group" style="min-width:260px;">
                        <label class="form-label">Report Category *</label>
                        <select id="report-type-select" class="form-control" onchange="App.generateReport()">
                            <option value="attendance">Workforce Attendance & Punctuality Report</option>
                            <option value="shifts">24-Hour Shift Scheduling & Coverage Report</option>
                            <option value="tasks">Operational Task Management & Productivity</option>
                            <option value="customer_service">24-Hour Customer Service & Incidents Report</option>
                            <option value="labor_cost">Labor Cost & Night Shift Differential Report</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Start Date</label>
                        <input type="date" id="report-start-date" class="form-control" value="${this.formatDate(new Date(Date.now() - 7 * 864e5))}">
                    </div>

                    <div class="form-group">
                        <label class="form-label">End Date</label>
                        <input type="date" id="report-end-date" class="form-control" value="${this.formatDate(new Date())}">
                    </div>

                    <button class="btn btn-primary" onclick="App.generateReport()">Generate Report</button>
                </div>
            </div>

            <!-- Report Output Card -->
            <div class="card" id="report-output-card">
                <div style="text-align:center; padding:3rem; color:var(--text-muted);">Click "Generate Report" to view compiled records.</div>
            </div>
        `;

        this.generateReport();
    },

    async generateReport() {
        const type = document.getElementById('report-type-select')?.value || 'attendance';
        const startDate = document.getElementById('report-start-date')?.value || this.formatDate(new Date(Date.now() - 7 * 864e5));
        const endDate = document.getElementById('report-end-date')?.value || this.formatDate(new Date());
        const card = document.getElementById('report-output-card');
        if (!card) return;

        card.innerHTML = `<div style="text-align:center; padding:2rem;">Compiling report...</div>`;

        try {
            const res = await API.get('reports.php', {
                type,
                start_date: startDate,
                end_date: endDate,
                branch_id: this.state.activeBranchId
            });

            const summary = res.summary || {};
            const records = res.records || [];

            card.innerHTML = `
                <div class="card-header" style="flex-wrap:wrap; gap:10px;">
                    <div>
                        <div class="card-title" style="font-size:1.15rem;">${escapeHtml(res.report_title)}</div>
                        <div style="font-size:0.8rem; color:var(--text-muted); margin-top:2px;">Timeframe: ${escapeHtml(res.date_range)} • Branch: ${escapeHtml(res.branch)}</div>
                    </div>
                    <div style="display:flex; gap:10px; flex-wrap:wrap;">
                        ${Object.keys(summary).map(k => `
                            <div style="background:var(--bg-surface); padding:0.4rem 0.75rem; border-radius:var(--radius-sm); font-size:0.78rem;">
                                <span style="color:var(--text-muted); text-transform:uppercase;">${k.replace(/_/g, ' ')}:</span>
                                <b class="font-mono">${summary[k]}</b>
                            </div>
                        `).join('')}
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="data-table" style="font-size:0.82rem;">
                        <thead>
                            <tr>
                                ${records.length > 0 ? Object.keys(records[0]).slice(0, 8).map(k => `<th>${k.replace(/_/g, ' ').toUpperCase()}</th>`).join('') : '<th>No Data</th>'}
                            </tr>
                        </thead>
                        <tbody>
                            ${records.length > 0 ? records.map(r => `
                                <tr>
                                    ${Object.keys(r).slice(0, 8).map(k => `<td>${escapeHtml(String(r[k] !== null ? r[k] : ''))}</td>`).join('')}
                                </tr>
                            `).join('') : '<tr><td colspan="8" style="text-align:center; padding:2rem;">No records found for this period.</td></tr>'}
                        </tbody>
                    </table>
                </div>
            `;
        } catch (e) {
            card.innerHTML = `<div style="color:var(--status-danger); padding:2rem; text-align:center;">Failed to generate report: ${escapeHtml(e.message)}</div>`;
        }
    },

    exportCurrentReport(format = 'csv') {
        const type = document.getElementById('report-type-select')?.value || 'attendance';
        const startDate = document.getElementById('report-start-date')?.value || this.formatDate(new Date(Date.now() - 7 * 864e5));
        const endDate = document.getElementById('report-end-date')?.value || this.formatDate(new Date());
        const branchId = this.state.activeBranchId;

        const downloadUrl = `api/reports.php?type=${type}&format=${format}&start_date=${startDate}&end_date=${endDate}&branch_id=${branchId}`;
        window.location.href = downloadUrl;
    },

    // =========================================================================
    // Module 10: Branch Management
    // =========================================================================
    async renderBranches(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>🏢 Business & Branch Management</h1>
                    <p>Continuous 24-hour physical hubs, logistical centers, and regional departments.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-primary" onclick="App.openCreateBranchModal()">+ Add New Branch</button>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Branch Code</th>
                                <th>Branch Location</th>
                                <th>Address</th>
                                <th>Operating Model</th>
                                <th>General Manager</th>
                                <th>Staff Enrolled</th>
                                <th>On Duty Now</th>
                            </tr>
                        </thead>
                        <tbody id="branches-tbody">
                            ${this.state.branches.map(b => `
                                <tr>
                                    <td class="font-mono"><b>${escapeHtml(b.code)}</b></td>
                                    <td><b>${escapeHtml(b.name)}</b></td>
                                    <td>${escapeHtml(b.address || 'N/A')}</td>
                                    <td><span class="badge badge-night">24/7/365 Continuous</span></td>
                                    <td>${escapeHtml(b.manager_name || 'Unassigned')}</td>
                                    <td class="font-mono">${b.active_employees_count || 0}</td>
                                    <td><span class="badge badge-success"><span class="pulse-dot"></span> ${b.live_on_duty_count || 0} on duty</span></td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    },

    openCreateBranchModal() {
        const modalHtml = `
            <div class="modal-backdrop active" id="branch-modal">
                <div class="modal-dialog">
                    <div class="modal-header">
                        <div class="modal-title">Create New 24/7 Branch Location</div>
                        <button class="modal-close" onclick="App.closeModal('branch-modal')">&times;</button>
                    </div>
                    <form onsubmit="App.saveBranch(event)">
                        <div class="modal-body">
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Branch Name *</label>
                                    <input type="text" name="name" class="form-control" required placeholder="e.g. Harbor Terminal Continuous Center">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Branch Code *</label>
                                    <input type="text" name="code" class="form-control font-mono" required placeholder="e.g. BR-HB04">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Address</label>
                                <input type="text" name="address" class="form-control" placeholder="Street, City, State">
                            </div>
                            <div class="form-row">
                                <div class="form-group">
                                    <label class="form-label">Phone</label>
                                    <input type="text" name="phone" class="form-control">
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Manager Name</label>
                                    <input type="text" name="manager_name" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" onclick="App.closeModal('branch-modal')">Cancel</button>
                            <button type="submit" class="btn btn-primary">Create Branch</button>
                        </div>
                    </form>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    async saveBranch(event) {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(event.target).entries());
        try {
            const res = await API.post('branches.php?action=create_branch', data);
            if (res.success) {
                API.toast(res.message, 'success');
                App.closeModal('branch-modal');
                await App.loadBranches();
                App.renderBranches(document.getElementById('content-container'));
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    // =========================================================================
    // Module 11: Audit Logs & Compliance Trail
    // =========================================================================
    async renderAudit(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>🛡️ Compliance & Operational Audit Trail</h1>
                    <p>Tamper-evident system logs capturing every clock-in, shift edit, handover sign-off, and customer ticket action.</p>
                </div>
            </div>

            <div class="card">
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>User / Actor</th>
                                <th>Action</th>
                                <th>Target Entity</th>
                                <th>IP Address</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody id="audit-tbody">
                            <tr><td colspan="6" style="text-align:center; padding:2rem;">Loading audit trail...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        try {
            const res = await API.get('audit.php');
            const logs = res.audit_logs || [];
            const tbody = document.getElementById('audit-tbody');
            if (tbody) {
                tbody.innerHTML = logs.map(l => `
                    <tr>
                        <td class="font-mono" style="font-size:0.8rem; color:var(--text-muted);">${l.created_at}</td>
                        <td><b>${escapeHtml(l.username || 'System')}</b> <span class="badge badge-info" style="font-size:0.65rem;">${l.role || 'sys'}</span></td>
                        <td><span class="badge badge-night">${escapeHtml(l.action)}</span></td>
                        <td>${escapeHtml(l.entity_type)} #${l.entity_id || '-'}</td>
                        <td class="font-mono" style="font-size:0.75rem;">${escapeHtml(l.ip_address)}</td>
                        <td style="font-size:0.78rem; font-family:'JetBrains Mono'; max-width:350px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                            ${escapeHtml(l.details || '')}
                        </td>
                    </tr>
                `).join('');
            }
        } catch (e) {
            console.error(e);
        }
    },

    // =========================================================================
    // Module 12: System Settings
    // =========================================================================
    async renderSettings(container) {
        container.innerHTML = `
            <div class="page-header">
                <div class="page-title-group">
                    <h1>⚙️ System Settings & 24/7 Operational Rules</h1>
                    <p>Configure continuous business operating rules, night differential wage multiplier, and grace periods.</p>
                </div>
            </div>

            <div id="settings-form-wrapper">Loading system configuration...</div>
        `;

        try {
            const res = await API.get('settings.php');
            const s = res.settings;
            const b = res.business;

            const wrapper = document.getElementById('settings-form-wrapper');
            if (!wrapper) return;

            wrapper.innerHTML = `
                <form onsubmit="App.saveSettings(event)" class="card" style="max-width:800px; padding:1.75rem;">
                    <h3 style="margin-bottom:1rem; font-size:1.1rem; border-bottom:1px solid var(--border-subtle); padding-bottom:0.5rem;">
                        24-Hour Business Operating Rules
                    </h3>

                    <div class="form-row" style="margin-bottom:1rem;">
                        <div class="form-group">
                            <label class="form-label">Grace Period for Late Clock-In (Minutes) *</label>
                            <input type="number" name="settings[grace_period_minutes]" class="form-control" required value="${s.grace_period_minutes?.value || 15}">
                            <div style="font-size:0.72rem; color:var(--text-muted);">Clock-ins exceeding scheduled start + grace period are logged as Late.</div>
                        </div>
                        <div class="form-group">
                            <label class="form-label">Night Shift Wage Differential Multiplier *</label>
                            <input type="number" step="0.05" name="settings[night_differential_multiplier]" class="form-control" required value="${s.night_differential_multiplier?.value || 1.25}">
                            <div style="font-size:0.72rem; color:var(--text-muted);">1.25 equals a 25% wage premium for overnight hours (22:00-06:00).</div>
                        </div>
                    </div>

                    <div class="form-row" style="margin-bottom:1rem;">
                        <div class="form-group">
                            <label class="form-label">Night Shift Start Time</label>
                            <input type="time" name="settings[night_shift_start]" class="form-control" value="${s.night_shift_start?.value || '22:00:00'}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Night Shift End Time</label>
                            <input type="time" name="settings[night_shift_end]" class="form-control" value="${s.night_shift_end?.value || '06:00:00'}">
                        </div>
                    </div>

                    <div class="form-row" style="margin-bottom:1.5rem;">
                        <div class="form-group">
                            <label class="form-label">Min. Rest Hours Between Shifts (Fatigue Guard)</label>
                            <input type="number" name="settings[min_rest_hours_between_shifts]" class="form-control" value="${s.min_rest_hours_between_shifts?.value || 8}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Currency Symbol</label>
                            <input type="text" name="settings[currency_symbol]" class="form-control" value="${s.currency_symbol?.value || '$'}">
                        </div>
                    </div>

                    <h3 style="margin-bottom:1rem; font-size:1.1rem; border-bottom:1px solid var(--border-subtle); padding-bottom:0.5rem;">
                        Business Identity
                    </h3>

                    <div class="form-row" style="margin-bottom:1rem;">
                        <div class="form-group">
                            <label class="form-label">Enterprise Name</label>
                            <input type="text" name="business[name]" class="form-control" value="${escapeHtml(b.name || '')}">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Tagline</label>
                            <input type="text" name="business[tagline]" class="form-control" value="${escapeHtml(b.tagline || '')}">
                        </div>
                    </div>

                    <div style="display:flex; justify-content:flex-end; margin-top:1.5rem;">
                        <button type="submit" class="btn btn-primary">Save System Settings</button>
                    </div>
                </form>
            `;
        } catch (e) {
            console.error(e);
        }
    },

    async saveSettings(event) {
        event.preventDefault();
        const form = event.target;
        const formData = new FormData(form);

        const payload = { settings: {}, business: {} };
        for (const [key, value] of formData.entries()) {
            if (key.startsWith('settings[')) {
                const cleanKey = key.replace('settings[', '').replace(']', '');
                payload.settings[cleanKey] = value;
            } else if (key.startsWith('business[')) {
                const cleanKey = key.replace('business[', '').replace(']', '');
                payload.business[cleanKey] = value;
            }
        }

        try {
            const res = await API.post('settings.php', payload);
            if (res.success) {
                API.toast(res.message, 'success');
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    // =========================================================================
    // Auth & Demo Switcher UI
    // =========================================================================
    showLoginView() {
        document.getElementById('app-container').style.display = 'none';
        document.getElementById('login-screen').style.display = 'flex';
    },

    async handleLoginSubmit(event) {
        event.preventDefault();
        const form = event.target;
        const data = Object.fromEntries(new FormData(form).entries());

        try {
            const res = await API.post('auth.php?action=login', data);
            if (res.success) {
                this.state.user = res.user;
                API.toast(`Welcome back, ${res.user.first_name || res.user.username}!`, 'success');
                await this.bootstrapApp();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async quickDemoLogin(role) {
        try {
            const res = await API.post('auth.php?action=demo_login', { role });
            if (res.success) {
                this.state.user = res.user;
                API.toast(res.message, 'success');
                this.closeModal('demo-role-modal');
                await this.bootstrapApp();
            } else {
                API.toast(res.message, 'error');
            }
        } catch (e) {
            API.toast(e.message, 'error');
        }
    },

    async handleLogout() {
        try {
            await API.post('auth.php?action=logout');
            this.state.user = null;
            if (this.state.clockInterval) clearInterval(this.state.clockInterval);
            if (this.state.notificationsInterval) clearInterval(this.state.notificationsInterval);
            API.toast('Signed out successfully', 'info');
            this.showLoginView();
        } catch (e) {
            this.showLoginView();
        }
    },

    openDemoRoleModal() {
        const modalHtml = `
            <div class="modal-backdrop active" id="demo-role-modal">
                <div class="modal-dialog" style="max-width:520px;">
                    <div class="modal-header">
                        <div class="modal-title">Switch Active 24/7 Demo Persona</div>
                        <button class="modal-close" onclick="App.closeModal('demo-role-modal')">&times;</button>
                    </div>
                    <div class="modal-body" style="display:flex; flex-direction:column; gap:0.75rem;">
                        <p style="font-size:0.85rem; color:var(--text-secondary);">
                            Instantly switch roles to evaluate permissions, scheduling views, and night shift supervisory workflows.
                        </p>

                        <div class="duty-card" style="cursor:pointer;" onclick="App.quickDemoLogin('admin')">
                            <div class="duty-emp-meta">
                                <div class="avatar-circle" style="background:#3b82f6;">PM</div>
                                <div>
                                    <b>Peter Mwewa (Chief of Operations)</b>
                                    <div style="font-size:0.75rem; color:var(--brand-cyan);">System Administrator (Full Global Access)</div>
                                </div>
                            </div>
                            <button class="btn btn-primary btn-sm">Switch</button>
                        </div>

                        <div class="duty-card" style="cursor:pointer;" onclick="App.quickDemoLogin('manager')">
                            <div class="duty-emp-meta">
                                <div class="avatar-circle" style="background:#0ea5e9;">MM</div>
                                <div>
                                    <b>Mutinta Mayibbe (Branch General Manager)</b>
                                    <div style="font-size:0.75rem; color:var(--status-success);">Downtown Central Flagship Manager</div>
                                </div>
                            </div>
                            <button class="btn btn-primary btn-sm">Switch</button>
                        </div>

                        <div class="duty-card" style="cursor:pointer;" onclick="App.quickDemoLogin('supervisor')">
                            <div class="duty-emp-meta">
                                <div class="avatar-circle" style="background:#8b5cf6;">NT</div>
                                <div>
                                    <b>Nerbart Tembo (Overnight Supervisor)</b>
                                    <div style="font-size:0.75rem; color:#c084fc;">🌙 Night Specialist & Graveyard Shift Lead</div>
                                </div>
                            </div>
                            <button class="btn btn-primary btn-sm">Switch</button>
                        </div>

                        <div class="duty-card" style="cursor:pointer;" onclick="App.quickDemoLogin('staff')">
                            <div class="duty-emp-meta">
                                <div class="avatar-circle" style="background:#06b6d4;">MT</div>
                                <div>
                                    <b>Milimo Tandeo (NOC Support Engineer)</b>
                                    <div style="font-size:0.75rem; color:var(--text-secondary);">Technical Operator (Staff Level)</div>
                                </div>
                            </div>
                            <button class="btn btn-primary btn-sm">Switch</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    },

    toggleTheme() {
        document.body.classList.toggle('light-theme');
        const isLight = document.body.classList.contains('light-theme');
        localStorage.setItem('247_theme', isLight ? 'light' : 'dark');
        API.toast(`Switched to ${isLight ? 'Light' : 'Night Operations'} Theme`, 'info');
    },

    closeModal(modalId) {
        const m = document.getElementById(modalId);
        if (m) m.remove();
    },

    formatDate(d) {
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    },

    formatDateTime(d) {
        const year = d.getFullYear();
        const month = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        const hours = String(d.getHours()).padStart(2, '0');
        const mins = String(d.getMinutes()).padStart(2, '0');
        return `${year}-${month}-${day}T${hours}:${mins}`;
    }
};

// Initialize application on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    // Restore saved theme
    if (localStorage.getItem('247_theme') === 'light') {
        document.body.classList.add('light-theme');
    }
    App.init();
});
