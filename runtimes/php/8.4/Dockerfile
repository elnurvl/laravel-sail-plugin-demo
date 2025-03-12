FROM php:8.4-fpm-alpine AS base

ARG WWWGROUP
ARG NODE_VERSION=22
ARG MYSQL_CLIENT="mariadb-client"
ARG POSTGRES_VERSION=17

WORKDIR /var/www/html

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV SUPERVISOR_PHP_USER="sail"

# Install PHP extension installer and base tools
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

RUN apk add --no-cache bash curl ca-certificates zip unzip \
    libpng-dev libxml2-dev icu-dev imap-dev openssl-dev libmemcached-dev \
    postgresql${POSTGRES_VERSION}-client mariadb-client libcap gnupg sqlite

RUN install-php-extensions \
    bcmath ctype curl dom exif fileinfo ftp gettext iconv intl \
    mbstring mysqli mysqlnd openssl pcntl pdo pdo_mysql pdo_pgsql pdo_sqlite \
    pgsql posix readline soap sockets sodium sqlite3 tokenizer xml xmlreader \
    xmlwriter xsl zip gd igbinary imagick imap ldap memcached mongodb \
    msgpack redis swoole

RUN docker-php-ext-install calendar shmop sysvmsg sysvsem sysvshm

# Development-only extensions and tools
FROM base AS dev

RUN apk add --no-cache nano git bind-tools
RUN apk add --no-cache --virtual .build-deps autoconf g++ make linux-headers
RUN install-php-extensions pcov xdebug

RUN apk add --no-cache shadow \
    && ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone \
    && setcap "cap_net_bind_service=+ep" /usr/local/bin/php \
    && addgroup -g 29 sail \
    && adduser -D -s /bin/bash -G sail -u 1337 sail \
    && chown -R sail:sail /var/www \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/* /tmp/* /var/tmp/*

RUN apk add --no-cache busybox su-exec

COPY start-container /usr/local/bin/start-container
COPY php.ini /etc/php/8.4/fpm/conf.d/99-sail.ini
COPY www.conf /usr/local/etc/php-fpm.d/www.conf
RUN chmod +x /usr/local/bin/start-container
COPY crontab /etc/crontabs/sail
RUN chmod 644 /etc/crontabs/sail

EXPOSE 9000/tcp

ENTRYPOINT ["start-container"]
