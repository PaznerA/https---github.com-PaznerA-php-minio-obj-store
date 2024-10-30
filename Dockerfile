# Compile Rust lib
FROM rust:1.70 as rust-builder

WORKDIR /usr/src/rust-db

COPY ./src/DB/Cargo.toml .
COPY ./src/DB/src src/

RUN cargo build --release

# Run PHP server
FROM php:8.2-apache

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libffi-dev \
    libfreetype6-dev \
    zip \
    unzip \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql

# Povolení FFI extension
RUN docker-php-ext-configure ffi --with-ffi \
    && docker-php-ext-install ffi


# Vytvoření php.ini s povoleným FFI
RUN echo "ffi.enable=true" >> /usr/local/etc/php/conf.d/ffi-enable.ini

COPY --from=rust-builder /usr/src/rust-db/target/release/librust_db.so /usr/local/lib/
RUN ldconfig

# Enable Apache modules
RUN a2enmod rewrite
COPY <<-"EOF" /etc/apache2/sites-available/000-default.conf
<VirtualHost *:80>
    ServerAdmin webmaster@localhost
    DocumentRoot /var/www/html/public

    <Directory /var/www/html/public>
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/error.log
    CustomLog ${APACHE_LOG_DIR}/access.log combined
</VirtualHost>
EOF

# Nastavení pracovního adresáře
WORKDIR /var/www/html

# Copy application files
COPY . .

# Set permissions
RUN chown -R www-data:www-data /var/www/html