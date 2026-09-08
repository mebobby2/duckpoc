FROM php:8.3-cli

# ext-ffi is required by satur-io/duckdb-php (it talks to the DuckDB C API via FFI).
# It is not compiled into the base php:8.3-cli image, so it has to be built from
# source against libffi-dev — see docker-php-ext-install below.
#
# ext-bcmath is optional for duckdb-php (used for big-integer results); we install
# it anyway since Figured's own fixed-point money math (values stored ×10,000)
# benefits from arbitrary-precision arithmetic when ported into this PoC.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libffi-dev \
        libzip-dev \
        libsqlite3-dev \
        unzip \
        git \
        default-mysql-client \
    && docker-php-ext-install ffi pdo_mysql pdo_sqlite bcmath zip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# PHP auto-enables FFI for the `cli` SAPI only. Any web SAPI — including the
# `cli-server` one behind `php artisan serve` — inherits the default
# `ffi.enable=preload` and refuses FFI calls with "FFI API is restricted by
# ffi.enable configuration directive", so every artisan command works while
# the browser 500s. Enabling it outright is fine for this local PoC; a real
# deployment would want `preload` plus an opcache preload script rather than
# FFI open to the web tier.
RUN echo "ffi.enable=1" > /usr/local/etc/php/conf.d/zz-ffi.ini

# A large scan has to be allowed to finish, and PHP's default 30s ceiling is
# enforced *inside* the FFI call — so a big query dies as
# "Maximum execution time of 30+2 seconds exceeded in .../FFI/DuckDB.php".
# Worse, `php artisan serve` is a single process, so that fatal takes the whole
# dev server down with it rather than just the one request: every other page
# then fails too, which makes the cause look like anything except a time limit.
# Diagnosed the hard way — see the hero-farm section in the README.
#
# 600s is a PoC affordance for deliberately extreme queries (a billion-row
# farm), NOT a model for production. Figured runs PHP-FPM with its own
# `request_terminate_timeout`, so a genuinely long report would be killed there
# regardless of this value. That is an argument for amortising setup with a
# persistent instance and for an async path for huge scans — not for raising
# timeouts in production.
RUN printf 'max_execution_time=600\nmemory_limit=4G\n' > /usr/local/etc/php/conf.d/zz-limits.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
