#!/bin/bash

# import variables from .env file
. ./.env

echo -e -n "${RRED}Script will remove all configs and generated folders. Sure? (y/n)${COLOR_OFF}"

read item
case "$item" in
    y|Y)
    rm -rf ./vendor
    rm -f ./composer.lock
    rm -rf ./plugin-dir/vendor
    rm -f ./plugin-dir/composer.lock
    rm -rf ./wordpress
    rm -f ./wp-config.php
    rm -f ./index.php
    rm -rf ./install/nginx
    rm -f ./install/php-fpm/error.log
    rm -f ./.env
      ;;

    *)
      echo "Nothing has been done."
      ;;
esac
