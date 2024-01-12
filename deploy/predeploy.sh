#!/bin/bash

# Change the timezone to Eastern
sudo cp /usr/share/zoneinfo/US/Eastern /etc/localtime
    
# Add swap space, if it doesn't exist
if [ "$(grep -c swap /etc/fstab)" -eq "0" ]; then
    sudo /bin/dd if=/dev/zero of=/var/swap.1 bs=1M count=1024
    sudo /sbin/mkswap /var/swap.1
    sudo chmod 600 /var/swap.1
    sudo /sbin/swapon /var/swap.1
    echo "/var/swap.1   swap    swap    defaults        0   0" | sudo tee /etc/fstab
fi

# Remove all PHP packages (they may well be PHP 7)
sudo apt-get -y purge $(dpkg -l | grep php| awk '{print $2}' |tr "\n" " ")

# Use repo for PHP 5.6.
sudo add-apt-repository -y ppa:ondrej/php

# Update the OS
sudo apt-get update
sudo apt-get -y upgrade

# Install necessary packages.
sudo apt-get install -y php5.6-cli php5.6-mysql php5.6-mbstring php5.6-curl php5.6-xml awscli mplayer tesseract-ocr imagemagick unzip jq ffmpeg bc
