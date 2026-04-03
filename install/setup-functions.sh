#!/bin/bash

draw_line(){
  printf %"$(tput cols)"s |tr " " "-"
}

fake_posts(){
  docker-compose -p cf7t exec php sh -c "\
  wp post create --post_type=cf7tg_chat --post_title=\"Chat 0\" --post_status=publish && \
  wp post create --post_type=cf7tg_chat --post_title=\"Chat 1\" --post_status=publish && \
  wp post create --post_type=cf7tg_bot --post_title=\"Bot example\" --post_status=publish && \
  wp post create --post_type=cf7tg_channel --post_title=\"Channel 0\" --post_status=publish && \
  wp post create --post_type=cf7tg_channel --post_title=\"Channel 1\" --post_status=publish && \
  wp post create --post_type=cf7tg_channel --post_title=\"Channel 2\" --post_status=publish"
}

set_permalinks(){
  docker-compose -p cf7t exec php sh -c "wp rewrite structure '/%year%/%monthnum%/%postname%/'"
}

setup-env(){
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
}

configure-nginx() {
  local project_host="${PROJECT_HOST:-cf7t.local}"
  local betas_project_host="${BETAS_PROJECT_HOST:-cf7t.betas}"
  local https_port="${HTTPS_PORT:-443}"

  # configure nginx.conf
  echo "nginx.conf ..."
  [ ! -d ./install/nginx/ ] && mkdir -p ./install/nginx/ && cp -R ./install/.example/ssl ./install/nginx/
  if [ ! -f ./install/nginx/dev.conf ]; then
    NGINXCONFIG=$(< ./install/.example/nginx.conf.template)
    printf "$NGINXCONFIG" "$project_host" "$project_host" dev "$project_host" "$project_host" "$https_port" > ./install/nginx/dev.conf
  fi
  if [ ! -f ./install/nginx/betas.conf ]; then
    NGINXCONFIG=$(< ./install/.example/nginx.conf.template)
    printf "$NGINXCONFIG" "$betas_project_host" "$betas_project_host" betas "$betas_project_host" "$betas_project_host" "$https_port" > ./install/nginx/betas.conf
  fi
  echo "Ok."

  echo "Creating access.log error.log  ..."
  touch install/nginx/access.log
  touch install/php-fpm/error.log
  echo "Ok."
}
