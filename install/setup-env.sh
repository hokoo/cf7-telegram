#!/bin/bash

# create .env from example
echo "Create .env from example"
if [ ! -f ./.env ]; then
    echo "File .env doesn't exist. Recreating..."
    cp ./install/.example/.env.example ./.env && echo "Ok."
else
    echo "File .env already exists."
fi

# import variables from .env file
. ./.env

# configure nginx.conf
echo "nginx.conf ..."
[ ! -d ./install/nginx/ ] && mkdir -p ./install/nginx/
if [ ! -f ./install/nginx/nginx.conf ]; then
  NGINXCONFIG=$(< ./install/.example/nginx.conf.template)
  printf "$NGINXCONFIG" $PROJECT_BASE_URL $PROJECT_BASE_URL $PROJECT_BASE_URL $PROJECT_BASE_URL $PROJECT_BASE_URL $PROJECT_BASE_URL > ./install/nginx/nginx.conf
fi
echo "Ok."

echo "Creating access.log error.log  ..."
touch install/nginx/access.log
touch install/php-fpm/error.log
echo "Ok."
