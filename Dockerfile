FROM php:8.2-apache

# Installer les dépendances système nécessaires pour compiler SQLite
RUN apt-get update && apt-get install -y \
    libsqlite3-dev \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

# Installer l'extension SQLite pour PDO
RUN docker-php-ext-install pdo pdo_sqlite

# Activer la réécriture d'URL Apache
RUN a2enmod rewrite

# Configurer Apache pour écouter sur le port 10000 (requis par Render)
RUN sed -i 's/80/10000/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

# Copier les fichiers du projet
COPY . /var/www/html/

# Donner les droits complets à Apache sur le dossier web (CORRIGÉ ici)
RUN chown -R www-data:www-data /var/www/html && chmod -R 755 /var/www/html