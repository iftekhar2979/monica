#!/bin/bash
#
# Run this script after first cloning the repository to be ready to use the sail command.
#
export MSYS_NO_PATHCONV=1
docker run --rm \
    -v composer-cache:/root/.composer \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php84-composer:latest \
    composer install --ignore-platform-reqs
ln -sf ./vendor/bin/sail sail 2>/dev/null || true
