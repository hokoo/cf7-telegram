#!/bin/bash

# import variables from .env file
. ./.env

echo -e -n "${RRED}Script will remove all configs and generated folders. Sure? (y/n)${COLOR_OFF}"

read item
case "$item" in
    y|Y)
    rm -rf ./vendor
    rm -rf ./cf7-telegram/vendor
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
