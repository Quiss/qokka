FROM xtrime/telegram-api-server:2.7.2

WORKDIR /app-host-link

RUN curl --fail --show-error --location --retry 3 \
        "https://github.com/xtrime-ru/TelegramApiServer/archive/refs/heads/master.tar.gz" \
        --output /tmp/telegram-api-server.tar.gz \
    && tar --extract --gzip \
        --file=/tmp/telegram-api-server.tar.gz \
        --directory=. \
        --strip-components=1 \
    && rm /tmp/telegram-api-server.tar.gz

RUN composer install \
    --no-dev \
    --prefer-dist \
    --no-interaction \
    --optimize-autoloader

COPY docker/production/telegram-api/patches/madelineproto-empty-report-peers.patch /tmp/madelineproto-empty-report-peers.patch
COPY docker/production/telegram-api/patches/madelineproto-disable-cdn-downloads.patch /tmp/madelineproto-disable-cdn-downloads.patch

RUN patch --strip=1 \
        --forward \
        --input=/tmp/madelineproto-empty-report-peers.patch \
    && patch --strip=1 \
        --forward \
        --input=/tmp/madelineproto-disable-cdn-downloads.patch \
    && rm \
        /tmp/madelineproto-empty-report-peers.patch \
        /tmp/madelineproto-disable-cdn-downloads.patch
