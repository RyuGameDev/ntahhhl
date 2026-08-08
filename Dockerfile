# syntax=docker/dockerfile:1

# Build binary Go menggunakan versi yang masih didukung.
FROM golang:1.26-bookworm AS builder
WORKDIR /src

COPY main_tiktok.go ./
RUN CGO_ENABLED=0 go build -trimpath -ldflags="-s -w" -o /out/bot_views ./main_tiktok.go

# Runtime PHP + Apache yang masih menerima security update.
FROM php:8.4-apache-bookworm
WORKDIR /var/www/html

# ADMIN_TOKEN sengaja tidak didefinisikan di image; set melalui Railway Variables.

COPY --chown=www-data:www-data index.php ./index.php
COPY --from=builder --chown=www-data:www-data --chmod=0755 /out/bot_views ./bot_views

# mod_php harus memakai satu MPM saja: prefork.
RUN set -eux; \
    a2dismod -f mpm_event mpm_worker || true; \
    rm -f "$APACHE_CONFDIR"/mods-enabled/mpm_event.load \
          "$APACHE_CONFDIR"/mods-enabled/mpm_event.conf \
          "$APACHE_CONFDIR"/mods-enabled/mpm_worker.load \
          "$APACHE_CONFDIR"/mods-enabled/mpm_worker.conf; \
    a2enmod mpm_prefork; \
    printf '%s\n' 'ServerName 0.0.0.0' > "$APACHE_CONFDIR/conf-available/servername.conf"; \
    a2enconf servername; \
    install -d -o www-data -g www-data -m 0755 /var/www/html/logs; \
    chown www-data:www-data /var/www/html; \
    apache2ctl -t

# Railway akan menimpa PORT saat runtime. Nilai ini menjadi fallback lokal.
ENV PORT=8080
EXPOSE 8080

# Bersihkan MPM lagi saat startup, validasi PORT, lalu jalankan Apache sebagai PID 1.
CMD ["bash", "-lc", "set -Eeuo pipefail; export APACHE_CONFDIR=/etc/apache2; port=${PORT:-8080}; case \"$port\" in ''|*[!0-9]*) echo >&2 'PORT harus berupa angka 1-65535'; exit 64;; esac; if (( port < 1 || port > 65535 )); then echo >&2 'PORT harus berupa angka 1-65535'; exit 64; fi; rm -f /etc/apache2/mods-enabled/mpm_event.load /etc/apache2/mods-enabled/mpm_event.conf /etc/apache2/mods-enabled/mpm_worker.load /etc/apache2/mods-enabled/mpm_worker.conf; a2enmod mpm_prefork >/dev/null; sed -ri \"0,/^[[:space:]]*Listen[[:space:]]+[0-9]+[[:space:]]*$/s//Listen ${port}/\" /etc/apache2/ports.conf; sed -ri \"0,/<VirtualHost[[:space:]]+\\*:[0-9]+>/s//<VirtualHost *:${port}>/\" /etc/apache2/sites-available/000-default.conf; apache2ctl -t; exec apache2-foreground"]
