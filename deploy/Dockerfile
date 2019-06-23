FROM php:5.6.39-cli
RUN docker-php-ext-install mysqli && docker-php-ext-install mysql

RUN apt --fix-broken install
RUN apt-get update
RUN apt-get install -y zip imagemagick mplayer ffmpeg curl wget tesseract-ocr

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR video-processor/
