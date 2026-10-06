-- =======================================================
-- 24/7 - 24-Hour Business Operations Management System
-- Database Schema: 24hr
-- =======================================================

CREATE DATABASE IF NOT EXISTS `24hr` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `24hr`;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Businesses
DROP TABLE IF EXISTS `businesses`;
CREATE TABLE `businesses` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `tagline` VARCHAR(255) DEFAULT 'Continuous 24-Hour Operations',
    `operating_model` VARCHAR(50) DEFAULT '24/7/365 Continuous',
    `timezone` VARCHAR(50) DEFAULT 'UTC+02:00',
    `currency` VARCHAR(10) DEFAULT 'ZMW',
    `address` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Branches
DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `business_id` INT NOT NULL DEFAULT 1,
    `name` VARCHAR(150) NOT NULL,
    `code` VARCHAR(20) NOT NULL UNIQUE,
    `address` VARCHAR(255) DEFAULT NULL,
    `phone` VARCHAR(50) DEFAULT NULL,
    `email` VARCHAR(100) DEFAULT NULL,
    `is_24hr` TINYINT(1) DEFAULT 1,
    `manager_name` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Departments
DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT DEFAULT NULL,
    `name` VARCHAR(100) NOT NULL,
    `code` VARCHAR(20) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Employees
DROP TABLE IF EXISTS `employees`;
CREATE TABLE `employees` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `department_id` INT NOT NULL,
    `employee_code` VARCHAR(30) NOT NULL UNIQUE,
    `first_name` VARCHAR(60) NOT NULL,
    `last_name` VARCHAR(60) NOT NULL,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `phone` VARCHAR(40) DEFAULT NULL,
    `role_title` VARCHAR(100) NOT NULL,
    `employment_type` ENUM('full_time', 'part_time', 'night_specialist', 'contractor') DEFAULT 'full_time',
    `shift_preference` ENUM('any', 'morning', 'afternoon', 'night') DEFAULT 'any',
    `hourly_rate` DECIMAL(10,2) DEFAULT 25.00,
    `max_weekly_hours` INT DEFAULT 40,
    `skills` TEXT DEFAULT NULL,
    `status` ENUM('active', 'inactive', 'on_leave') DEFAULT 'active',
    `hire_date` DATE NOT NULL,
    `emergency_contact` VARCHAR(150) DEFAULT NULL,
    `avatar_color` VARCHAR(20) DEFAULT '#06b6d4',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Users (Authentication & RBAC)
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT DEFAULT NULL,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `email` VARCHAR(120) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` ENUM('admin', 'manager', 'supervisor', 'staff') NOT NULL DEFAULT 'staff',
    `status` ENUM('active', 'inactive') DEFAULT 'active',
    `last_login` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Shift Templates
DROP TABLE IF EXISTS `shift_templates`;
CREATE TABLE `shift_templates` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT DEFAULT NULL,
    `name` VARCHAR(100) NOT NULL,
    `shift_code` VARCHAR(20) NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `is_overnight` TINYINT(1) DEFAULT 0,
    `duration_hours` DECIMAL(4,2) NOT NULL DEFAULT 8.00,
    `color` VARCHAR(20) DEFAULT '#3b82f6',
    `min_staff_required` INT DEFAULT 3,
    `description` TEXT DEFAULT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Shift Assignments
DROP TABLE IF EXISTS `shift_assignments`;
CREATE TABLE `shift_assignments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `shift_template_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `shift_date` DATE NOT NULL,
    `start_datetime` DATETIME NOT NULL,
    `end_datetime` DATETIME NOT NULL,
    `status` ENUM('scheduled', 'confirmed', 'in_progress', 'completed', 'swapped', 'cancelled') DEFAULT 'scheduled',
    `notes` TEXT DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`shift_template_id`) REFERENCES `shift_templates`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Shift Swaps
DROP TABLE IF EXISTS `shift_swaps`;
CREATE TABLE `shift_swaps` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `shift_assignment_id` INT NOT NULL,
    `requester_id` INT NOT NULL,
    `target_employee_id` INT NOT NULL,
    `status` ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    `reason` TEXT DEFAULT NULL,
    `reviewed_by` INT DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `requested_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`shift_assignment_id`) REFERENCES `shift_assignments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`requester_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`target_employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Attendance
DROP TABLE IF EXISTS `attendance`;
CREATE TABLE `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `shift_assignment_id` INT DEFAULT NULL,
    `clock_in` DATETIME NOT NULL,
    `clock_out` DATETIME DEFAULT NULL,
    `scheduled_in` DATETIME DEFAULT NULL,
    `scheduled_out` DATETIME DEFAULT NULL,
    `status` ENUM('present', 'late', 'left_early', 'overtime', 'absent', 'manual_adjustment') DEFAULT 'present',
    `regular_hours` DECIMAL(5,2) DEFAULT 0.00,
    `overtime_hours` DECIMAL(5,2) DEFAULT 0.00,
    `night_hours` DECIMAL(5,2) DEFAULT 0.00,
    `late_minutes` INT DEFAULT 0,
    `early_departure_minutes` INT DEFAULT 0,
    `clock_in_ip` VARCHAR(45) DEFAULT '127.0.0.1',
    `clock_out_ip` VARCHAR(45) DEFAULT NULL,
    `notes` TEXT DEFAULT NULL,
    `verified_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`shift_assignment_id`) REFERENCES `shift_assignments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Breaks
