# Wyn Forms: Apache + PHP 8.3. Data and uploads live in /var/www/wyn-forms/storage.
FROM php:8.3-apache

RUN a2enmod rewrite headers \
 && sed -ri 's#/var/www/html#/var/www/wyn-forms/public#g' /etc/apache2/sites-available/000-default.conf /etc/apache2/apache2.conf \
 && sed -ri 's#AllowOverride None#AllowOverride All#g' /etc/apache2/apache2.conf \
 && echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf && a2enconf servername \
 && { echo 'upload_max_filesize=25M'; echo 'post_max_size=130M'; echo 'expose_php=Off'; } > /usr/local/etc/php/conf.d/wyn-forms.ini

WORKDIR /var/www/wyn-forms
COPY . .
RUN mkdir -p storage && chown -R www-data:www-data storage

ENV WYN_DATABASE=/var/www/wyn-forms/storage/wyn-forms.sqlite
VOLUME ["/var/www/wyn-forms/storage"]
EXPOSE 80
