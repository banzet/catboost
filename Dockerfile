FROM php:8.2-apache

# Installer l'extension SQLite pour PDO
RUN docker-php-ext-install pdo pdo_sqlite

# Activer la réécriture d'URL Apache
RUN a2enmod rewrite

# Configurer Apache pour écouter sur le port 10000 (requis par Render)
RUN sed -i 's/80/10000/g' /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

# Copier les fichiers du projet dans le dossier web d'Apache
COPY . /var/www/html/

# Donner les droits d'écriture pour la base de données SQLite
RUN chown -www-data:www-data /var/www/html