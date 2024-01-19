FROM php:cli

WORKDIR video-processor/

RUN apt-get update &&  apt-get install -y zip \
    imagemagick \
    mplayer \
    ffmpeg \
    curl \
    wget \
    tesseract-ocr \
    && rm -rf /var/lib/apt/lists/*

# Copy the current directory contents into the container
COPY . .

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
#RUN composer install
CMD ["deploy/tests.sh"]
