# Discuz! X3.5 dev runtime — PHP 8.1 + Apache with the extensions Discuz needs.
FROM php:8.1-apache

# PHP extensions: mysqli gd zip exif opcache  (mbstring, curl, xml, json are bundled in base image)
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libzip-dev default-mysql-client; \
    docker-php-ext-configure gd --with-freetype --with-jpeg; \
    docker-php-ext-install -j"$(nproc)" mysqli gd zip exif opcache; \
    apt-get clean; rm -rf /var/lib/apt/lists/*

# Apache: enable rewrite + allow .htaccess overrides (Discuz rewrite & security rules)
RUN a2enmod rewrite headers; \
    sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

# Dev PHP settings — opcache revalidates timestamps so live plugin edits take effect
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.validate_timestamps=1'; \
      echo 'opcache.revalidate_freq=0'; \
      echo 'upload_max_filesize=64M'; \
      echo 'post_max_size=64M'; \
      echo 'memory_limit=256M'; \
      echo 'max_execution_time=300'; \
      echo 'date.timezone=UTC'; \
    } > /usr/local/etc/php/conf.d/zz-discuz-dev.ini

# Bake the Discuz core (web root = distro's upload/) into the image — ephemeral at runtime.
COPY Discuz_X3.5_SC_UTF8/upload/ /var/www/html/
COPY scripts/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh && chown -R www-data:www-data /var/www/html

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
