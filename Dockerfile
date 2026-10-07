FROM php:8.2-apache

# Copy all repository files to Apache root folder
COPY . /var/www/html/

# Expose port 80 for Render
EXPOSE 80
