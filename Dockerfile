FROM php:8.3-apache-bookworm
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y libc-client-dev libkrb5-dev libicu-dev \
 && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
 && docker-php-ext-install imap intl \
 && rm -rf /var/lib/apt/lists/*
COPY app/ /usr/share/zpush/
COPY apache-zpush.conf /etc/apache2/conf-enabled/z-push.conf
RUN mkdir -p /var/lib/z-push /var/log/z-push \
 && chown -R www-data:www-data /usr/share/zpush /var/lib/z-push /var/log/z-push \
 && a2enmod alias
