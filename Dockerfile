FROM php:8.3-cli-alpine

# PostgreSQL + curl + zip PHP-Extensions
RUN apk add --no-cache postgresql-dev curl-dev oniguruma-dev libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql curl mbstring zip

# Chromium + Node.js für Fundstellen-Screenshots (Puppeteer), poppler für PDF-Text
RUN apk add --no-cache \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ca-certificates \
    ttf-freefont \
    nodejs \
    npm \
    poppler-utils \
    && ln -sf /usr/bin/chromium-browser /usr/bin/chromium 2>/dev/null || true

ENV CHROMIUM_PATH=/usr/bin/chromium
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium
ENV PHP_CLI_SERVER_WORKERS=4

WORKDIR /app
COPY package*.json ./
RUN npm install --omit=dev 2>/dev/null || true
COPY . .

EXPOSE 8080

CMD ["sh", "-c", "php -d upload_max_filesize=25M -d post_max_size=27M -d memory_limit=256M -S 0.0.0.0:${PORT:-8080} -t . router.php"]
