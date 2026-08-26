# Mirrors the server: PHP 8.2, no Composer, no build step. Apache stands in for
# LiteSpeed — it reads the same .htaccess syntax, which is the part that
# matters. The differences that do not survive the substitution (LSAPI worker
# reuse, the absence of OPcache) are documented in docs/hosting.md.
FROM php:8.2-apache

RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite expires headers

COPY php.ini /usr/local/etc/php/conf.d/zz-rerm.ini
COPY apache.conf /etc/apache2/sites-available/000-default.conf
