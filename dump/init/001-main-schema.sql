-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 13, 2026 at 07:14 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

-- Data flow note:
-- This file must contain schema definitions only (CREATE/ALTER structure).
-- Table data is loaded through 500-data-flow-tunnel.sql from tables/*.sql.
-- Keep INSERT table data out of this main schema file.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `diagnostic_center_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `bills`
--

CREATE TABLE `bills` (
  `id` int(11) NOT NULL,
  `invoice_number` int(11) DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `receptionist_id` int(11) NOT NULL,
  `referral_type` enum('Doctor','Self','Other') NOT NULL,
  `referral_doctor_id` int(11) DEFAULT NULL,
  `gross_amount` decimal(10,2) NOT NULL,
  `discount` decimal(10,2) DEFAULT 0.00,
  `discount_by` enum('Center','Doctor') NOT NULL DEFAULT 'Center',
  `net_amount` decimal(10,2) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `balance_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_mode` varchar(50) DEFAULT NULL,
  `payment_status` enum('Paid','Due','Half Paid') NOT NULL DEFAULT 'Due',
  `bill_status` enum('Original','Re-Billed','Void') NOT NULL DEFAULT 'Original',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `referral_source_other` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bills`
--


-- --------------------------------------------------------

--
-- Table structure for table `bill_edit_log`
--

CREATE TABLE `bill_edit_log` (
  `id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `editor_id` int(11) NOT NULL,
  `reason_for_change` text NOT NULL,
  `previous_data_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`previous_data_json`)),
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bill_edit_log`
--


-- --------------------------------------------------------

--
-- Table structure for table `bill_edit_requests`
--

CREATE TABLE `bill_edit_requests` (
  `id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `receptionist_id` int(11) NOT NULL,
  `reason_for_change` text NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bill_edit_requests`
--


-- --------------------------------------------------------

--
-- Table structure for table `bill_items`
--

CREATE TABLE `bill_items` (
  `id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `test_id` int(11) NOT NULL,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `report_content` longtext DEFAULT NULL,
  `report_status` enum('Pending','Completed') DEFAULT 'Pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `item_status` tinyint(1) NOT NULL DEFAULT 0 COMMENT '0=Visible, 1=Deleted'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bill_items`
--


-- --------------------------------------------------------

--
-- Table structure for table `bill_item_screenings`
--

CREATE TABLE `bill_item_screenings` (
  `id` int(11) NOT NULL,
  `bill_item_id` int(11) NOT NULL,
  `screening_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `bill_item_screenings`
--


-- --------------------------------------------------------

--
-- Table structure for table `calendar_events`
--

CREATE TABLE `calendar_events` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `event_date` date NOT NULL,
  `event_type` enum('Doctor Event','Company Event','Holiday','Birthday','Anniversary','Other') NOT NULL,
  `details` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `calendar_events`
--


-- --------------------------------------------------------

--
-- Table structure for table `doctor_payout_history`
--

CREATE TABLE `doctor_payout_history` (
  `id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `payout_amount` decimal(10,2) NOT NULL,
  `payout_period_start` date NOT NULL,
  `payout_period_end` date NOT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `accountant_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `doctor_payout_history`
--


-- --------------------------------------------------------

--
-- Table structure for table `doctor_test_payables`
--

CREATE TABLE `doctor_test_payables` (
  `id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `test_id` int(11) NOT NULL,
  `payable_amount` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `expense_type` varchar(100) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` varchar(50) DEFAULT NULL,
  `proof_path` varchar(255) DEFAULT NULL,
  `accountant_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `expenses`
--


-- --------------------------------------------------------

--
-- Table structure for table `notification_queue`
--

CREATE TABLE `notification_queue` (
  `id` int(11) NOT NULL,
  `recipient_group` varchar(50) DEFAULT NULL,
  `recipient_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recipient_data`)),
  `recipient_count` int(11) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `channels` varchar(100) DEFAULT NULL,
  `status` varchar(20) DEFAULT 'Queued',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `id` int(11) NOT NULL,
  `registration_id` varchar(12) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `sex` enum('Male','Female','Other') NOT NULL,
  `age` int(11) NOT NULL,
  `address` text DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `mobile_number` varchar(15) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patients`
--


-- --------------------------------------------------------

--
-- Table structure for table `payment_history`
--

CREATE TABLE `payment_history` (
  `id` int(11) NOT NULL,
  `bill_id` int(11) NOT NULL,
  `amount_paid_in_txn` decimal(10,2) NOT NULL,
  `previous_amount_paid` decimal(10,2) NOT NULL,
  `new_total_amount_paid` decimal(10,2) NOT NULL,
  `payment_mode` varchar(50) NOT NULL,
  `user_id` int(11) NOT NULL,
  `paid_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payment_history`
--


-- --------------------------------------------------------

--
-- Table structure for table `referral_doctors`
--

CREATE TABLE `referral_doctors` (
  `id` int(11) NOT NULL,
  `doctor_name` varchar(100) NOT NULL,
  `hospital_name` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `phone_number` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `referral_doctors`
--


-- --------------------------------------------------------

--
-- Table structure for table `system_audit_log`
--

CREATE TABLE `system_audit_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `action_type` varchar(100) NOT NULL COMMENT 'e.g., DELETED_BILL, CHANGED_PASSWORD, DELETED_DOCTOR',
  `target_id` int(11) DEFAULT NULL COMMENT 'ID of the affected record, e.g., bill_id',
  `details` text DEFAULT NULL COMMENT 'More context about the action',
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_audit_log`
--


-- --------------------------------------------------------

--
-- Table structure for table `tests`
--

CREATE TABLE `tests` (
  `id` int(11) NOT NULL,
  `main_test_name` varchar(100) NOT NULL,
  `sub_test_name` varchar(100) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `default_payable_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `report_format` longtext DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `document` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tests`
--


-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('manager','receptionist','accountant','writer','superadmin','platform_admin') NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--


-- --------------------------------------------------------

--
-- Table structure for table `writer_report_print_logs`
--

CREATE TABLE `writer_report_print_logs` (
  `id` int(10) UNSIGNED NOT NULL,
  `bill_item_id` int(10) UNSIGNED NOT NULL,
  `printed_by` int(10) UNSIGNED NOT NULL,
  `printed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `writer_report_print_logs`
--


-- --------------------------------------------------------
-- Additional support/runtime tables (schema-only)
-- --------------------------------------------------------

--
-- Table structure for table `site_messages`
--

CREATE TABLE `site_messages` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `type` enum('info','warning','maintenance','success','danger') DEFAULT 'info',
  `show_as_popup` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `error_logs`
--

CREATE TABLE `error_logs` (
  `id` int(11) NOT NULL,
  `error_level` int(11) DEFAULT NULL,
  `error_message` text DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `line_number` int(11) DEFAULT NULL,
  `request_url` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `developer_settings`
--

CREATE TABLE `developer_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `ip_diagnostics`
--

CREATE TABLE `ip_diagnostics` (
  `id` int(11) NOT NULL,
  `check_type` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `port` int(11) DEFAULT NULL,
  `status` enum('ok','error','warning') NOT NULL DEFAULT 'ok',
  `message` text DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `id` int(11) NOT NULL,
  `setting_scope` varchar(50) NOT NULL DEFAULT 'global',
  `scope_id` int(11) NOT NULL DEFAULT 0,
  `setting_key` varchar(120) NOT NULL,
  `setting_value` longtext DEFAULT NULL,
  `value_type` varchar(20) NOT NULL DEFAULT 'string',
  `category` varchar(80) DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `updated_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------
-- Package feature schema rollout (merged into main schema)
-- --------------------------------------------------------

CREATE TABLE IF NOT EXISTS test_packages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_code VARCHAR(50) NOT NULL UNIQUE,
  package_name VARCHAR(255) NOT NULL,
  description TEXT DEFAULT NULL,
  total_base_price DECIMAL(10,2) DEFAULT 0.00,
  package_price DECIMAL(10,2) NOT NULL,
  discount_amount DECIMAL(10,2) DEFAULT 0.00,
  discount_percent DECIMAL(5,2) DEFAULT 0.00,
  status ENUM('active','inactive') DEFAULT 'active',
  created_by INT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS package_tests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  package_id INT NOT NULL,
  test_id INT NOT NULL,
  base_test_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  package_test_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  display_order INT DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_package_test (package_id, test_id)
);

ALTER TABLE bill_items
  MODIFY COLUMN test_id INT NULL,
  ADD COLUMN IF NOT EXISTS package_id INT DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS is_package TINYINT(1) DEFAULT 0,
  ADD COLUMN IF NOT EXISTS item_type ENUM('test','package') DEFAULT 'test',
  ADD COLUMN IF NOT EXISTS package_name VARCHAR(255) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS package_discount DECIMAL(10,2) DEFAULT 0.00;

SET @fk_bill_items_package_exists = (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bill_items'
    AND CONSTRAINT_NAME = 'fk_bill_items_package'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @fk_bill_items_package_sql = IF(
  @fk_bill_items_package_exists = 0,
  'ALTER TABLE bill_items ADD CONSTRAINT fk_bill_items_package FOREIGN KEY (package_id) REFERENCES test_packages(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt_fk_bill_items_package FROM @fk_bill_items_package_sql;
EXECUTE stmt_fk_bill_items_package;
DEALLOCATE PREPARE stmt_fk_bill_items_package;

CREATE TABLE IF NOT EXISTS bill_package_items (
  id INT AUTO_INCREMENT PRIMARY KEY,
  bill_id INT NOT NULL,
  bill_item_id INT NOT NULL,
  package_id INT NOT NULL,
  test_id INT NOT NULL,
  test_name VARCHAR(255) NOT NULL,
  base_test_price DECIMAL(10,2) DEFAULT 0.00,
  package_test_price DECIMAL(10,2) DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

SET @idx_package_name_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'test_packages'
    AND INDEX_NAME = 'idx_package_name'
);
SET @idx_package_name_sql = IF(
  @idx_package_name_exists = 0,
  'CREATE INDEX idx_package_name ON test_packages(package_name)',
  'SELECT 1'
);
PREPARE stmt_idx_package_name FROM @idx_package_name_sql;
EXECUTE stmt_idx_package_name;
DEALLOCATE PREPARE stmt_idx_package_name;

SET @idx_package_status_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'test_packages'
    AND INDEX_NAME = 'idx_package_status'
);
SET @idx_package_status_sql = IF(
  @idx_package_status_exists = 0,
  'CREATE INDEX idx_package_status ON test_packages(status)',
  'SELECT 1'
);
PREPARE stmt_idx_package_status FROM @idx_package_status_sql;
EXECUTE stmt_idx_package_status;
DEALLOCATE PREPARE stmt_idx_package_status;

SET @idx_bill_items_package_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bill_items'
    AND INDEX_NAME = 'idx_bill_items_package'
);
SET @idx_bill_items_package_sql = IF(
  @idx_bill_items_package_exists = 0,
  'CREATE INDEX idx_bill_items_package ON bill_items(package_id)',
  'SELECT 1'
);
PREPARE stmt_idx_bill_items_package FROM @idx_bill_items_package_sql;
EXECUTE stmt_idx_bill_items_package;
DEALLOCATE PREPARE stmt_idx_bill_items_package;

SET @idx_bill_package_items_package_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'bill_package_items'
    AND INDEX_NAME = 'idx_bill_package_items_package'
);
SET @idx_bill_package_items_package_sql = IF(
  @idx_bill_package_items_package_exists = 0,
  'CREATE INDEX idx_bill_package_items_package ON bill_package_items(package_id)',
  'SELECT 1'
);
PREPARE stmt_idx_bill_package_items_package FROM @idx_bill_package_items_package_sql;
EXECUTE stmt_idx_bill_package_items_package;
DEALLOCATE PREPARE stmt_idx_bill_package_items_package;

--
