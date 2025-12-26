FROM php:8.2-apache

# Устанавливаем PostgreSQL расширения
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && a2enmod rewrite

# Устанавливаем только prefork MPM
RUN apt-get install -y apache2-mpm-prefork

# Копируем конфигурацию Apache
COPY apache-config.conf /etc/apache2/sites-available/000-default.conf

# Копируем файлы проекта
COPY . /var/www/html/

# Настраиваем права
RUN chown -R www-data:www-data /var/www/html

# Railway использует порт из переменной окружения
EXPOSE 8080

CMD ["apache2-foreground"]
