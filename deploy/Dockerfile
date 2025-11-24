FROM php:cli

WORKDIR video-processor/

RUN apt-get update &&  apt-get install -y zip \
    imagemagick \
    mplayer \
    ffmpeg \
    curl \
    wget \
    git \
    tesseract-ocr \
    python3 \
    python3-pip \
    yt-dlp \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd

# Copy the current directory contents into the container
COPY . .

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
#RUN composer install
CMD ["tail","-f","/dev/null"]
