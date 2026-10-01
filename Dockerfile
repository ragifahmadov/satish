FROM php:8.2-apache

# MySQL üçün lazımi PHP uzantıları
RUN docker-php-ext-install pdo pdo_mysql mysqli

# .htaccess-lərin işləməsi üçün
RUN a2enmod rewrite headers

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
