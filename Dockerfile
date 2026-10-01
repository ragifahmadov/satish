FROM php:8.2-apache

# MySQL üçün lazımi PHP uzantıları
RUN docker-php-ext-install pdo pdo_mysql mysqli

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

EXPOSE 8080

# Railway konteynerə PORT environment variable-ı verir — Apache-ı ona uyğunlaşdırırıq
CMD sh -c "sed -i \"s/80/\$PORT/g\" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf && apache2-foreground"
