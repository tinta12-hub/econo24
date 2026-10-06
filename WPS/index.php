<?php
/**
 * 24/7 - 24-Hour Business Operations Management System
 * Main Web Application Interface Entry Point
 */

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/helpers.php';

// Check if database tables exist, auto-run install if missing
try {
    $pdo = Database::getConnection();
    $chk = $pdo->query("SHOW TABLES LIKE 'employees'");
    if ($chk->rowCount() === 0) {
        // Automatically run installer
        include __DIR__ . '/database/install.php';
        header("Location: index.php");
        exit;
    }
} catch (Exception $e) {
    die("Database configuration error: " . htmlspecialchars($e->getMessage()));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>24/7 — Continuous Business Operations Management System</title>
    <meta name="description" content="Centralized 24-hour business operations management platform synchronizing employees, shifts, attendance, tasks, customer service, and shift handovers.">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>⏱️</text></svg>">
    
    <!-- Design System & Stylesheets -->
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/dashboard.css">
</head>
<body>

    <!-- ====================================================================
         Login Screen (Dedicated View)
         ==================================================================== -->
    <div id="login-screen" style="display:none; min-height:100vh; width:100vw; background:linear-gradient(135deg, #06090e 0%, #0f172a 100%); align-items:center; justify-content:center; padding:1.5rem; position:relative; overflow:hidden;">
        <!-- Background Ambient Glow -->
        <div style="position:absolute; width:500px; height:500px; background:radial-gradient(circle, rgba(6,182,212,0.18) 0%, transparent 70%); top:-100px; left:-100px; pointer-events:none;"></div>
        <div style="position:absolute; width:400px; height:400px; background:radial-gradient(circle, rgba(139,92,246,0.15) 0%, transparent 70%); bottom:-80px; right:-80px; pointer-events:none;"></div>

        <div style="width:100%; max-width:440px; background:rgba(30, 41, 59, 0.85); backdrop-filter:blur(16px); -webkit-backdrop-filter:blur(16px); border:1px solid rgba(255,255,255,0.12); border-radius:var(--radius-lg); padding:2.25rem; box-shadow:var(--shadow-lg); z-index:1;">
            <div style="text-align:center; margin-bottom:1.75rem;">
                <div style="display:inline-flex; align-items:center; gap:8px; margin-bottom:0.75rem;">
                    <span class="brand-badge">24/7</span>
                    <span style="font-size:1.35rem; font-weight:800; letter-spacing:-0.5px;">OPERATIONS</span>
                </div>
                <div style="font-size:0.85rem; color:var(--text-secondary);">Continuous 24-Hour Business Operations Management System</div>
            </div>

            <form onsubmit="App.handleLoginSubmit(event)" style="display:flex; flex-direction:column; gap:1rem;">
                <div class="form-group">
                    <label class="form-label">Email or Username</label>
                    <input type="text" name="login" class="form-control" required placeholder="admin@247ops.com" value="admin@247ops.com">
                </div>

                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input type="password" name="password" class="form-control" required placeholder="••••••••" value="admin123">
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%; padding:0.75rem; margin-top:0.5rem; font-size:0.95rem;">
                    Access 24/7 Operations Room
                </button>
            </form>

            <div style="margin-top:1.75rem; padding-top:1.25rem; border-top:1px solid var(--border-subtle); text-align:center;">
                <div style="font-size:0.75rem; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px; margin-bottom:0.75rem;">
                    ⚡ Instant 1-Click Evaluation Personas
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.5rem;">
                    <button type="button" class="btn btn-secondary btn-sm" onclick="App.quickDemoLogin('admin')">👑 Admin</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="App.quickDemoLogin('manager')">🏢 Manager</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="App.quickDemoLogin('supervisor')">🌙 Night Supv</button>
                    <button type="button" class="btn btn-secondary btn-sm" onclick="App.quickDemoLogin('staff')">👷 NOC Staff</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ====================================================================
         Main Application Shell (SPA)
         ==================================================================== -->
    <div id="app-container" style="display:none;">

        <!-- Sidebar Navigation -->
        <aside id="sidebar">
            <div class="sidebar-header">
                <a href="#dashboard" class="brand-logo">
                    <span class="brand-badge">24/7</span>
                    <div class="brand-text">
                        <span class="brand-title">OPERATIONS</span>
                        <span class="brand-sub"><span class="pulse-dot"></span> LIVE 24/7/365</span>
                    </div>
                </a>
            </div>

            <nav class="sidebar-nav">
                <div class="nav-section-title">Core Operations</div>
                
                <a class="nav-link active" data-route="dashboard" href="#dashboard">
                    <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    <span>Operations Dashboard</span>
                </a>

                <a class="nav-link" data-route="employees" href="#employees">
                    <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    <span>Employee Management</span>
                </a>

                <a class="nav-link" data-route="shifts" href="#shifts">
                    <svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    <span>Shift Scheduling</span>
                </a>

                <a class="nav-link" data-route="attendance" href="#attendance">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <span>Attendance & Duty</span>
                </a>

                <a class="nav-link" data-route="tasks" href="#tasks">
                    <svg viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/></svg>
                    <span>Task Management</span>
                </a>

                <a class="nav-link" data-route="customer_service" href="#customer_service">
                    <svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    <span>Customer Service</span>
                </a>

                <div class="nav-section-title">24-Hour Continuous</div>

                <a class="nav-link" data-route="handover" href="#handover">
                    <svg viewBox="0 0 24 24"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></svg>
                    <span>Shift Handover Protocol</span>
                </a>

                <a class="nav-link" data-route="analytics" href="#analytics">
                    <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    <span>Business Analytics</span>
                </a>

                <a class="nav-link" data-route="reports" href="#reports">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
                    <span>Operational Reports</span>
                </a>

                <div class="nav-section-title">System & Hubs</div>

                <a class="nav-link" data-route="branches" href="#branches">
                    <svg viewBox="0 0 24 24"><path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"/></svg>
                    <span>Branches & Hubs</span>
                </a>

                <a class="nav-link" data-route="audit" href="#audit">
                    <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    <span>Compliance Audit</span>
                </a>

                <a class="nav-link" data-route="settings" href="#settings">
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    <span>System Settings</span>
                <div class="nav-section-title">My Account & Duty</div>

                <a class="nav-link" data-route="profile" href="#profile">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <span>My Profile & Duty</span>
                </a>
            </nav>

            <div class="sidebar-footer">
                <div class="user-snippet" style="cursor:pointer;" onclick="App.navigate('profile')" title="Click to view My Profile">
                    <div class="avatar-circle" id="user-avatar-badge">A</div>
                    <div class="user-info">
                        <div class="user-name" id="user-display-name">Peter Mwewa</div>
                        <div class="user-role-badge" id="user-display-role">System Administrator</div>
                    </div>
                    <button class="btn-icon-action" title="Sign Out / Log Out" onclick="event.stopPropagation(); App.handleLogout();" style="width:34px; height:34px; color:var(--status-danger);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    </button>
                </div>
            </div>
        </aside>

        <!-- Main Wrapper -->
        <div id="main-wrapper">
            <!-- Topbar -->
            <header id="topbar">
                <div class="topbar-left">
                    <button class="btn-sidebar-toggle" onclick="document.getElementById('sidebar').classList.toggle('mobile-open')">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                    </button>

                    <!-- 24-Hour Live Operations Clock & Phase -->
                    <div class="ops-clock-widget">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span id="live-clock-digits" class="clock-digits">00:00:00</span>
                        <span id="live-clock-phase" class="clock-phase-badge phase-night">🌙 24/7 Operations</span>
                    </div>

                    <!-- Direct Personal Duty Clock-In/Out Bar for Logged-In User -->
                    <div id="topbar-personal-duty" class="personal-duty-widget off-duty">
                        <div class="personal-duty-meta">
                            <span class="personal-duty-status" id="topbar-duty-status"><span style="color:var(--text-muted);">●</span> OFF DUTY</span>
                            <span class="personal-duty-time" id="topbar-duty-time">Ready to Clock In</span>
                        </div>
                        <div id="topbar-duty-action-btns" style="display:flex; gap:4px;">
                            <button class="btn btn-success btn-sm" id="btn-topbar-clockin" onclick="App.myClockIn()">⏱️ Clock In</button>
                        </div>
                    </div>

                    <!-- Branch Selector -->
                    <div class="branch-select-wrapper">
                        <select id="global-branch-select" class="select-custom">
                            <option value="all">🏢 All Branches (Global 24/7)</option>
                        </select>
                    </div>
                </div>

                <div class="topbar-right">
                    <!-- Quick Kiosk Clock In -->
                    <button class="btn-kiosk-quick" onclick="App.openKioskModal()">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span>Kiosk Terminal</span>
                    </button>

                    <!-- Persona Switcher -->
                    <button class="btn-demo-switch" onclick="App.openDemoRoleModal()" title="Switch Persona">
                        <span>⚡ Switch Persona</span>
                    </button>

                    <!-- Profile Quick Button -->
                    <button class="btn-icon-action" onclick="App.navigate('profile')" title="My Profile">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    </button>

                    <!-- Theme Toggle -->
                    <button class="btn-icon-action" onclick="App.toggleTheme()" title="Toggle Theme">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
                    </button>

                    <!-- Logout Button -->
                    <button class="btn-icon-action" onclick="App.handleLogout()" title="Sign Out / Log Out" style="color:var(--status-danger);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    </button>
                </div>
            </header>

            <!-- Dynamic Route Content Container -->
            <main id="content-container">
                <!-- Injected dynamically by app.js router -->
            </main>
        </div>
    </div>

    <!-- Toast Notifications Root -->
    <div id="toast-container"></div>

    <!-- Application Scripts -->
    <script src="assets/js/api.js"></script>
    <script src="assets/js/charts.js"></script>
    <script src="assets/js/app.js"></script>
</body>
</html>
