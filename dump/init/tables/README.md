# Table Data Files Index

This folder stores table data in separate SQL files.
The main router file is:
- ../500-data-flow-tunnel.sql

Rules:
- Keep schema changes in ../001-main-schema.sql.
- Keep table row data (INSERT/seed data) in the files below.
- Do not put table data directly in ../001-main-schema.sql.

Table data files:
- 100-data-bill_edit_log.sql
- 101-data-bill_edit_requests.sql
- 102-data-bill_item_screenings.sql
- 103-data-bill_items.sql
- 104-data-bills.sql
- 105-data-calendar_events.sql
- 106-data-doctor_payout_history.sql
- 107-data-expenses.sql
- 108-data-patients.sql
- 109-data-payment_history.sql
- 110-data-referral_doctors.sql
- 111-data-system_audit_log.sql
- 112-data-tests.sql
- 113-data-users.sql
- 114-data-writer_report_print_logs.sql
- 115-data-doctor_test_payables.sql
- 116-data-notification_queue.sql
- 117-data-site_messages.sql
- 118-data-error_logs.sql
- 119-data-developer_settings.sql
- 120-data-ip_diagnostics.sql
- 121-data-app_settings.sql
- 122-data-test_packages.sql
- 123-data-package_tests.sql
- 124-data-bill_package_items.sql
