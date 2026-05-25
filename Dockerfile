FROM php:8.2-apache

# Abilita il modulo rewrite di Apache per la navigazione delle pagine
RUN a2enmod rewrite

# Installa l'estensione PDO MySQL che serve a OnePassage per connettersi a Clever Cloud
RUN docker-php-ext-install pdo pdo_mysql

# Copia tutti i file del tuo progetto dentro il server web
COPY . /var/www/html/

# Imposta i permessi corretti per Apache
RUN chown -R www-data:www-data /var/www/html/

EXPOSE 80
