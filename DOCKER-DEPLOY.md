# Diagnostic Center - Docker Deployment Guide

## Windows Docker Workflow (2 Scripts)

Use only these two `.bat` scripts for Docker runtime workflows on Windows:

1. `deploy.bat` - run and host the app (localhost, private IP, or public IP)
2. `build-and-push.bat` - build Docker image and push to Docker Hub

## Quick Start (3 Steps)

```bash
# 1. Copy environment config
copy .env.example .env          # Windows
cp .env.example .env            # Linux

# 2. Edit .env with your settings (see below)

# 3. Build and start
docker-compose up -d --build
```

**Access the website (private/local):** http://localhost:8081  
**Access the website (public HTTPS):** https://localhost:8443  
**Access phpMyAdmin:** http://localhost:8082

Security default: database and phpMyAdmin host ports are bound to localhost only via `DB_BIND_IP=127.0.0.1` and `PMA_BIND_IP=127.0.0.1`.

---

## What's Inside

| Service | Container | Port | Description |
|---------|-----------|------|-------------|
| Web Server | diagnostic-center-web | 8081 (HTTP), 8443 (HTTPS) | Apache 2.4 + PHP 8.2 |
| Database | diagnostic-center-db | 127.0.0.1:3301 (default) | MariaDB 10.4 |
| phpMyAdmin | diagnostic-center-pma | 127.0.0.1:8082 (default) | Database management UI |

---

## Configuration (.env file)

### Basic Setup (Local Development)
```env
APP_PORT=8081
SSL_PORT=8443
DB_PASS=root_password
APACHE_SERVER_NAME=localhost
ENABLE_SSL=true
DUAL_IP_BIND=false
STARTUP_NETWORK_PROBES=false
IP_MONITOR_APACHE_RELOAD=false
DB_BIND_IP=127.0.0.1
PMA_BIND_IP=127.0.0.1
INIT_BUNDLE_GUARD=true
```

### Local Network Access (Other computers on your network)
```env
APP_PORT=8081
SSL_PORT=8443
DB_PASS=your_secure_password
APACHE_SERVER_NAME=192.168.1.100    # Your computer's local IP
ENABLE_SSL=true
DUAL_IP_BIND=false
STARTUP_NETWORK_PROBES=false
IP_MONITOR_APACHE_RELOAD=false
```

### Public IP Access (Port forwarded to 8443)
```env
APP_PORT=8081
SSL_PORT=8443
DB_PASS=your_very_secure_password
APACHE_SERVER_NAME=203.0.113.50     # Your public IP
ENABLE_SSL=true
DUAL_IP_BIND=true
STARTUP_NETWORK_PROBES=false
IP_MONITOR_APACHE_RELOAD=false
```

### Production with Domain + HTTPS
```env
APP_PORT=80
SSL_PORT=443
DB_PASS=super_secure_password_here
APACHE_SERVER_NAME=yourdomain.com
ENABLE_SSL=true
DUAL_IP_BIND=true
STARTUP_NETWORK_PROBES=false
IP_MONITOR_APACHE_RELOAD=false
```

---

## Moving to a New Server

### Step 1: Export from current machine
```bash
# Backup the database
docker exec diagnostic-center-db mysqldump -u root -proot_password diagnostic_center_db > backup.sql

# Or use the backup script
./backup-db.sh         # Linux
backup-db.bat          # Windows
```

### Step 2: Copy project to new server
```bash
# Copy the entire project folder (including docker/ folder)
scp -r diagnostic-center/ user@new-server:/path/to/

# Or zip and transfer
zip -r diagnostic-center.zip diagnostic-center/
```

### Step 3: Start on new server
```bash
cd diagnostic-center
cp .env.example .env
# Edit .env with new server settings
docker-compose up -d --build
```

### Step 4: Restore database (if needed)
```bash
# SQL init bundle in dump/init/ auto-imports on first run.
# To restore a backup on an existing setup:
docker exec -i diagnostic-center-db mysql -u root -proot_password diagnostic_center_db < backup.sql
```

---

## SSL / HTTPS Setup

### Option 1: Self-Signed Certificate (Testing)
```bash
# Windows
setup-ssl.bat

# Linux
chmod +x setup-ssl.sh
./setup-ssl.sh
```
Then set `ENABLE_SSL=true` in `.env` and restart:
```bash
docker-compose up -d --build
```

