FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    sqlite \
    sqlite-dev \
    git \
    nodejs \
    npm \
    && mkdir -p /run/nginx /app/backups \
    && php -m | grep -qi pdo_sqlite || docker-php-ext-install pdo_sqlite \
    && php -m | grep -qi sqlite3    || docker-php-ext-install sqlite3

# Install openclaw CLI (thin client that connects to host gateway via WebSocket)
RUN npm install -g openclaw 2>/dev/null || true

# PHP-FPM config: listen on unix socket instead of TCP port 9000
# The official php-fpm Docker image sets "listen = 9000" in docker.conf
RUN printf '[www]\nlisten = /run/php-fpm.sock\nlisten.owner = nginx\nlisten.group = nginx\nlisten.mode = 0660\n' \
    > /usr/local/etc/php-fpm.d/zz-openmind.conf

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
