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

# Preserve the maintained Luck shell/translation overrides before cloning the
# upstream application. The clone intentionally replaces /www, so copying
# these files into a temporary build directory makes them available below.
RUN mkdir -p /tmp/luck-custom
COPY luck-i18n-v18.js luck-dashboard.blade.php luck-overrides.css luck-donate-qr.svg patch-luck-assets.php /tmp/luck-custom/

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
RUN mkdir -p public/theme/Luck/assets storage/theme/Luck/assets && \
    php /tmp/luck-custom/patch-luck-assets.php public/theme/Luck/assets && \
    cp /tmp/luck-custom/luck-i18n-v18.js public/theme/Luck/i18n-v18.js && \
    cp /tmp/luck-custom/luck-i18n-v18.js storage/theme/Luck/i18n-v18.js && \
    cp /tmp/luck-custom/luck-dashboard.blade.php public/theme/Luck/dashboard.blade.php && \
    cp /tmp/luck-custom/luck-dashboard.blade.php storage/theme/Luck/dashboard.blade.php && \
    cp /tmp/luck-custom/luck-overrides.css public/theme/Luck/assets/luck-overrides.css && \
    cp /tmp/luck-custom/luck-overrides.css storage/theme/Luck/assets/luck-overrides.css

# The donation entry point deliberately serves the QR artwork only; keeping it
# at a stable public path lets the Blade shell reference it without exposing
# the underlying bank details.
RUN cp /tmp/luck-custom/luck-donate-qr.svg /www/public/luck-donate-qr.svg

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
