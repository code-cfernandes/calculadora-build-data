FROM php:8.3-apache

# PhpSpreadsheet 5 exige: zip, gd (com jpeg + freetype), xml, mbstring, intl, etc.
# A maioria já vem compilada no php:8.2-apache; precisamos instalar zip e gd.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libxml2-dev \
        libicu-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" zip gd intl \
    && rm -rf /var/lib/apt/lists/*

# Habilita mod_rewrite para o .htaccess funcionar
RUN a2enmod rewrite

# Configurações PHP para uploads de planilhas grandes
COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini

# Virtual host apontando para public/
COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf

# Instala o Composer via script oficial (evita multi-stage pull do Docker Hub)
RUN curl -sS https://getcomposer.org/installer | php -- \
        --install-dir=/usr/local/bin \
        --filename=composer \
    && composer --version

WORKDIR /var/www/app

# Copia manifesto de dependências primeiro (cache de layer)
COPY composer.json composer.lock ./

# Instala dependências PHP sem scripts para evitar erros em ambiente vazio
RUN composer install --no-dev --optimize-autoloader --no-scripts --no-interaction

# Copia o código-fonte
COPY src/     src/
COPY public/  public/

# Diretório de logs gravável pelo Apache (www-data)
RUN mkdir -p storage/logs && chown -R www-data:www-data storage

EXPOSE 80
