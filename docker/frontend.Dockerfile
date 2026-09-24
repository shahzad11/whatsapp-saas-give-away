FROM php:8.3-apache

RUN apt-get update \
 && apt-get install -y --no-install-recommends curl ca-certificates \
 && docker-php-ext-install mysqli \
 && a2enmod rewrite headers remoteip \
 && rm -rf /var/lib/apt/lists/*

# The app root IS the DocumentRoot, so config/ and includes/ sit next to public
# pages. The vhost below denies them explicitly — see the comment there.
ENV APP_ROOT=/var/www/app
WORKDIR ${APP_ROOT}

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/vhost.conf /etc/apache2/sites-available/000-default.conf

COPY frontend-php/ ${APP_ROOT}/
COPY docker/bootstrap.php /usr/local/bin/bootstrap.php
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# PHP sessions go to a dedicated directory so it can be mounted as a volume and
# survive container replacement (otherwise every deploy logs everyone out).
RUN mkdir -p /var/lib/php/sessions ${APP_ROOT}/logs \
 && chown -R www-data:www-data /var/lib/php/sessions ${APP_ROOT}/logs \
 && chmod 700 /var/lib/php/sessions

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
  CMD curl -fsS http://127.0.0.1/health.php || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
