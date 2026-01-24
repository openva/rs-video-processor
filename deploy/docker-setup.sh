#!/bin/bash
set -euo pipefail

# Ensure required directories exist
mkdir -p includes


  rm -rf includes/vendor
  composer install --no-interaction --prefer-dist

# Get all functions from the main repo
git clone -b deploy https://github.com/openva/richmondsunlight.com.git
cd richmondsunlight.com && composer install && cd ..
cp richmondsunlight.com/htdocs/includes/*.php includes/
rm -Rf richmondsunlight.com

# Install Composer dependencies
rm -rf includes/vendor
composer install --no-interaction --prefer-dist

# Move over the settings file (always overwrite with Docker settings).
cp deploy/settings-docker.inc.php includes/settings.inc.php
