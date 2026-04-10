#!/bin/bash
# ============================================================
# SSL Certificate Setup Script for Diagnostic Center
# ============================================================
# This script helps you set up SSL certificates.
# Run this BEFORE enabling SSL in .env
# Usage: chmod +x setup-ssl.sh && ./setup-ssl.sh
# ============================================================

echo ""
echo "============================================"
echo "  SSL Certificate Setup"
echo "============================================"
echo ""

mkdir -p docker/ssl

echo "Choose an option:"
echo ""
echo "  1. Generate self-signed certificate (for testing)"
echo "  2. I have my own certificate files (copy manually)"
echo "  3. Set up Let's Encrypt (free SSL - requires domain)"
echo ""

read -p "Enter choice (1/2/3): " choice

case $choice in
  1)
    echo ""
    read -p "Enter your domain or IP (e.g., example.com or 192.168.1.100): " domain
    echo ""
    echo "Generating self-signed certificate for: $domain"
    echo ""

    openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
        -keyout docker/ssl/private.key \
        -out docker/ssl/certificate.crt \
        -subj "/C=IN/ST=State/L=City/O=DiagnosticCenter/CN=$domain"

    chmod 600 docker/ssl/private.key
    chmod 644 docker/ssl/certificate.crt

    echo ""
    echo "Self-signed certificate generated!"
    echo ""
    echo "NOTE: Browsers will show a warning for self-signed certs."
    echo "      For production, use a real certificate (option 3)."
    echo ""
    echo "Next steps:"
    echo "  1. Edit .env and set ENABLE_SSL=true"
    echo "  2. Set APACHE_SERVER_NAME=$domain"
    echo "  3. Run: docker-compose up -d --build"
    ;;

  2)
    echo ""
    echo "Please copy your SSL certificate files to docker/ssl/:"
    echo ""
    echo "  docker/ssl/certificate.crt    (your SSL certificate)"
    echo "  docker/ssl/private.key        (your private key)"
    echo "  docker/ssl/ca_bundle.crt      (CA bundle, if provided)"
    echo ""
    echo "After copying:"
    echo "  1. Edit .env and set ENABLE_SSL=true"
    echo "  2. Run: docker-compose up -d --build"
    ;;

  3)
    echo ""
    echo "For Let's Encrypt (free SSL), you need:"
    echo "  - A registered domain name pointing to your server IP"
    echo "  - Port 80 and 443 open on your firewall"
    echo ""
    read -p "Enter your domain name: " domain
    echo ""
    echo "Installing certbot and requesting certificate..."
    echo ""

    # Install certbot if not present
    if ! command -v certbot &> /dev/null; then
        sudo apt-get update && sudo apt-get install -y certbot
    fi

    # Stop docker to free port 80
    docker-compose down 2>/dev/null || true

    # Request certificate
    sudo certbot certonly --standalone -d "$domain"

    # Copy certificates
    sudo cp "/etc/letsencrypt/live/$domain/fullchain.pem" docker/ssl/certificate.crt
    sudo cp "/etc/letsencrypt/live/$domain/privkey.pem" docker/ssl/private.key
    sudo chmod 644 docker/ssl/certificate.crt
    sudo chmod 600 docker/ssl/private.key

    echo ""
    echo "Certificate obtained for $domain!"
    echo ""
    echo "Next steps:"
    echo "  1. Edit .env:"
    echo "     ENABLE_SSL=true"
    echo "     APACHE_SERVER_NAME=$domain"
    echo "  2. Run: docker-compose up -d --build"
    echo ""
    echo "Auto-renewal: sudo certbot renew --pre-hook 'docker-compose down' --post-hook 'docker-compose up -d'"
    ;;

  *)
    echo "Invalid choice."
    ;;
esac
