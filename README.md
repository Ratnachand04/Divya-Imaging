# Diagnostic Center Management System

Full-stack diagnostic center operations platform built with PHP, MariaDB, Apache, and Docker.

This project supports multi-role workflows for billing, reporting, finance, analytics, notifications, backups, and developer diagnostics.

## Table of Contents

- [Overview](#overview)
- [Core Features](#core-features)
- [Role Modules](#role-modules)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [Quick Start (Docker Recommended)](#quick-start-docker-recommended)
- [Deployment Modes](#deployment-modes)
- [Environment Variables](#environment-variables)
- [Access and Credentials](#access-and-credentials)
- [Database and Schema](#database-and-schema)
- [Backups and Restore](#backups-and-restore)
- [Notifications and Queue Processing](#notifications-and-queue-processing)
- [API Endpoints](#api-endpoints)
- [Security Notes](#security-notes)
- [Operations Commands](#operations-commands)
- [Troubleshooting](#troubleshooting)
- [Known Limitations](#known-limitations)
- [Production Hardening Checklist](#production-hardening-checklist)
- [Business View (Non-Technical)](#business-view-non-technical)

## Overview

The Diagnostic Center Management System is a role-based web application for end-to-end diagnostic operations.

Main capabilities include:

- Patient registration with generated UID format (`DCYYYYNNNN`)
- Bill generation and payment tracking
- Edit-request workflow for controlled billing corrections
- Financial operations (expenses, payouts, discount analytics)
- Writer workflow for report templates and final report upload
- Role dashboards with analytics and charts
- Notification queue system (email and simulated WhatsApp)
- Automated monthly backup engine with searchable backup index
- Developer/Platform console for diagnostics, logs, and network tools

## Core Features

- Multi-role authentication and authorization (`manager`, `receptionist`, `accountant`, `writer`, `superadmin`, `platform_admin`)
- Dockerized runtime with Apache + PHP 8.2 + MariaDB + phpMyAdmin
- Persistent volumes for uploads, bills, reports, and DB data
- Automated startup checks and schema guard rails in container entrypoint
- Global + role + user settings resolution through `app_settings`
- Built-in error logging, system audit logging, and network diagnostics

## Role Modules

### Receptionist

- Generate bills and patient entries
- Edit bills and submit edit requests
- Update payment status and view bill history

Key files:

- `receptionist/generate_bill.php`
- `receptionist/process_bill.php`
- `receptionist/update_payment.php`
- `receptionist/bill_history.php`

### Manager

- Operational dashboard with KPI cards and charts
- Manage test catalog, doctors, commissions, and employees
- Approve/reject bill edit requests
- Payment and due-bill reporting

Key files:

- `manager/dashboard.php`
- `manager/requests.php`
- `manager/approve_request.php`
- `manager/manage_tests.php`
- `manager/manage_doctors.php`

### Accountant

- Earnings and cashflow dashboard
- Expense logging and tracking
- Doctor payout workflows and proof documents
- Discount and payment reporting

Key files:

- `accountant/dashboard.php`
- `accountant/manage_payments.php`
- `accountant/doctor_payouts.php`
- `accountant/log_expense.php`

### Writer

- Pending report workflow from bill items
- DOCX template management and preview
- Final report uploads and report views

Key files:

- `writer/dashboard.php`
- `writer/templates.php`
- `writer/templates_ajax.php`
- `writer/upload_final_report.php`

### Superadmin

- Center-wide analytics dashboard
- Global defaults and settings policy
- Notification composition and queue management
- Calendar/events, deep analysis, logs, and exports

Key files:

- `superadmin/dashboard.php`
- `superadmin/global_settings.php`
- `superadmin/notifications.php`
- `superadmin/process_queue.php`

### Platform Admin (Developer Console)

Legacy `developer` role is migrated to `platform_admin`.

Capabilities include:

- File manager
- Error logs
- Data backup manager
- IP/network diagnostics
- Database tools

Key files:

- `Ghost/dashboard.php`
- `Ghost/data_backup.php`
- `Ghost/ip_manager.php`
- `Ghost/manage_database.php`

## Tech Stack

- Backend: PHP 8.2
- Web server: Apache 2.4
- Database: MariaDB 10.4
- Containerization: Docker + Docker Compose
- PDF generation: `dompdf/dompdf`
- Mail transport: PHPMailer (runtime in queue processor)
- Frontend libs used in modules:
  - Chart.js (analytics dashboards)
  - Mammoth.js (DOCX preview for writer templates)
  - Tailwind CDN (parts of superadmin dashboard)

## Project Structure

```text
.
|- index.php / login.php / logout.php
|- includes/                 Shared auth, DB connection, helpers
|- api/                      AJAX/API endpoints
|- receptionist/             Billing desk workflows
|- manager/                  Operations and approvals
|- accountant/               Finance and payouts
|- writer/                   Reporting pipeline
|- superadmin/               Admin analytics + settings + notifications
|- Ghost/                    Platform admin console
|- data_backup/              Backup engine, index, search
|- docker/                   Entrypoint, apache, mysql init, ssl scripts
|- uploads/                  Persistent uploads
|- saved_bills/              Generated bill PDFs
|- final_reports/            Uploaded final reports
|- docker-compose.yml        Dev/full compose
|- docker-compose.deploy.yml Deploy/pull-image compose
|- diagnostic_center_db_.sql Main SQL seed/schema dump
```

## Quick Start (Docker Recommended)

### Prerequisites

- Docker Desktop (Windows) or Docker Engine (Linux)
- Docker Compose

### 1) Configure environment

```bash
# Windows
copy .env.example .env

# Linux/macOS
cp .env.example .env
```

Edit `.env` as needed.

### 2) Start services

```bash
docker compose up -d --build
```

### 3) Access

- Website: `http://localhost:8081`
- HTTPS (if enabled): `https://localhost:8443`
- phpMyAdmin: `http://localhost:8082`
- MariaDB host port: `3301`

## Deployment Modes

### A) Development/Build mode

Use `docker-compose.yml` (builds image from local code).

```bash
docker compose up -d --build
```

### B) Pull-and-run deploy mode

`deploy.bat` creates `.env` + `docker-compose.deploy.yml`, pulls the prebuilt image, imports SQL if needed, and starts services.

```bat
deploy.bat
```

### C) Build and push image

Use on your development machine before deploying elsewhere:

```bat
build-and-push.bat
```

## Environment Variables

Defined in `.env.example`:

- `APP_PORT` (default `8081`)
- `SSL_PORT` (default `8443`)
- `PMA_PORT` (default `8082`)
- `DB_PORT` (default `3301`)
- `DB_HOST` (default `db`)
- `DB_USER` (default `root`)
- `DB_PASS` (default `root_password`)
- `DB_NAME` (default `diagnostic_center_db`)
- `DB_EXTRA_USER`, `DB_EXTRA_PASS`
- `APACHE_SERVER_NAME`
- `ENABLE_SSL` (`true` or `false`)
- `PUBLIC_IP`, `LOCAL_IP`, `DUAL_IP_BIND`, `IP_CHECK_INTERVAL`

## Access and Credentials

### Important runtime behavior

- Platform account is enforced in startup/login flows:
  - Username: `platform`
  - Password: `password123`
  - Role: `platform_admin`

This is enforced by logic in:

- `login.php`
- `docker/entrypoint.sh`

For production, change these hardcoded values before deployment.

### Seeded users in SQL dump

`diagnostic_center_db_.sql` contains sample users with hashed passwords for existing roles.

## Database and Schema

### Initial load

- Main schema/data comes from `diagnostic_center_db_.sql`
- Docker init also runs `docker/mysql/init/02-platform-admin-migration.sql`

### Core tables (high-level)

- Billing: `bills`, `bill_items`, `bill_edit_requests`, `bill_edit_log`
- Clinical: `patients`, `tests`, `bill_item_screenings`
- Finance: `expenses`, `payment_history`, `doctor_test_payables`, `doctor_payout_history`
- Admin: `users`, `system_audit_log`, `notification_queue`, `calendar_events`
- Writer: `writer_report_print_logs`

### Runtime schema safeguards

- `docker/entrypoint.sh` ensures support tables exist:
  - `site_messages`
  - `error_logs`
  - `developer_settings`
  - `ip_diagnostics`
- `includes/functions.php` auto-manages `app_settings` and patient UID schema

## Backups and Restore

### Quick backup

```bat
backup-db.bat
```

```bash
./backup-db.sh
```

### Monthly backup scripts

```bat
monthly-backup.bat
```

```bash
./monthly-backup.sh
```

### In-app backup engine

- Engine: `data_backup/backup_engine.php`
- Index: `data_backup/backup_index.json`
- Search utilities: `data_backup/search_backups.php`
- Admin UI: `Ghost/data_backup.php`

### Automatic monthly backup

Container startup validates monthly backup and configures cron:

- Schedule: 1st day of month at 02:00
- Command: `php data_backup/backup_engine.php`

### Restore (Docker)

```bash
docker exec -i diagnostic-center-db mysql -u root -p<DB_PASS> diagnostic_center_db < backup.sql
```

## Notifications and Queue Processing

Workflow:

1. Superadmin composes message in `superadmin/notifications.php`
2. Entry inserted into `notification_queue` as `Queued`
3. `superadmin/process_queue.php` processes up to 5 queued records per run

Delivery behavior:

- Email: PHPMailer + SMTP config
- WhatsApp: simulated logging to `uploads/whatsapp.log`

SMTP configuration sources:

1. `includes/mail_config.php`
2. Environment variables (`SMTP_HOST`, `SMTP_USERNAME`, etc.)

If SMTP config is missing, processor runs in simulation mode and logs to `uploads/mail.log`.

## API Endpoints

### `api/generate_patient_uid.php`

- Method: GET
- Auth: none (as implemented)
- Response: next UID (`DCYYYYNNNN`)

### `api/check_patient_uid.php`

- Method: GET/POST (`uid`)
- Auth: none (as implemented)
- Response: patient payload if UID exists

### `api/notification_status.php`

- Method: GET (`action`)
- Auth: required (session + role)
- Actions:
  - `partial_paid` (manager/receptionist)
  - `manager_nav_counts` (manager)
  - `latest_request` (manager)

### `api/verify_manager_password.php`

- Method: POST (`password`)
- Auth: manager session required
- Response: password verification result

## Security Notes

- Role-based gatekeeping via `includes/auth_check.php`
- Sensitive file access blocked in root `.htaccess` (e.g. `.env`, SQL, shell scripts, compose files)
- DB credentials read from environment in `includes/db_connect.php`
- Errors can be logged into `error_logs` table

Important security risks to address before production:

- Hardcoded platform credential enforcement in `login.php` and `docker/entrypoint.sh`
- Plaintext SMTP credentials currently present in `includes/mail_config.php`
- Demo/legacy data exists in `diagnostic_center_db_.sql`

## Operations Commands

```bash
# Start services
docker compose up -d

# Rebuild and restart
docker compose up -d --build

# View status
docker compose ps

# Follow logs
docker compose logs -f web
docker compose logs -f db

# Stop
docker compose down

# Reset with volume deletion (destructive)
docker compose down -v
```

## Troubleshooting

### Website not opening

- Check container health: `docker compose ps`
- Check web logs: `docker compose logs web`
- Check port conflicts on `8081`, `8443`, `8082`, `3301`

### Database connection errors

- Verify DB container: `docker compose logs db`
- Confirm `.env` values (`DB_HOST`, `DB_PASS`, `DB_NAME`)

### Writer dashboard SQL error on `reporting_doctor`

- Older DB states may miss `bill_items.reporting_doctor`
- Current writer flow includes a compatibility `ALTER TABLE` guard, but run migration manually if needed

### Notifications marked sent but no real delivery

- Check if queue processor is in simulation mode
- Confirm SMTP host/username/password values
- Review logs in `uploads/mail.log` and `uploads/whatsapp.log`

### SSL issues

- Run `setup-ssl.bat` or `setup-ssl.sh`
- Set `ENABLE_SSL=true`
- Ensure cert files exist in `docker/ssl/`

### Permission issues on uploads/reports

```bash
docker exec -it diagnostic-center-web bash
chown -R www-data:www-data /var/www/html/uploads /var/www/html/saved_bills /var/www/html/final_reports
chmod -R 775 /var/www/html/uploads /var/www/html/saved_bills /var/www/html/final_reports
```

## Known Limitations

- WhatsApp integration is currently simulated, not connected to a provider API
- No automated test suite is included in this repository
- Some configuration is file-based and not secret-managed by default

## Production Hardening Checklist

- Replace hardcoded platform credentials in `login.php` and `docker/entrypoint.sh`
- Move SMTP credentials out of `includes/mail_config.php` into secure environment secrets
- Rotate all known/default credentials (`DB_PASS`, platform account, SMTP)
- Restrict network exposure (firewall + least-open ports)
- Enable HTTPS with valid CA-signed certificates
- Review and prune demo/sample data before going live
- Add regular offsite backup copy strategy (in addition to local monthly backups)

## Business View (Non-Technical)

### What this website does

This website helps a diagnostic center run daily operations from one place.

- Register patients and maintain patient records
- Generate and manage bills quickly at the front desk
- Track pending payments and follow up dues
- Manage report workflow between billing and writer teams
- Monitor staff performance and center financial health

### Business analysis dashboards

- Live performance indicators like total patients, tests, revenue, pending bills, and payouts
- Quick period filters (today/week/month/last month) for management review
- Visual charts for referral sources, payment methods, top tests, and doctor contribution
- Faster decision-making for pricing, staffing, and growth planning

### New and easy billing system

- Faster, simpler billing workflow for reception staff
- Automatic amount handling for gross, discount, net, paid, and balance
- Supports due and partial-paid tracking for better collections
- Bill edit request and approval flow to reduce billing mistakes
- Printable and shareable bill output for patients

### Employee management

- Role-based logins for receptionist, manager, accountant, writer, superadmin, and platform admin
- User activation/deactivation and role control
- Better accountability with audit logs and action history
- Easier onboarding and smoother responsibility handoff

### Center management

- Test catalog and pricing management
- Referral doctor and commission/payout tracking
- Expense and payment management for finance control
- Notifications for patients, doctors, and employees
- Centralized settings and backup tools for operational continuity

### Business impact

- Faster patient service and billing turnaround time
- Reduced manual errors and stronger process control
- Better visibility on revenue, expenses, and pending collections
- Improved coordination across all center departments
- Operational foundation that scales as the center grows
