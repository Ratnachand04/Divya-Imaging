# ============================================================
# Diagnostic Center - PHP 8.2 + Apache Docker Image
# ============================================================
# This image is pushed to: ratnachand/diagnostic-center-web
# It contains the full website. Pair with MariaDB via compose.
# ============================================================

FROM php:8.2-apache

LABEL maintainer="ratnachand"
LABEL description="Diagnostic Center Website - Apache + PHP 8.2"
LABEL version="1.0"

# Set environment defaults (override via .env or docker-compose)
ENV APACHE_DOCUMENT_ROOT=/var/www/html
ENV DB_HOST=db
ENV DB_USER=root
ENV DB_PASS=root_password
ENV DB_NAME=diagnostic_center_db

# ---- Install system dependencies ----
RUN apt-get update && apt-get install -y --no-install-recommends \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libzip-dev \
    libonig-dev \
    libicu-dev \
    libxml2-dev \
    libssl-dev \
    zip \
    unzip \
    curl \
    cron \
    ssl-cert \
    default-mysql-client \
    inotify-tools \
    && rm -rf /var/lib/apt/lists/*

# ---- Install PHP extensions ----
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
    mysqli \
    pdo \
    pdo_mysql \
    gd \
    mbstring \
    zip \
    intl \
    xml \
    opcache \
    calendar

# ---- Enable Apache modules ----
RUN a2enmod rewrite headers ssl expires deflate remoteip

# ---- Install Composer ----
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# ---- Custom PHP configuration ----
COPY docker/php/custom-php.ini /usr/local/etc/php/conf.d/custom-php.ini

# ---- Apache virtual host ----
COPY docker/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/apache/vhost-ssl.conf /etc/apache2/sites-available/default-ssl.conf

# ---- Copy application code ----
COPY . /var/www/html/

# ---- Set proper permissions ----
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && mkdir -p /var/www/html/uploads \
    && mkdir -p /var/www/html/saved_bills \
    && mkdir -p /var/www/html/final_reports \
    && mkdir -p /var/www/html/manager/uploads \
    && mkdir -p /var/www/html/uploads/documents \
    && mkdir -p /var/www/html/uploads/expenses \
    && mkdir -p /var/www/html/uploads/payout_proofs \
    && mkdir -p /var/www/html/uploads/report_templates \
    && mkdir -p /var/www/html/uploads/test_documents \
    && chown -R www-data:www-data /var/www/html/uploads \
    && chown -R www-data:www-data /var/www/html/saved_bills \
    && chown -R www-data:www-data /var/www/html/final_reports \
    && chown -R www-data:www-data /var/www/html/manager/uploads \
    && chmod -R 775 /var/www/html/uploads \
    && chmod -R 775 /var/www/html/saved_bills \
    && chmod -R 775 /var/www/html/final_reports \
    && chmod -R 775 /var/www/html/manager/uploads

# ---- Install Composer dependencies ----
RUN cd /var/www/html && composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || true

# ---- SSL certificate directory ----
RUN mkdir -p /etc/apache2/ssl

# ---- Copy entrypoint and helper scripts ----
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/ip-monitor.sh /usr/local/bin/ip-monitor.sh
COPY docker/port-scan.sh /usr/local/bin/port-scan.sh
RUN chmod +x /usr/local/bin/entrypoint.sh /usr/local/bin/ip-monitor.sh /usr/local/bin/port-scan.sh

# ---- Expose ports ----
EXPOSE 80 443

# ---- Start Apache via entrypoint ----
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
