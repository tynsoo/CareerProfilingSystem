FROM php:8.4-cli

# pdo_pgsql/pgsql need libpq headers to build against
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
COPY . .

# Render sets PORT at runtime; default matches local dev (php-dev-server on 8000)
ENV PORT=8000
EXPOSE 8000

# Same PHP built-in server used for local dev all along — no router script
# needed since every page/endpoint is requested by its literal file path.
CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t /var/www/html"]
