FROM php:8.2-apache

# MySQL extension इंस्टॉल करो
RUN docker-php-ext-install pdo pdo_mysql

# Apache rewrite enable
RUN a2enmod rewrite

# फाइलें कॉपी करो
COPY . /var/www/html/

# Port 80 expose
EXPOSE 80
