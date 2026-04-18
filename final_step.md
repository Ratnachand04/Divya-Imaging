# Deployment Guide: Docker

## Prerequisites
- Docker Desktop installed and running
- The `diagnostic_center_db_.sql` file in the project root

## Quick Deploy (Any Machine)

### Option 1: Run `deploy.bat` (Windows)
```
deploy.bat
```
This will:
1. Check Docker is running
2. Ask how you want to access the site (localhost / private IP / public IP / domain)
3. Create `.env` and `docker-compose.deploy.yml`
4. Pull the Docker image from Docker Hub
5. Import the SQL database
6. Start all containers

### Option 2: Manual Docker Compose
```bash
# Copy .env.example to .env and edit settings
copy .env.example .env

# Start all services
docker compose up -d
```

## Services & Ports

| Service     | Default Port | URL                          |
|-------------|-------------|-------------------------------|
| Website     | 8081        | http://localhost:8081          |
| phpMyAdmin  | 8082        | http://localhost:8082          |
| MariaDB     | 3301        | localhost:3301                 |
| HTTPS       | 8443        | https://localhost:8443         |

## Environment Variables (.env)

| Variable            | Default             | Description                |
|---------------------|---------------------|----------------------------|
| `APP_PORT`          | 8081                | Website HTTP port          |
| `SSL_PORT`          | 8443                | Website HTTPS port         |
| `PMA_PORT`          | 8082                | phpMyAdmin port            |
| `DB_PORT`           | 3301                | MariaDB port               |
| `DB_HOST`           | db                  | Database hostname          |
| `DB_USER`           | root                | Database username          |
| `DB_PASS`           | root_password       | Database root password     |
| `DB_NAME`           | diagnostic_center_db| Database name              |
| `APACHE_SERVER_NAME`| localhost           | Server name for Apache     |
| `ENABLE_SSL`        | false               | Enable HTTPS               |

## SSL / HTTPS Setup

1. Set `ENABLE_SSL=true` in `.env`
2. Place certificate files in `docker/ssl/`:
   - `certificate.crt`
   - `private.key`
   - `ca_bundle.crt` (optional)
3. Restart: `docker compose restart web`

If no certificates are provided, a self-signed certificate is auto-generated.

## Network Access

- **Localhost only**: Set `APACHE_SERVER_NAME=localhost`
- **LAN access**: Set `APACHE_SERVER_NAME=<your-private-IP>` (e.g., `192.168.1.100`)
- **Public access**: Set `APACHE_SERVER_NAME=<your-public-IP>`, forward ports 8081/8443 in your router
- **Domain**: Set `APACHE_SERVER_NAME=yourdomain.com`, point DNS A record to your public IP

## Database Backup
```bash
# Windows
backup-db.bat

# Linux/Mac
chmod +x backup-db.sh && ./backup-db.sh
```
Backups are saved to the `backups/` folder with timestamps.

## Build & Push (Development)
```bash
# Build and push updated image to Docker Hub
build-and-push.bat
```

## Container Management
```bash
# View status
docker compose ps

# View logs
docker compose logs -f web

# Restart
docker compose restart

# Stop
docker compose down

# Stop and remove data volumes (CAUTION: deletes database)
docker compose down -v
```

## Directory Structure (Key Paths inside Container)
```
/var/www/html/              # Application root
├── includes/               # Shared PHP includes (db_connect, auth, header, footer)
├── manager/                # Manager dashboard & tools
├── receptionist/           # Receptionist billing
├── accountant/             # Accountant payments & expenses
├── writer/                 # Report writer
├── superadmin/             # Super admin dashboard
├── Ghost/                  # Developer console
├── api/                    # AJAX API endpoints
├── templates/              # Bill print templates
├── assets/css/             # Stylesheets
├── assets/js/              # JavaScript
├── uploads/                # Persistent volume
├── saved_bills/            # Persistent volume (PDF bills)
└── final_reports/          # Persistent volume
```

## File Paths Convention
All PHP files use relative `../` paths for includes:
```php
require_once '../includes/auth_check.php';
require_once '../includes/db_connect.php';
require_once '../includes/header.php';
// ... page content ...
require_once '../includes/footer.php';
```
Root-level files use `__DIR__` based paths:
```php
require_once __DIR__ . '/includes/db_connect.php';
```

## Application Hardening (SSL-Ready Code)
- The root `.htaccess` can force HTTPS and enable HSTS:
  ```apache
  RewriteEngine On
  RewriteCond %{HTTPS} !=on
  RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
  Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"
  ```
- Secure cookie flags are set in `includes/auth_check.php`:
  ```php
  if (!headers_sent()) {
      ini_set('session.cookie_secure', '1');
      ini_set('session.cookie_httponly', '1');
      ini_set('session.cookie_samesite', 'Strict');
  }
  ```
- All asset URLs use relative paths so they inherit HTTPS automatically.
