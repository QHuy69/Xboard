FROM phpswoole/swoole:php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install PHP extensions one by one with lower optimization level for ARM64 compatibility
RUN CFLAGS="-O0" install-php-extensions pcntl && \
    CFLAGS="-O0 -g0" install-php-extensions bcmath && \
    install-php-extensions zip && \
    install-php-extensions redis && \
    apk --no-cache add shadow sqlite mysql-client mysql-dev mariadb-connector-c git patch supervisor redis caddy && \
    addgroup -S -g 1000 www && adduser -S -G www -u 1000 www && \
    (getent group redis || addgroup -S redis) && \
    (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY .docker /

# Add build arguments
ARG CACHEBUST=1
ARG REPO_URL=https://github.com/cedar2025/Xboard
ARG BRANCH_NAME=master

RUN echo "Attempting to clone branch: ${BRANCH_NAME} from ${REPO_URL} with CACHEBUST: ${CACHEBUST}" && \
    rm -rf ./* && \
    rm -rf .git && \
    git config --global --add safe.directory /www && \
    git clone --depth 1 --branch ${BRANCH_NAME} ${REPO_URL} . && \
    git submodule update --init --recursive --force

# Keep custom Luck runtime files in the image's public tree. Static JS is
# served from public/theme while compose mounts storage/theme for templates.
RUN mkdir -p public/theme/Luck && \
    if [ -f luck-i18n-v18.js ]; then cp luck-i18n-v18.js public/theme/Luck/i18n-v18.js; fi && \
    if [ -f luck-dashboard.blade.php ]; then cp luck-dashboard.blade.php public/theme/Luck/dashboard.blade.php; fi && \
    if [ -f luck-overrides.css ]; then cp luck-overrides.css public/theme/Luck/assets/luck-overrides.css; fi

# Overlay the customized runtime files on top of the upstream checkout. The
# image deliberately clones upstream for normal updates, but these files are
# part of the maintained custom branch and must be present in every build.
COPY routes/web.php /www/routes/web.php
COPY plugins-core/Sepay/Plugin.php /www/plugins-core/Sepay/Plugin.php
COPY resources/views/payment/banking.blade.php /www/resources/views/payment/banking.blade.php

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

RUN composer install --no-cache --no-dev --no-security-blocking \
    && php artisan storage:link \
    && chown -R www:www /www \
    && chmod -R 775 /www \
    && mkdir -p /data \
    && chown redis:redis /data
    
ENV ENABLE_WEB=true \
    ENABLE_HORIZON=true \
    ENABLE_REDIS=true \
    ENABLE_WS_SERVER=true \
    ENABLE_CADDY=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"] 
