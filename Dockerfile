FROM php:8.4-cli

# Install system packages required for Composer/PHPMailer
# PHP sockets extension is required for receiving SMS messages
RUN apt-get update && \
    apt-get install -y git unzip libzip-dev && \
    docker-php-ext-install zip sockets && \
    rm -rf /var/lib/apt/lists/*

WORKDIR /usr/src/app
COPY receive.php email.php ./

# Install Composer and the PHPMailer dependency
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader

# (Optional) Expose UDP port 44444 for the listener
EXPOSE 44444/udp

CMD ["php", "receive.php"]
