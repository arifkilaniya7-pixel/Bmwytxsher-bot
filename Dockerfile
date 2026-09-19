FROM php:8.2-apache
RUN docker-php-ext-install pdo pdo_mysql
COPY . /var/www/html/
RUN mkdir -p /var/www/html/data && chmod 777 /var/www/html/data
EXPOSE 80
