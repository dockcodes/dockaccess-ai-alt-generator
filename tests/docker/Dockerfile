# Test runtime: the official PHP CLI image plus GD with JPEG and WebP, and Composer.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli
# PHP 7.4 images sit on Debian 11, whose packages moved to archive.debian.org.
RUN if grep -q bullseye /etc/os-release; then \
      printf 'deb http://archive.debian.org/debian bullseye main\ndeb http://archive.debian.org/debian-security bullseye-security main\n' > /etc/apt/sources.list; \
    fi
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev libwebp-dev unzip git \
 && docker-php-ext-configure gd --with-jpeg --with-webp \
 && docker-php-ext-install -j"$(nproc)" gd \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
