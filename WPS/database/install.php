<?php
/**
 * 24/7 - Database Installer and Seeder
 * Run via CLI: php database/install.php
 * Or via browser: http://localhost/WPS/database/install.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

header('Content-Type: text/plain; charset=utf-8');
echo "=======================================================\n";
echo "24/7 Operations Management System - Database Installer\n";
echo "=======================================================\n\n";

try {
    $pdo = Database::getConnection();
    echo "[1/4] Connected to MySQL database '24hr' successfully.\n";

    // 1. Run schema.sql
    echo "[2/4] Executing database/schema.sql...\n";
    $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
    
    // Split and execute SQL statements
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $queries = array_filter(array_map('trim', explode(';', $schemaSql)));
    foreach ($queries as $query) {
        if (!empty($query)) {
            $pdo->exec($query);
        }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");
    echo "      Tables created successfully.\n";

    // 2. Seed Master Data
    echo "[3/4] Seeding core operational master data...\n";

    // Business
    $pdo->exec("
        INSERT INTO businesses (id, name, tagline, operating_model, timezone, currency, address, phone, email)
        VALUES (1, '24/7 Global Operations Enterprise', 'Round-the-Clock Workforce & Operational Synchronization', '24/7/365 Continuous', 'UTC+02:00', 'ZMW', '100 Metro Tower Blvd, Suite 2400', '+1 (800) 247-9000', 'operations@247ops.com')
    ");

    // Branches
    $pdo->exec("
        INSERT INTO branches (id, business_id, name, code, address, phone, email, is_24hr, manager_name, status) VALUES
        (1, 1, 'Downtown Central (Flagship 24/7)', 'BR-DT01', '100 Grand Avenue, Financial Core', '+1 (555) 247-0100', 'downtown@247ops.com', 1, 'Mutinta Mayibbe', 'active'),
        (2, 1, 'Metro Logistics & Dispatch Hub', 'BR-LG02', '450 Industrial Parkway, Gate 12', '+1 (555) 247-0200', 'logistics@247ops.com', 1, 'Evans Phiri', 'active'),
        (3, 1, 'Airport Terminal Rapid Service Center', 'BR-AP03', 'Terminal 3 Concourse B, Skyway Gate 4', '+1 (555) 247-0300', 'airport@247ops.com', 1, 'Kenji Sato', 'active')
    ");

    // Departments
    $pdo->exec("
        INSERT INTO departments (id, branch_id, name, code, description) VALUES
        (1, 1, 'Customer Operations & Frontline', 'DEP-OPS', '24-hour frontline customer assistance, inquiries and walk-in support'),
        (2, 2, 'Logistics, Fleet & Fulfillment', 'DEP-LOG', 'Continuous 24-hour order fulfillment, routing and vehicle dispatch'),
        (3, 1, 'Physical Security & Safety Control', 'DEP-SEC', 'Continuous facility surveillance, keycard access and night patrols'),
        (4, 1, 'NOC & Infrastructure Tech', 'DEP-NOC', 'Round-the-clock server, network monitoring and critical systems support'),
        (5, 1, 'Facilities & Sanitization', 'DEP-FAC', 'Scheduled deep cleaning, equipment maintenance and overnight readiness')
    ");

    // Shift Templates (Crucial for 24h cycle: Morning, Afternoon, Overnight Graveyard)
    $pdo->exec("
        INSERT INTO shift_templates (id, branch_id, name, shift_code, start_time, end_time, is_overnight, duration_hours, color, min_staff_required, description) VALUES
        (1, NULL, 'Morning Operational Shift', 'SFT-MORN', '06:00:00', '14:00:00', 0, 8.00, '#0284c7', 4, 'Day opening and peak morning business operations'),
        (2, NULL, 'Afternoon / Swing Shift', 'SFT-AFT', '14:00:00', '22:00:00', 0, 8.00, '#f59e0b', 4, 'Peak daytime customer traffic, handoff from morning team'),
        (3, NULL, 'Graveyard / Overnight Shift', 'SFT-NGT', '22:00:00', '06:00:00', 1, 8.00, '#8b5cf6', 3, 'Crucial overnight 22:00 to 06:00 continuous shift. Next-day transition.'),
        (4, NULL, '12-Hour Continuous Day', 'SFT-12D', '07:00:00', '19:00:00', 0, 12.00, '#10b981', 2, 'Continuous 12-hour daytime coverage for rapid emergency response'),
        (5, NULL, '12-Hour Continuous Night', 'SFT-12N', '19:00:00', '07:00:00', 1, 12.00, '#ec4899', 2, 'Continuous 12-hour overnight coverage for technical operations')
    ");

    // Employees
    $pdo->exec("
        INSERT INTO employees (id, branch_id, department_id, employee_code, first_name, last_name, email, phone, role_title, employment_type, shift_preference, hourly_rate, max_weekly_hours, skills, status, hire_date, emergency_contact, avatar_color) VALUES
        (1, 1, 1, 'EMP-247-01', 'Peter', 'Mwewa', 'admin@247ops.com', '+1 (555) 001-2401', 'Chief of Operations', 'full_time', 'any', 45.00, 40, '[\"Executive Operations\", \"Crisis Management\", \"Resource Scheduling\"]', 'active', '2023-01-15', 'Grace Mwewa (+1 555-901-0001)', '#3b82f6'),
        (2, 1, 1, 'EMP-247-02', 'Mutinta', 'Mayibbe', 'manager.downtown@247ops.com', '+1 (555) 001-2402', 'Branch General Manager', 'full_time', 'morning', 38.00, 40, '[\"Workforce Planning\", \"Quality Assurance\", \"Audit Oversight\"]', 'active', '2023-03-01', 'Rachel Mayibbe (+1 555-901-0002)', '#0ea5e9'),
        (3, 1, 4, 'EMP-247-03', 'Nerbart', 'Tembo', 'supervisor.night@247ops.com', '+1 (555) 001-2403', 'Overnight Shift Supervisor', 'night_specialist', 'night', 36.00, 40, '[\"Night Shift Protocol\", \"Emergency Response\", \"Handover Certification\"]', 'active', '2023-05-10', 'Daniel Tembo (+1 555-901-0003)', '#8b5cf6'),
        (4, 1, 4, 'EMP-247-04', 'Milimo', 'Tandeo', 'marcus.tech@247ops.com', '+1 (555) 001-2404', 'NOC Support Engineer', 'full_time', 'night', 32.00, 40, '[\"Network Infrastructure\", \"Incident Escalation\", \"Hardware Telemetry\"]', 'active', '2023-08-12', 'Grace Tandeo (+1 555-901-0004)', '#06b6d4'),
        (5, 2, 2, 'EMP-247-05', 'Evans', 'Phiri', 'elena.logistics@247ops.com', '+1 (555) 001-2405', 'Logistics Hub Manager', 'full_time', 'any', 38.00, 40, '[\"Continuous Supply Chain\", \"Fleet Optimization\", \"Shift Orchestration\"]', 'active', '2023-02-20', 'Victor Phiri (+1 555-901-0005)', '#f59e0b'),
        (6, 2, 2, 'EMP-247-06', 'Meek', 'Musoka', 'carlos.mendez@247ops.com', '+1 (555) 001-2406', 'Night Dispatch Coordinator', 'night_specialist', 'night', 28.00, 40, '[\"Radio Dispatch\", \"Fleet Route Tracking\", \"HAZMAT Procedures\"]', 'active', '2023-09-01', 'Maria Musoka (+1 555-901-0006)', '#10b981'),
        (7, 1, 1, 'EMP-247-07', 'Priya', 'Sharma', 'priya.sharma@247ops.com', '+1 (555) 001-2407', 'Day Shift Supervisor', 'full_time', 'morning', 35.00, 40, '[\"Team Leadership\", \"Customer Service Escalation\", \"Punctuality Tracking\"]', 'active', '2023-06-15', 'Rohan Sharma (+1 555-901-0007)', '#ec4899'),
        (8, 1, 3, 'EMP-247-08', 'James', 'Thorne', 'james.thorne@247ops.com', '+1 (555) 001-2408', 'Lead Night Security Officer', 'night_specialist', 'night', 29.00, 40, '[\"CCTV Surveillance\", \"First Aid & CPR\", \"Access Badge Control\"]', 'active', '2023-07-22', 'Laura Thorne (+1 555-901-0008)', '#64748b'),
        (9, 1, 1, 'EMP-247-09', 'Aisha', 'Khan', 'aisha.khan@247ops.com', '+1 (555) 001-2409', 'Customer Care Specialist', 'full_time', 'afternoon', 26.00, 40, '[\"Omnichannel CRM\", \"Conflict Resolution\", \"Bilingual English/Spanish\"]', 'active', '2023-10-05', 'Tariq Khan (+1 555-901-0009)', '#a855f7'),
        (10, 3, 1, 'EMP-247-10', 'Kenji', 'Sato', 'kenji.sato@247ops.com', '+1 (555) 001-2410', 'Airport Hub Supervisor', 'full_time', 'afternoon', 35.00, 40, '[\"Rapid Service Dispatch\", \"International Passenger Protocols\", \"Multi-lingual\"]', 'active', '2023-04-18', 'Yuki Sato (+1 555-901-0010)', '#f97316'),
        (11, 1, 5, 'EMP-247-11', 'Lucas', 'Silva', 'lucas.silva@247ops.com', '+1 (555) 001-2411', 'Overnight Facilities Tech', 'night_specialist', 'night', 27.00, 40, '[\"HVAC Diagnostics\", \"Deep Sanitization\", \"Emergency Power Backup\"]', 'active', '2023-11-10', 'Camila Silva (+1 555-901-0011)', '#14b8a6'),
        (12, 2, 2, 'EMP-247-12', 'Maya', 'Lin', 'maya.lin@247ops.com', '+1 (555) 001-2412', '24/7 Logistics Controller', 'full_time', 'morning', 28.00, 40, '[\"Inventory Telemetry\", \"Automated Sorting Systems\", \"Forklift Certified\"]', 'active', '2024-01-08', 'Chen Lin (+1 555-901-0012)', '#6366f1')
    ");

    // Users with bcrypt password 'admin123'
    $defaultHash = password_hash('admin123', PASSWORD_BCRYPT);
    $userStmt = $pdo->prepare("
        INSERT INTO users (id, employee_id, username, email, password_hash, role, status) VALUES
        (1, 1, 'admin', 'admin@247ops.com', ?, 'admin', 'active'),
        (2, 2, 'manager_dt', 'manager.downtown@247ops.com', ?, 'manager', 'active'),
        (3, 3, 'supervisor_night', 'supervisor.night@247ops.com', ?, 'supervisor', 'active'),
        (4, 4, 'marcus_tech', 'marcus.tech@247ops.com', ?, 'staff', 'active'),
        (5, 5, 'manager_log', 'elena.logistics@247ops.com', ?, 'manager', 'active'),
        (6, 6, 'carlos_mendez', 'carlos.mendez@247ops.com', ?, 'staff', 'active'),
        (7, 7, 'priya_sharma', 'priya.sharma@247ops.com', ?, 'supervisor', 'active'),
        (8, 8, 'james_thorne', 'james.thorne@247ops.com', ?, 'staff', 'active'),
        (9, 9, 'aisha_khan', 'aisha.khan@247ops.com', ?, 'staff', 'active'),
        (10, 10, 'kenji_sato', 'kenji.sato@247ops.com', ?, 'supervisor', 'active')
    ");
    $userStmt->execute(array_fill(0, 10, $defaultHash));

    // 3. Dynamic Relative Dates Seeding (Past 3 days, Today, Next 3 days)
    echo "[4/4] Generating continuous 24-hour schedules, attendance, tasks and handovers...\n";
    $today = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $twoDaysAgo = date('Y-m-d', strtotime('-2 days'));
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $twoDaysLater = date('Y-m-d', strtotime('+2 days'));

    // Schedules covering 24 hours: Morning, Afternoon, Overnight Graveyard
    $scheduleData = [
        // Two Days Ago
        [1, 1, 7, $twoDaysAgo, "$twoDaysAgo 06:00:00", "$twoDaysAgo 14:00:00", 'completed'],
        [1, 1, 2, $twoDaysAgo, "$twoDaysAgo 06:00:00", "$twoDaysAgo 14:00:00", 'completed'],
        [1, 2, 9, $twoDaysAgo, "$twoDaysAgo 14:00:00", "$twoDaysAgo 22:00:00", 'completed'],
        [1, 3, 3, $twoDaysAgo, "$twoDaysAgo 22:00:00", "$yesterday 06:00:00", 'completed'], // Overnight!
        [1, 3, 4, $twoDaysAgo, "$twoDaysAgo 22:00:00", "$yesterday 06:00:00", 'completed'],
        [1, 3, 8, $twoDaysAgo, "$twoDaysAgo 22:00:00", "$yesterday 06:00:00", 'completed'],

        // Yesterday
        [1, 1, 7, $yesterday, "$yesterday 06:00:00", "$yesterday 14:00:00", 'completed'],
        [1, 1, 1, $yesterday, "$yesterday 06:00:00", "$yesterday 14:00:00", 'completed'],
        [1, 2, 9, $yesterday, "$yesterday 14:00:00", "$yesterday 22:00:00", 'completed'],
        [1, 2, 2, $yesterday, "$yesterday 14:00:00", "$yesterday 22:00:00", 'completed'],
        [1, 3, 3, $yesterday, "$yesterday 22:00:00", "$today 06:00:00", 'completed'], // Overnight!
        [1, 3, 4, $yesterday, "$yesterday 22:00:00", "$today 06:00:00", 'completed'],
        [1, 3, 8, $yesterday, "$yesterday 22:00:00", "$today 06:00:00", 'completed'],
        [1, 3, 11, $yesterday, "$yesterday 22:00:00", "$today 06:00:00", 'completed'],

        // TODAY (Active 24h cycle)
        [1, 1, 7, $today, "$today 06:00:00", "$today 14:00:00", 'completed'],
        [1, 1, 2, $today, "$today 06:00:00", "$today 14:00:00", 'completed'],
        [1, 2, 9, $today, "$today 14:00:00", "$today 22:00:00", 'in_progress'],
        [1, 2, 1, $today, "$today 14:00:00", "$today 22:00:00", 'in_progress'],
        [1, 3, 3, $today, "$today 22:00:00", "$tomorrow 06:00:00", 'scheduled'], // Overnight tonight!
        [1, 3, 4, $today, "$today 22:00:00", "$tomorrow 06:00:00", 'scheduled'],
        [1, 3, 8, $today, "$today 22:00:00", "$tomorrow 06:00:00", 'scheduled'],
        [1, 3, 11, $today, "$today 22:00:00", "$tomorrow 06:00:00", 'scheduled'],

        // Branch 2: Logistics continuous
        [2, 4, 5, $today, "$today 07:00:00", "$today 19:00:00", 'in_progress'],
        [2, 5, 6, $today, "$today 19:00:00", "$tomorrow 07:00:00", 'scheduled'], // 12hr night!
        [2, 4, 12, $today, "$today 07:00:00", "$today 19:00:00", 'in_progress'],

        // Tomorrow
        [1, 1, 7, $tomorrow, "$tomorrow 06:00:00", "$tomorrow 14:00:00", 'scheduled'],
        [1, 1, 2, $tomorrow, "$tomorrow 06:00:00", "$tomorrow 14:00:00", 'scheduled'],
        [1, 2, 9, $tomorrow, "$tomorrow 14:00:00", "$tomorrow 22:00:00", 'scheduled'],
        [1, 3, 3, $tomorrow, "$tomorrow 22:00:00", "$twoDaysLater 06:00:00", 'scheduled'],
        [1, 3, 4, $tomorrow, "$tomorrow 22:00:00", "$twoDaysLater 06:00:00", 'scheduled'],
        [1, 3, 8, $tomorrow, "$tomorrow 22:00:00", "$twoDaysLater 06:00:00", 'scheduled']
    ];

    $shiftAssignStmt = $pdo->prepare("
        INSERT INTO shift_assignments (branch_id, shift_template_id, employee_id, shift_date, start_datetime, end_datetime, status, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1)
    ");
    foreach ($scheduleData as $s) {
        $shiftAssignStmt->execute($s);
    }

    // Attendance Records: Including yesterday overnight and today's active workforce
    $attendanceData = [
        // Yesterday Overnight Shift (22:00 yesterday to 06:00 today)
        [1, 3, 11, "$yesterday 21:55:00", "$today 06:05:00", "$yesterday 22:00:00", "$today 06:00:00", 'present', 8.00, 0.17, 8.00, 0, 0, 'Completed night shift seamlessly. Handover to morning supervisor.'],
        [1, 4, 12, "$yesterday 21:58:00", "$today 06:00:00", "$yesterday 22:00:00", "$today 06:00:00", 'present', 8.00, 0.00, 8.00, 0, 0, 'NOC telemetry active throughout the night.'],
        [1, 8, 13, "$yesterday 22:12:00", "$today 06:02:00", "$yesterday 22:00:00", "$today 06:00:00", 'late', 7.80, 0.00, 7.80, 12, 0, 'Late by 12 mins due to highway transit diversion.'],
        [1, 11, 14, "$yesterday 21:50:00", "$today 06:15:00", "$yesterday 22:00:00", "$today 06:00:00", 'overtime', 8.00, 0.42, 8.00, 0, 0, 'Overtime approved for HVAC pre-filter servicing.'],

        // Today Morning Shift (06:00 to 14:00)
        [1, 7, 15, "$today 05:55:00", "$today 14:05:00", "$today 06:00:00", "$today 14:00:00", 'present', 8.00, 0.08, 0.00, 0, 0, 'Handed over operational logs to afternoon supervisor.'],
        [1, 2, 16, "$today 06:00:00", "$today 14:00:00", "$today 06:00:00", "$today 14:00:00", 'present', 8.00, 0.00, 0.00, 0, 0, 'Morning facility audit completed.'],

        // Today Afternoon Shift (Currently Clocked In!)
        [1, 9, 17, "$today 13:55:00", NULL, "$today 14:00:00", "$today 22:00:00", 'present', 6.50, 0.00, 0.00, 0, 0, 'On-duty: Handling peak afternoon customer desk.'],
        [1, 1, 18, "$today 13:58:00", NULL, "$today 14:00:00", "$today 22:00:00", 'present', 6.45, 0.00, 0.00, 0, 0, 'On-duty: Operations monitoring.'],
        [2, 5, 23, "$today 06:50:00", NULL, "$today 07:00:00", "$today 19:00:00", 'present', 11.50, 0.00, 0.00, 0, 0, '12-Hour continuous logistics floor monitoring.']
    ];

    $attStmt = $pdo->prepare("
        INSERT INTO attendance (branch_id, employee_id, shift_assignment_id, clock_in, clock_out, scheduled_in, scheduled_out, status, regular_hours, overtime_hours, night_hours, late_minutes, early_departure_minutes, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($attendanceData as $att) {
        $attStmt->execute($att);
    }

    // Breaks
    $pdo->exec("
        INSERT INTO breaks (attendance_id, employee_id, break_type, start_time, end_time, duration_minutes) VALUES
        (1, 3, 'night_refreshment', '$yesterday 23:45:00', '$yesterday 00:15:00', 30),
        (1, 3, 'rest', '$today 03:00:00', '$today 03:15:00', 15),
        (5, 7, 'meal', '$today 10:30:00', '$today 11:15:00', 45),
        (7, 9, 'meal', '$today 17:00:00', '$today 17:40:00', 40)
    ");

    // Tasks across continuous 24-hour cycles
    $tasksData = [
        [1, 1, 1, 'Morning Opening Physical Security Inspection', 'Inspect perimeter fences, verify keycard logs, ensure all frontline terminals are online.', 'high', 'completed', 7, $today, "$today 07:30:00", "$today 07:15:00", 7, 0, 'All checkpoints cleared.'],
        [1, 4, 3, 'Overnight Network Backup & Redundancy Test', 'Execute automated server snapshots, test secondary failover line, check latency across edge nodes.', 'urgent', 'completed', 4, $yesterday, "$today 03:30:00", "$today 03:22:00", 4, 1, 'Failover latency normal (12ms). Handed over report to morning tech.'],
        [1, 5, 3, 'Night Sanitization & HVAC Deep Filter Cycle', 'Perform automated chemical sanitation of operations floor and swap central intake filters.', 'medium', 'completed', 11, $yesterday, "$today 05:00:00", "$today 04:55:00", 11, 0, 'Filter cycle 100% nominal.'],
        [1, 1, 2, 'Afternoon Customer Peak Queue Audit', 'Ensure customer waiting time does not exceed 4 minutes during the 16:00-19:00 traffic surge.', 'high', 'in_progress', 9, $today, "$today 19:30:00", NULL, NULL, 0, NULL],
        [1, 4, 2, 'Critical UPS Battery Telemetry Verification', 'Verify battery bank voltage for UPS-A and UPS-B before evening switch-in.', 'urgent', 'in_progress', 4, $today, "$today 21:00:00", NULL, NULL, 1, 'Will hand over to incoming Night Tech if battery 4 delta remains above 0.3V.'],
        [1, 3, 3, 'Graveyard Perimeter Patrol & Vault Access Verification', 'Perform 3-point physical audit at 23:30, 02:00, and 04:30. Log keybox seal numbers.', 'high', 'pending', 8, $today, "$tomorrow 04:30:00", NULL, NULL, 1, 'Scheduled for incoming overnight team.'],
        [2, 2, 4, 'Fleet Dispatch Manifest Reconciliation', 'Review night freight dispatches, ensure all GPS telemetry is pinging central tracker.', 'medium', 'in_progress', 5, $today, "$today 18:00:00", NULL, NULL, 0, NULL]
    ];

    $taskStmt = $pdo->prepare("
        INSERT INTO tasks (branch_id, department_id, shift_template_id, title, description, priority, status, assigned_to_employee_id, shift_date, due_datetime, completed_at, completed_by, is_handover_task, handover_notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
    ");
    foreach ($tasksData as $t) {
        $taskStmt->execute($t);
    }

    // Task Checklists
    $pdo->exec("
        INSERT INTO task_checklists (task_id, item_text, is_completed, sort_order) VALUES
        (1, 'Check main entrance revolving doors & emergency exits', 1, 1),
        (1, 'Verify security CCTV matrix wall status', 1, 2),
        (1, 'Check frontline cash drawer seal integrity', 1, 3),
        (2, 'Trigger DB replica snapshot at 02:00', 1, 1),
        (2, 'Verify backup checksum integrity', 1, 2),
        (2, 'Upload audit log archive to cold vault', 1, 3),
        (4, 'Deploy additional queue barrier at kiosk 3', 1, 1),
        (4, 'Audit average wait times on ticketing display', 0, 2),
        (5, 'Inspect UPS Battery 1-12 cell voltages', 1, 1),
        (5, 'Check ambient battery room thermal sensor (< 22°C)', 0, 2),
        (6, 'Check gate 1 biometric sensor', 0, 1),
        (6, 'Verify fire alarm suppression valves', 0, 2),
        (6, 'Sign physical key lockbox register', 0, 3)
    ");

    // Customer Service Logs (24/7 Operations interactions)
    $customerLogs = [
        [1, 1, 3, 3, 4, 'CS-247-8801', 'Enterprise Cloud Logistics Corp', 'dispatch@ecl-freight.com', 'phone', 'urgent_request', 'high', 'resolved', 'Emergency late-night access clearance for cargo dock 4', 'Carrier driver arrived at 01:15 AM requiring expedited security clearance for cold storage transfer.', 'Verified driver credentials via dispatch API, issued temporary RFID badge, escorted to bay 4.', 'Resolved in 18 minutes. Driver cleared and departed.', 4, 18, 5, 0, "$yesterday 01:33:00"],
        [1, 1, 3, 4, 4, 'CS-247-8802', 'OmniNet Global Ltd', 'noc@omninet.org', 'radio', 'incident', 'critical_247', 'resolved', 'Night-time telemetry feed disconnect alert on Node 7', 'Automated ping failure alert triggered at 03:20 AM. NOC engineer alerted.', 'Rerouted connection to backup optic strand. Rebooted edge transceiver unit.', 'Full redundancy restored within 22 minutes. Incident post-mortem appended to morning handover.', 2, 22, 5, 1, "$yesterday 03:42:00"],
        [1, 1, 1, 7, 7, 'CS-247-8803', 'Apex Retail Group', '+1 555-882-9901', 'walk_in', 'inquiry', 'low', 'resolved', 'Corporate account shift billing and weekend coverage expansion', 'Client inquired about adding 24-hour dedicated security coverage starting next month.', 'Provided comprehensive 24/7 staffing tier package and SLA documentation.', 'Client signed exploratory agreement. Forwarded to account director.', 1, 25, 5, 0, "$today 09:30:00"],
        [1, 1, 2, 9, 9, 'CS-247-8804', 'Horizon Healthcare Systems', 'operations@horizon-health.net', 'emergency_line', 'urgent_request', 'critical_247', 'in_progress', 'Urgent medical consignment courier priority dispatch', 'Hospital pharmacy requesting emergency temperature-monitored courier for dialysis units.', 'Courier unit #14 dispatched with live GPS tracking beacon. ETA hospital 40 minutes.', 'Active tracking engaged. Monitoring temperature sensors live.', 3, 0, NULL, 1, NULL],
        [1, 1, 2, 9, 9, 'CS-247-8805', 'Metropolitan Courier Services', 'support@metrocourier.com', 'email', 'complaint', 'medium', 'open', 'Delayed receipt notification on batch #440-B', 'Customer reported delayed electronic delivery confirmation on 3:00 PM batch.', 'Investigating scanner synchronization delay with logistics server.', 'Under review by Meek Musoka.', 15, 0, NULL, 0, NULL]
    ];

    $custStmt = $pdo->prepare("
        INSERT INTO customer_service_logs (branch_id, department_id, shift_template_id, logged_by_employee_id, assigned_to_employee_id, ticket_number, customer_name, customer_contact, channel, category, severity, status, subject, description, action_taken, resolution_notes, response_time_minutes, resolution_time_minutes, satisfaction_rating, is_handover_flagged, resolved_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($customerLogs as $c) {
        $custStmt->execute($c);
    }

    // Shift Handover Records (The cornerstone of continuous 24h business operations!)
    $checklist1 = json_encode([
        ['category' => 'Facilities', 'item' => 'All exterior security doors locked & armed', 'status' => 'pass'],
        ['category' => 'Systems', 'item' => 'Server room HVAC operating at 19.5°C', 'status' => 'pass'],
        ['category' => 'Cash/Safe', 'item' => 'Cash register drawer balanced at ZMW 2,500.00', 'status' => 'pass'],
        ['category' => 'Incidents', 'item' => 'Node 7 optic flap resolved at 03:42 AM', 'status' => 'flagged']
    ]);

    $checklist2 = json_encode([
        ['category' => 'Facilities', 'item' => 'Front desk reception clean & supplied', 'status' => 'pass'],
        ['category' => 'Personnel', 'item' => 'All morning shift team members present and accounted for', 'status' => 'pass'],
        ['category' => 'Cash/Safe', 'item' => 'Midday deposit pre-counted and vaulted', 'status' => 'pass'],
        ['category' => 'Open Tasks', 'item' => 'UPS Battery verification handed off to afternoon shift', 'status' => 'flagged']
    ]);

    $handoverStmt = $pdo->prepare("
        INSERT INTO shift_handovers (branch_id, shift_date, outgoing_shift_template_id, incoming_shift_template_id, outgoing_supervisor_id, incoming_supervisor_id, status, handover_notes, facility_status, security_status, cash_status, equipment_status, checklist_json, outgoing_signed_at, incoming_signed_at) VALUES
        (1, ?, 3, 1, 3, 7, 'completed', 'Overnight graveyard shift smoothly transitioned. Node 7 telemetry hiccup resolved by Milimo. All perimeter patrols cleared.', 'normal', 'secure', 'reconciled', 'all_operational', ?, ?, ?),
        (1, ?, 1, 2, 7, 2, 'completed', 'Morning shift handover completed. High customer volume handled. UPS inspection handed to afternoon tech team.', 'normal', 'secure', 'reconciled', 'maintenance_required', ?, ?, ?),
        (1, ?, 2, 3, 2, 3, 'pending_signoff', 'Afternoon shift winding down. Urgent medical consignment dispatched to Horizon Healthcare. Handing over to Nerbart Tembo for overnight continuous operations.', 'needs_attention', 'secure', 'reconciled', 'all_operational', ?, ?, NULL)
    ");
    $handoverStmt->execute([
        $yesterday, $checklist1, "$today 05:58:00", "$today 06:05:00",
        $today, $checklist2, "$today 13:58:00", "$today 14:04:00",
        $today, $checklist2, "$today 21:50:00"
    ]);

    // Notifications
    $pdo->exec("
        INSERT INTO notifications (branch_id, user_id, type, title, message, severity, link_module, link_id, is_read) VALUES
        (1, 3, 'handover', 'Shift Handover Ready for Review', 'Afternoon shift manager Mutinta Mayibbe has submitted the shift handover log for your acceptance.', 'warning', 'handover', 3, 0),
        (1, 1, 'customer', 'Critical 24/7 Incident Dispatched', 'Urgent medical consignment courier dispatched for Horizon Healthcare Systems.', 'critical', 'customer_service', 4, 0),
        (1, 2, 'task', 'High-Priority Task Due Soon', 'Critical UPS Battery Telemetry Verification is scheduled for completion before 21:00.', 'warning', 'tasks', 5, 0),
        (1, 1, 'attendance', 'Shift Attendance Alert', 'Lead Security Officer recorded late arrival (12 min delta) on yesterday graveyard shift.', 'info', 'attendance', 3, 1)
    ");

    // Audit Logs
    $pdo->exec("
        INSERT INTO audit_logs (user_id, action, entity_type, entity_id, branch_id, details, ip_address) VALUES
        (1, 'SYSTEM_INIT', 'system', 1, 1, '{\"event\":\"24/7 Operations Database Initialized\",\"version\":\"1.0.0\"}', '127.0.0.1'),
        (3, 'CLOCK_IN', 'attendance', 1, 1, '{\"employee_id\":3,\"shift\":\"Graveyard / Overnight Shift\",\"time\":\"21:55:00\"}', '127.0.0.1'),
        (3, 'HANDOVER_SIGN', 'shift_handover', 1, 1, '{\"outgoing_supervisor\":\"Nerbart Tembo\",\"incoming_supervisor\":\"Priya Sharma\"}', '127.0.0.1'),
        (7, 'HANDOVER_ACCEPT', 'shift_handover', 1, 1, '{\"incoming_supervisor\":\"Priya Sharma\",\"status\":\"acknowledged\"}', '127.0.0.1'),
        (9, 'CUSTOMER_TICKET_CREATE', 'customer_service_logs', 4, 1, '{\"ticket\":\"CS-247-8804\",\"severity\":\"critical_247\"}', '127.0.0.1')
    ");

    // System Settings
    $pdo->exec("
        INSERT INTO system_settings (setting_key, setting_value, description) VALUES
        ('company_name', '24/7 Operations Network', 'Operating enterprise name'),
        ('operating_mode', 'continuous_24hr', 'Operations cycle mode (continuous_24hr, standard_business)'),
        ('grace_period_minutes', '15', 'Grace period before clock-in is flagged as late'),
        ('night_shift_start', '22:00:00', 'Official commencement time for night differential calculation'),
        ('night_shift_end', '06:00:00', 'Official conclusion time for night differential calculation'),
        ('night_differential_multiplier', '1.25', 'Pay multiplier for overnight hours worked (25% premium)'),
        ('min_rest_hours_between_shifts', '8', 'Fatigue management minimum rest interval between scheduled shifts'),
        ('auto_handover_alert_minutes', '30', 'Trigger handover notification minutes prior to shift end'),
        ('currency_symbol', 'ZMW', 'Currency symbol used in labor costing and reporting'),
        ('timezone', 'Europe/Berlin', 'System primary operating timezone')
    ");

    echo "\n=======================================================\n";
    echo "SUCCESS: 24/7 Operations Management System database '24hr' is fully configured!\n";
    echo "Default Demo Logins:\n";
    echo "  - System Admin:      admin@247ops.com               (password: admin123)\n";
    echo "  - Branch Manager:    manager.downtown@247ops.com    (password: admin123)\n";
    echo "  - Night Supervisor:  supervisor.night@247ops.com    (password: admin123)\n";
    echo "  - Support Engineer:  marcus.tech@247ops.com         (password: admin123)\n";
    echo "=======================================================\n";

} catch (Exception $e) {
    echo "ERROR during installation: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
