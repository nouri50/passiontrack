FROM php:8.4-cli

# Dépendances système + extensions PHP nécessaires pour Symfony + Doctrine/MySQL
# (gd : requis par phpoffice/phpspreadsheet, utilisé pour lire les rapports SimBit .xlsx)
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libicu-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
    pdo \
    pdo_mysql \
    intl \
    zip \
    gd \
    opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Config OPcache : garde les fichiers PHP compilés en mémoire.
# validate_timestamps=1 => vérifie quand même si un fichier a changé (tes modifs restent prises en compte)
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=16"; \
    echo "opcache.max_accelerated_files=20000"; \
    echo "opcache.validate_timestamps=1"; \
    echo "opcache.revalidate_freq=0"; \
    echo "realpath_cache_size=4096K"; \
    echo "realpath_cache_ttl=600"; \
    } > /usr/local/etc/php/conf.d/opcache-custom.ini

# Script de démarrage
COPY entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

EXPOSE 8000

ENTRYPOINT ["entrypoint.sh"]
