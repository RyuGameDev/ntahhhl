# Build stage untuk mengompilasi program Go
FROM golang:1.18-alpine AS builder
WORKDIR /app
COPY main_tiktok.go .
RUN go build -o bot_views main_tiktok.go

# Stage final menggunakan Nginx + PHP-FPM di Alpine (Ringan dan stabil di Railway)
FROM php:8.1-fpm-alpine

# Pasang Nginx
RUN apk add --no-cache nginx

# Buat folder kerja dan salin file proyek
WORKDIR /var/www/html
COPY index.php .
COPY --from=builder /app/bot_views .
RUN chmod +x bot_views

# Tulis konfigurasi minimal Nginx
RUN mkdir -p /run/nginx && \
    cat << 'EOF' > /etc/nginx/http.d/default.conf
server {
    listen 80;
    server_name _;
    root /var/www/html;
    index index.php index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
EOF

# Jalankan PHP-FPM dan Nginx bersamaan
EXPOSE 80
CMD php-fpm -D && nginx -g "daemon off;"
