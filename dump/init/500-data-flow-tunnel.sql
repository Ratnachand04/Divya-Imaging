-- Data Flow Tunnel (Main Data Router)
-- --------------------------------------------------------
-- Purpose:
--   1) Keep schema and data separated.
--   2) Keep inline table data out of the main schema file.
--   3) Load each table's data from its own SQL file.
--
-- Execution order in dump/init:
--   - 001-main-schema.sql
--   - 500-data-flow-tunnel.sql  (this file)
--   - 900-post-schema.sql
--
-- Data file location:
--   - /docker-entrypoint-initdb.d/tables/
--
-- Maintenance rules:
--   - Add/update table rows only in individual files under tables/.
--   - Do not place INSERT data directly in 001-main-schema.sql.
--
-- Quick access paths:
--   - tables/100-data-bill_edit_log.sql
--   - tables/101-data-bill_edit_requests.sql
--   - tables/102-data-bill_item_screenings.sql
--   - tables/103-data-bill_items.sql
--   - tables/104-data-bills.sql
--   - tables/105-data-calendar_events.sql
--   - tables/106-data-doctor_payout_history.sql
--   - tables/107-data-expenses.sql
--   - tables/108-data-patients.sql
--   - tables/109-data-payment_history.sql
--   - tables/110-data-referral_doctors.sql
--   - tables/111-data-system_audit_log.sql
--   - tables/112-data-tests.sql
--   - tables/113-data-users.sql
--   - tables/114-data-writer_report_print_logs.sql
--   - tables/115-data-doctor_test_payables.sql
--   - tables/116-data-notification_queue.sql
--   - tables/117-data-site_messages.sql
--   - tables/118-data-error_logs.sql
--   - tables/119-data-developer_settings.sql
--   - tables/120-data-ip_diagnostics.sql
--   - tables/121-data-app_settings.sql
--   - tables/122-data-test_packages.sql
--   - tables/123-data-package_tests.sql
--   - tables/124-data-bill_package_items.sql

SOURCE /docker-entrypoint-initdb.d/tables/100-data-bill_edit_log.sql;
SOURCE /docker-entrypoint-initdb.d/tables/101-data-bill_edit_requests.sql;
SOURCE /docker-entrypoint-initdb.d/tables/102-data-bill_item_screenings.sql;
SOURCE /docker-entrypoint-initdb.d/tables/103-data-bill_items.sql;
SOURCE /docker-entrypoint-initdb.d/tables/104-data-bills.sql;
SOURCE /docker-entrypoint-initdb.d/tables/105-data-calendar_events.sql;
SOURCE /docker-entrypoint-initdb.d/tables/106-data-doctor_payout_history.sql;
SOURCE /docker-entrypoint-initdb.d/tables/107-data-expenses.sql;
SOURCE /docker-entrypoint-initdb.d/tables/108-data-patients.sql;
SOURCE /docker-entrypoint-initdb.d/tables/109-data-payment_history.sql;
SOURCE /docker-entrypoint-initdb.d/tables/110-data-referral_doctors.sql;
SOURCE /docker-entrypoint-initdb.d/tables/111-data-system_audit_log.sql;
SOURCE /docker-entrypoint-initdb.d/tables/112-data-tests.sql;
SOURCE /docker-entrypoint-initdb.d/tables/113-data-users.sql;
SOURCE /docker-entrypoint-initdb.d/tables/114-data-writer_report_print_logs.sql;
SOURCE /docker-entrypoint-initdb.d/tables/115-data-doctor_test_payables.sql;
SOURCE /docker-entrypoint-initdb.d/tables/116-data-notification_queue.sql;
SOURCE /docker-entrypoint-initdb.d/tables/117-data-site_messages.sql;
SOURCE /docker-entrypoint-initdb.d/tables/118-data-error_logs.sql;
SOURCE /docker-entrypoint-initdb.d/tables/119-data-developer_settings.sql;
SOURCE /docker-entrypoint-initdb.d/tables/120-data-ip_diagnostics.sql;
SOURCE /docker-entrypoint-initdb.d/tables/121-data-app_settings.sql;
SOURCE /docker-entrypoint-initdb.d/tables/122-data-test_packages.sql;
SOURCE /docker-entrypoint-initdb.d/tables/123-data-package_tests.sql;
SOURCE /docker-entrypoint-initdb.d/tables/124-data-bill_package_items.sql;
