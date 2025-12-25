# xAPI Learning Records Viewer - Tsugi Edition
# Supports both LTI 1.1 and LTI 1.3

FROM php:8.1-apache

# Install PHP extensions and dependencies
RUN apt-get update && apt-get install -y \
    libcurl4-openssl-dev \
    libssl-dev \
    unzip \
    git \
    && docker-php-ext-install \
    curl \
    pdo \
    pdo_mysql \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules
RUN a2enmod rewrite headers

# Configure Apache for .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . /var/www/html/

# Create necessary directories
RUN mkdir -p /var/www/html/keys

# Set permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Configure PHP
RUN echo "session.cookie_samesite=None" >> /usr/local/etc/php/conf.d/session.ini \
    && echo "session.cookie_secure=On" >> /usr/local/etc/php/conf.d/session.ini

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/register.php || exit 1

# Expose port 80
EXPOSE 80

# Start Apache
CMD ["apache2-foreground"]
