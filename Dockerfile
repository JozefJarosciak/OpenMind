FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    sqlite \
    && mkdir -p /run/nginx /app/backups \
    && docker-php-ext-install pdo_sqlite

# PHP-FPM config: listen on socket, run as www-data
RUN sed -i 's|listen = 127.0.0.1:9000|listen = /run/php-fpm.sock|' /usr/local/etc/php-fpm.d/www.conf \
    && echo "listen.owner = nginx"  >> /usr/local/etc/php-fpm.d/www.conf \
    && echo "listen.group = nginx"  >> /usr/local/etc/php-fpm.d/www.conf \
    && echo "listen.mode = 0660"    >> /usr/local/etc/php-fpm.d/www.conf

COPY docker/nginx.conf    /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisord.conf

COPY . /app
RUN rm -rf /app/docker /app/.git /app/.claude /app/.env* \
    && chown -R www-data:www-data /app/backups

COPY docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /app
EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
