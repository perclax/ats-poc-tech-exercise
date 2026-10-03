FROM composer:2.8.12 AS composer

FROM php:8.4.25-cli-bookworm

ARG MONGODB_EXTENSION_VERSION=2.4.1
ARG AMQP_EXTENSION_VERSION=2.1.2

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        libicu-dev \
        librabbitmq-dev \
        libssl-dev \
        libxml2-dev \
        unzip \
    && docker-php-ext-install -j"$(nproc)" intl \
    && pecl install mongodb-${MONGODB_EXTENSION_VERSION} amqp-${AMQP_EXTENSION_VERSION} \
    && docker-php-ext-enable mongodb amqp \
    && apt-mark manual librabbitmq4 \
    && apt-get purge -y --auto-remove libicu-dev librabbitmq-dev libssl-dev libxml2-dev \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/ats.ini

WORKDIR /app
