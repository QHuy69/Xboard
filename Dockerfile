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
COPY luck-i18n-v18.js luck-dashboard.blade.php luck-overrides.css luck-donate-qr.svg luck-clash.svg /tmp/luck-custom/

# Add build arguments
ARG CACHEBUST=1
ARG REPO_URL=https://github.com/cedar2025/Xboard
ARG BRANCH_NAME=master
ARG SOURCE_SHA=
ARG APP_VERSION=1.0.0

RUN echo "Cloning ${REPO_URL} at ${SOURCE_SHA:-${BRANCH_NAME}} with CACHEBUST: ${CACHEBUST}" && \
    rm -rf ./* && \
    rm -rf .git && \
    git config --global --add safe.directory /www && \
    git clone --filter=blob:none --no-checkout --depth 1 --branch "${BRANCH_NAME}" "${REPO_URL}" . && \
    git fetch --depth 1 origin "${SOURCE_SHA:-${BRANCH_NAME}}" && \
    git checkout --detach FETCH_HEAD && \
    git submodule update --init --recursive --force

# Stamp the exact CI build version after the pinned checkout. Editing the
# workflow checkout before this clone has no effect on the final image.
RUN sed -i "s/'version' => '.*'/'version' => '${APP_VERSION}'/g" config/app.php

# Keep custom Luck runtime files in the image's public tree. Static JS is
# served from public/theme while compose mounts storage/theme for templates.
RUN mkdir -p public/theme/Luck/assets storage/theme/Luck/assets && \
    cp /tmp/luck-custom/luck-i18n-v18.js public/theme/Luck/i18n-v18.js && \
    cp /tmp/luck-custom/luck-i18n-v18.js storage/theme/Luck/i18n-v18.js && \
    cp /tmp/luck-custom/luck-dashboard.blade.php public/theme/Luck/dashboard.blade.php && \
    cp /tmp/luck-custom/luck-dashboard.blade.php storage/theme/Luck/dashboard.blade.php && \
    cp /tmp/luck-custom/luck-overrides.css public/theme/Luck/assets/luck-overrides.css && \
    cp /tmp/luck-custom/luck-overrides.css storage/theme/Luck/assets/luck-overrides.css && \
    cp /tmp/luck-custom/luck-clash.svg public/theme/Luck/assets/luck-clash.svg && \
    cp /tmp/luck-custom/luck-clash.svg storage/theme/Luck/assets/luck-clash.svg

# The donation entry point deliberately serves the QR artwork only; keeping it
# at a stable public path lets the Blade shell reference it without exposing
# the underlying bank details.
RUN cp /tmp/luck-custom/luck-donate-qr.svg /www/public/luck-donate-qr.svg

# Overlay the customized runtime files on top of the upstream checkout. The
# image deliberately clones upstream for normal updates, but these files are
# part of the maintained custom branch and must be present in every build.
COPY routes/web.php /www/routes/web.php
COPY app/Http/Controllers/ResourcePortalController.php /www/app/Http/Controllers/ResourcePortalController.php
COPY app/Http/Routes/V2/AdminRoute.php /www/app/Http/Routes/V2/AdminRoute.php
COPY plugins-core/Sepay/Plugin.php /www/plugins-core/Sepay/Plugin.php
COPY resources/views/payment/banking.blade.php /www/resources/views/payment/banking.blade.php
COPY resources/views/resources/ /www/resources/views/resources/
COPY app/Http/Requests/Admin/PlanSave.php /www/app/Http/Requests/Admin/PlanSave.php
COPY app/Models/Plan.php /www/app/Models/Plan.php
COPY app/Services/PlanService.php /www/app/Services/PlanService.php
COPY app/Http/Controllers/V1/User/ServerController.php /www/app/Http/Controllers/V1/User/ServerController.php
COPY app/Http/Resources/NodeResource.php /www/app/Http/Resources/NodeResource.php

COPY .docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY .docker/caddy/Caddyfile /etc/caddy/Caddyfile
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

RUN php -l routes/web.php \
    && php -l app/Http/Controllers/ResourcePortalController.php \
    && php -l app/Console/Commands/XboardInstall.php \
    && php -l app/Http/Controllers/V1/Guest/CommController.php \
    && php -l app/Http/Controllers/V1/Passport/CommController.php \
    && php -l app/Http/Controllers/V2/Admin/ConfigController.php \
    && php -l app/Http/Controllers/V2/Admin/MailTemplateController.php \
    && php -l app/Http/Requests/Admin/ConfigSave.php \
    && php -l app/Http/Routes/V2/AdminRoute.php \
    && php -l app/Http/Requests/Admin/PlanSave.php \
    && php -l app/Models/MailTemplate.php \
    && php -l app/Models/Plan.php \
    && php -l app/Services/Auth/MailLinkService.php \
    && php -l app/Services/Auth/RegisterService.php \
    && php -l app/Services/MailService.php \
    && php -l app/Services/PlanService.php \
    && php -l app/Services/TicketService.php \
    && php -l app/Console/Commands/CheckServer.php \
    && php -l app/Http/Controllers/V1/Guest/PaymentController.php \
    && php -l app/Http/Controllers/V1/Guest/TelegramController.php \
    && php -l app/Http/Controllers/V1/User/UserController.php \
    && php -l app/Http/Requests/Admin/UserUpdate.php \
    && php -l app/Http/Controllers/V1/User/ServerController.php \
    && php -l app/Http/Resources/NodeResource.php \
    && php -l app/Services/LuckThemeAssetPatcher.php \
    && php -l app/Services/OrderService.php \
    && php -l app/Services/TelegramService.php \
    && php -l app/Services/TrafficResetService.php \
    && php -l plugins-core/CoinPayments/Plugin.php \
    && php -l plugins-core/Telegram/Plugin.php \
    && php -l tests/smoke-order-idempotency.php \
    && php -l database/migrations/2026_08_29_000002_add_language_to_mail_templates.php \
    && php -l database/migrations/2026_08_29_000003_enable_email_verification_and_set_admin_path.php \
    && composer install --no-cache --no-dev --no-security-blocking \
    && php tests/smoke-node-access-url.php \
    && php tests/smoke-luck-theme-patches.php \
    && php tests/smoke-custom-backend.php \
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
RUN chmod +x /entrypoint.sh /healthcheck.sh
HEALTHCHECK --interval=10s --timeout=5s --start-period=30s --retries=6 \
    CMD ["/healthcheck.sh"]
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"] 
