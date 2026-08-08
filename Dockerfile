# Build stage untuk mengompilasi program Go
FROM golang:1.18 AS builder
WORKDIR /app
COPY main_tiktok.go .
RUN go build -o bot_views main_tiktok.go

# Stage final menggunakan PHP dengan Apache (Debian-based)
FROM php:8.1-apache
WORKDIR /var/www/html

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