DROP TABLE IF EXISTS `breaks`;
CREATE TABLE `breaks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `attendance_id` INT NOT NULL,
    `employee_id` INT NOT NULL,
    `break_type` ENUM('meal', 'rest', 'night_refreshment') DEFAULT 'meal',
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME DEFAULT NULL,
    `duration_minutes` INT DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`attendance_id`) REFERENCES `attendance`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Tasks
DROP TABLE IF EXISTS `tasks`;
CREATE TABLE `tasks` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `department_id` INT DEFAULT NULL,
    `shift_template_id` INT DEFAULT NULL,
    `shift_assignment_id` INT DEFAULT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `priority` ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    `status` ENUM('pending', 'in_progress', 'under_review', 'completed', 'escalated') DEFAULT 'pending',
    `assigned_to_employee_id` INT DEFAULT NULL,
    `shift_date` DATE NOT NULL,
    `due_datetime` DATETIME NOT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `completed_by` INT DEFAULT NULL,
    `is_handover_task` TINYINT(1) DEFAULT 0,
    `handover_notes` TEXT DEFAULT NULL,
    `created_by` INT DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`shift_template_id`) REFERENCES `shift_templates`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to_employee_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Task Checklists
DROP TABLE IF EXISTS `task_checklists`;
CREATE TABLE `task_checklists` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT NOT NULL,
    `item_text` VARCHAR(255) NOT NULL,
    `is_completed` TINYINT(1) DEFAULT 0,
    `completed_at` DATETIME DEFAULT NULL,
    `sort_order` INT DEFAULT 0,
    FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Customer Service Logs
DROP TABLE IF EXISTS `customer_service_logs`;
CREATE TABLE `customer_service_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `department_id` INT DEFAULT NULL,
    `shift_template_id` INT DEFAULT NULL,
    `logged_by_employee_id` INT NOT NULL,
    `assigned_to_employee_id` INT DEFAULT NULL,
    `ticket_number` VARCHAR(50) NOT NULL UNIQUE,
    `customer_name` VARCHAR(100) NOT NULL,
    `customer_contact` VARCHAR(100) DEFAULT NULL,
    `channel` ENUM('phone', 'walk_in', 'emergency_line', 'email', 'chat', 'radio') DEFAULT 'phone',
    `category` ENUM('inquiry', 'complaint', 'incident', 'urgent_request', 'maintenance_call', 'dispatch_issue') DEFAULT 'inquiry',
    `severity` ENUM('low', 'medium', 'high', 'critical_247') DEFAULT 'low',
    `status` ENUM('open', 'in_progress', 'pending_handover', 'resolved', 'escalated') DEFAULT 'open',
    `subject` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `action_taken` TEXT DEFAULT NULL,
    `resolution_notes` TEXT DEFAULT NULL,
    `response_time_minutes` INT DEFAULT 0,
    `resolution_time_minutes` INT DEFAULT 0,
    `satisfaction_rating` TINYINT DEFAULT NULL,
    `is_handover_flagged` TINYINT(1) DEFAULT 0,
    `resolved_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`shift_template_id`) REFERENCES `shift_templates`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`logged_by_employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`assigned_to_employee_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Shift Handovers
DROP TABLE IF EXISTS `shift_handovers`;
CREATE TABLE `shift_handovers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT NOT NULL,
    `shift_date` DATE NOT NULL,
    `outgoing_shift_template_id` INT NOT NULL,
    `incoming_shift_template_id` INT NOT NULL,
    `outgoing_supervisor_id` INT NOT NULL,
    `incoming_supervisor_id` INT DEFAULT NULL,
    `status` ENUM('draft', 'pending_signoff', 'acknowledged', 'completed') DEFAULT 'pending_signoff',
    `handover_notes` TEXT DEFAULT NULL,
    `facility_status` ENUM('normal', 'needs_attention', 'critical_issue') DEFAULT 'normal',
    `security_status` ENUM('secure', 'incident_logged', 'patrol_needed') DEFAULT 'secure',
    `cash_status` ENUM('reconciled', 'variance_reported', 'not_applicable') DEFAULT 'reconciled',
    `equipment_status` ENUM('all_operational', 'maintenance_required', 'faulty_unit') DEFAULT 'all_operational',
    `checklist_json` TEXT DEFAULT NULL,
    `outgoing_signed_at` DATETIME DEFAULT NULL,
    `incoming_signed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`branch_id`) REFERENCES `branches`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`outgoing_shift_template_id`) REFERENCES `shift_templates`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`incoming_shift_template_id`) REFERENCES `shift_templates`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`outgoing_supervisor_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`incoming_supervisor_id`) REFERENCES `employees`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. Notifications
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `branch_id` INT DEFAULT NULL,
    `user_id` INT DEFAULT NULL,
    `type` VARCHAR(50) DEFAULT 'system',
    `title` VARCHAR(150) NOT NULL,
    `message` TEXT NOT NULL,
    `severity` ENUM('info', 'warning', 'critical') DEFAULT 'info',
    `link_module` VARCHAR(50) DEFAULT NULL,
    `link_id` INT DEFAULT NULL,
    `is_read` TINYINT(1) DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Audit Logs
DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT DEFAULT NULL,
    `branch_id` INT DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT '127.0.0.1',
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. System Settings
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(60) NOT NULL UNIQUE,
    `setting_value` TEXT NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
