FROM php:8.2-apache

# Устанавливаем необходимые расширения для PostgreSQL
RUN apt-get update && apt-get install -y \
    libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Отключаем ненужные MPM модули, оставляем только mpm_prefork
RUN a2dismod mpm_event mpm_worker
RUN a2enmod mpm_prefork rewrite

# Копируем файлы проекта
COPY . /var/www/html/

# Настраиваем Apache для использования порта Railway
ENV APACHE_RUN_USER www-data
ENV APACHE_RUN_GROUP www-data
ENV APACHE_LOG_DIR /var/log/apache2
ENV APACHE_PID_FILE /var/run/apache2/apache2.pid
ENV APACHE_RUN_DIR /var/run/apache2
ENV APACHE_LOCK_DIR /var/lock/apache2

# Создаем необходимые директории
RUN mkdir -p ${APACHE_RUN_DIR} ${APACHE_LOCK_DIR} ${APACHE_LOG_DIR}

# Устанавливаем права
RUN chown -R www-data:www-data /var/www/html

# Настраиваем конфигурацию Apache
RUN echo "Listen ${PORT:-8080}" > /etc/apache2/ports.conf
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Настраиваем виртуальный хост
COPY ./apache-config.conf /etc/apache2/sites-available/000-default.conf

EXPOSE 8080

CMD ["apache2-foreground"]
