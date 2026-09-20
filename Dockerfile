FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_mysql
RUN a2enmod rewrite
RUN echo "DirectoryIndex index.php index.html" >> /etc/apache2/apache2.conf
COPY . /var/www/html/
RUN mkdir -p /var/www/html/data && chmod -R 777 /var/www/html/data
RUN chown -R www-data:www-data /var/www/html/data
EXPOSE 80
