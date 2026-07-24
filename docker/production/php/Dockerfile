FROM serversideup/php:8.5-frankenphp

USER root

ARG USER_ID=1000
ARG GROUP_ID=1000

# Set user/group ID for www-data
RUN docker-php-serversideup-set-id www-data $USER_ID:$GROUP_ID

RUN apt-get update && apt-get install -y \
    ca-certificates \
    curl \
    imagemagick \
    libmagickwand-dev \
    ffmpeg \
 && curl --fail --show-error --location --retry 3 \
    https://gu-st.ru/content/lending/russian_trusted_root_ca_pem.crt \
    --output /usr/local/share/ca-certificates/russian_trusted_root_ca.crt \
 && curl --fail --show-error --location --retry 3 \
    https://gu-st.ru/content/lending/russian_trusted_sub_ca_pem.crt \
    --output /usr/local/share/ca-certificates/russian_trusted_sub_ca.crt \
 && update-ca-certificates \
 && rm -rf /var/lib/apt/lists/*

# Install required PHP extensions
RUN install-php-extensions intl bcmath gd redis imagick

# Copy optimized PHP configuration
COPY docker/production/php/php-production.ini /usr/local/etc/php/conf.d/99-production.ini

USER www-data
