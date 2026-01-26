# php-backend/Dockerfile
#Matches the PHP version in your WordPress image
FROM php:8.2-fpm-alpine

WORKDIR /var/www/html

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/local/bin/composer

# Install system dependencies required for PHP extensions
# The `--no-cache` flag helps keep the image size down on Alpine
# `build-base` includes essential compilation tools (gcc, make, etc.)
# `libzip-dev` for 'zip' extension
# `curl-dev` for 'curl' extension
# `mysql-client` for 'pdo_mysql' (contains necessary client libraries)
# `openssl-dev` is good to have for general SSL operations (like HTTPS with Guzzle)
# `icu-dev` if you ever need the 'intl' extension (good to keep for future if needed)

RUN apk add --no-cache \
    bash \
    build-base \
    autoconf \
    curl-dev \
    libxml2-dev \
    libzip-dev \
    mysql-client \
    openssl-dev \
    icu-dev \
    git \
    unzip \
    # Clean up apk cache to reduce image size
    && rm -rf /var/cache/apk/*

# Install PHP extensions
# Removed 'json' and 'opcache' as they are usually built-in or implicitly handled.
# pdo_mysql needs 'pdo' installed or specified with it.
RUN docker-php-ext-install pdo_mysql curl zip

# The 'json' extension is almost always part of PHP core and doesn't need docker-php-ext-install.
# The 'opcache' extension is also usually enabled by default in FPM images for production use.
# You can verify this by running `php -m` inside the container after it's built.

# Copy composer.json first to leverage Docker cache
COPY composer.json .
RUN composer install --no-dev --optimize-autoloader

# Copy the rest of your application code (api.php and src/ if it exists)
COPY . .

# Adjust user/group if needed (php-fpm typically runs as www-data by default)
USER www-data

# Expose PHP-FPM port
EXPOSE 9000

# Start PHP-FPM
CMD ["php-fpm"]