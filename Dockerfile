FROM php:8.2-apache

# MySQL və Excel (.xlsx) faylları oxumaq üçün lazımi uzantılar
RUN apt-get update && apt-get install -y libzip-dev && rm -rf /var/lib/apt/lists/*
RUN docker-php-ext-install pdo pdo_mysql mysqli zip

# Böyük Excel fayllarının yüklənə bilməsi üçün limitləri artırırıq
RUN { \
    echo 'upload_max_filesize = 25M'; \
    echo 'post_max_size = 25M'; \
    echo 'max_execution_time = 300'; \
    echo 'memory_limit = 512M'; \
    } > /usr/local/etc/php/conf.d/uploads.ini

# .htaccess-lərin işləməsi üçün
# (bəzi php:apache image-lərində birdən çox MPM modulu aktiv simlink kimi qalır —
# a2dismod bunu bəzən tanımır, ona görə simlinkləri birbaşa siləcəyik)
RUN rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf \
           /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf \
    && a2enmod mpm_prefork rewrite headers \
    && apachectl configtest

COPY . /var/www/html/
WORKDIR /var/www/html

RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# .htaccess-lərin AllowOverride ilə oxunması üçün
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 8080

# Konteyner hər başlayanda MPM konfliktini təmizləyib, portu Railway-in
# verdiyi $PORT-a uyğunlaşdırıb, Apache-ı işə salan skript.
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