### Option 2: Real Certificate (Let's Encrypt - Free)
```bash
# On your server (requires a domain name):
sudo apt install certbot
sudo certbot certonly --standalone -d yourdomain.com

# Copy certificates
cp /etc/letsencrypt/live/yourdomain.com/fullchain.pem docker/ssl/certificate.crt
cp /etc/letsencrypt/live/yourdomain.com/privkey.pem docker/ssl/private.key
```

Then update `.env`:
```env
ENABLE_SSL=true
APACHE_SERVER_NAME=yourdomain.com
APP_PORT=80
SSL_PORT=443
```

```bash
docker-compose up -d --build
```

### Option 3: Purchased SSL Certificate
Place your certificate files in `docker/ssl/`:
- `certificate.crt` - Your SSL certificate
- `private.key` - Your private key
- `ca_bundle.crt` - CA bundle (if provided)

---

## Common Commands

```bash
# Start all services
docker-compose up -d

# Stop all services
docker-compose down

# Rebuild after code changes
docker-compose up -d --build

# View logs
docker-compose logs -f web        # Apache logs
docker-compose logs -f db         # Database logs

# Restart web server only
docker-compose restart web

# Enter web container shell
docker exec -it diagnostic-center-web bash

# Enter database shell
docker exec -it diagnostic-center-db mysql -u root -proot_password diagnostic_center_db

# Check container status
docker-compose ps
```

---

## Database Backup & Restore

### Backup
```bash
# Quick backup
docker exec diagnostic-center-db mysqldump -u root -proot_password diagnostic_center_db > backup_$(date +%Y%m%d).sql

# Using included script
./backup-db.sh         # Linux
backup-db.bat          # Windows
```

### Restore
```bash
docker exec -i diagnostic-center-db mysql -u root -proot_password diagnostic_center_db < backup.sql
```

---

## Persistent Data

All important data is stored in Docker volumes and survives container restarts/rebuilds:

| Volume | Purpose |
|--------|---------|
| `db_data` | MySQL database files |
| `uploads_data` | Patient documents, expense receipts |
| `saved_bills_data` | Generated bill PDFs |
| `final_reports_data` | Final report files |
| `manager_uploads_data` | Manager uploaded files |

### To completely reset (WARNING: deletes all data):
```bash
docker-compose down -v
docker-compose up -d --build
```

---

## Troubleshooting

### Website not loading
```bash
docker-compose ps                    # Check if containers are running
docker-compose logs web              # Check Apache error logs
```

### Database connection error
```bash
docker-compose logs db               # Check MariaDB logs
docker exec -it diagnostic-center-db mysql -u root -proot_password  # Test connection
```

### Port already in use
Edit `.env` and change `APP_PORT`, `SSL_PORT`, `DB_PORT`, or `PMA_PORT` to an available port.
If needed, also adjust `DB_BIND_IP` / `PMA_BIND_IP` (keep `127.0.0.1` for intruder-safe default).

### Permission errors on uploads
```bash
docker exec -it diagnostic-center-web bash
chown -R www-data:www-data /var/www/html/uploads
chmod -R 775 /var/www/html/uploads
```

### Rebuild everything from scratch
```bash
docker-compose down -v --rmi all
docker-compose up -d --build
```

---

## File Structure

```
diagnostic-center/
├── Dockerfile                 # Web server image definition
├── docker-compose.yml         # All services configuration
├── .env.example               # Environment template
├── .env                       # Your local config (create from .env.example)
├── .dockerignore              # Files excluded from Docker build
├── setup-ssl.bat/.sh          # SSL setup helper script
├── backup-db.bat/.sh          # Database backup script
├── dump/
│   ├── diagnostic_center_db_.sql            # Key schema source SQL
│   ├── init/                                # Auto-imported SQL bundle
│   │   ├── 001-main-schema.sql              # Table structure
│   │   ├── 500-data-flow-tunnel.sql         # Main data router (sources per-table files)
│   │   ├── tables/100-data-*.sql            # One file per table data
│   │   └── 900-post-schema.sql              # Indexes/constraints/final updates
│   └── backup/                              # Runtime SQL backups + backup_index.json
├── docker/
│   ├── apache/
│   │   ├── vhost.conf         # HTTP virtual host config
│   │   └── vhost-ssl.conf     # HTTPS virtual host config
│   ├── php/
│   │   └── custom-php.ini     # PHP configuration
│   ├── ssl/
│   │   └── README.txt         # SSL certificate placement guide
│   └── entrypoint.sh          # Container startup script
└── [website files...]
```
