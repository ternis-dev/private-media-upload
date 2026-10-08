FROM php:8.3-cli-alpine
RUN apk add --no-cache git
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
# Mount apps/api at runtime: docker compose mounts ./apps/api:/app
EXPOSE 8000
CMD ["php", "-S", "0.0.0.0:8000", "-t", "public"]
