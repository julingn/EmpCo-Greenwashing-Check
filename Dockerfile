FROM php:8.3-cli-alpine

# PostgreSQL + curl + zip PHP-Extensions
RUN apk add --no-cache postgresql-dev curl-dev oniguruma-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql curl mbstring zip

ENV PHP_CLI_SERVER_WORKERS=4

WORKDIR /app
COPY . .

EXPOSE 8080

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT:-8080} -t . router.php"]
