# Build stage untuk mengompilasi program Go
FROM golang:1.18 AS builder
WORKDIR /app
COPY main_tiktok.go .
RUN go build -o bot_views main_tiktok.go

FROM php:8.1-apache
WORKDIR /var/www/html

# Matikan mpm_event dan mpm_worker yang bentrok agar hanya mpm_prefork yang aktif
RUN a2dismod mpm_event mpm_worker 2>/dev/null || true && \
    a2enmod mpm_prefork

# Salin file PHP frontend
COPY index.php .

# Salin binary Go yang sudah dikompilasi dari stage builder
COPY --from=builder /app/bot_views .

# Berikan izin eksekusi pada binary Go & siapkan folder logs dengan kepemilikan www-data
RUN chmod +x bot_views && \
    mkdir -p /var/www/html/logs && \
    chown -R www-data:www-data /var/www/html

# Port bawaan yang akan didengarkan oleh Apache
EXPOSE 80
