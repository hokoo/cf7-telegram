#!/bin/bash

# import functions
. ./install/setup-functions.sh

setup-env
configure-nginx

docker-compose -p cf7tg up -d

echo -e "Composers installation... Yes, there are two composers here ${RYELLOW}:-D${COLOR_OFF}"
docker-compose -p cf7tg exec php sh -c "composer install"
docker-compose -p cf7tg exec php sh -c "cd ./cf7-telegram && composer install"

# Create symlink for the plugin
echo "Symlinking plugin..."
docker-compose -p cf7tg exec php sh -c "ln -s /var/www/html/cf7-telegram /var/www/html/wordpress/wp-content/plugins/cf7-telegram"
echo -e "${ICYAN}Result: ${RYELLOW}$(ls -l ./wordpress/wp-content/plugins/ | grep cf7-telegram)${COLOR_OFF}"

echo "WP setup preparing..."
# prepare file structure

[ ! -f ./index.php ] && echo "<?php
define( 'WP_USE_THEMES', true );
require( './wordpress/wp-blog-header.php' );" > index.php

if [ ! -f wp-config.php ]; then
  WPCONFIG=$(< ./install/.example/wp-config.php.template)
  printf "$WPCONFIG" $DB_NAME $DB_USER $DB_PASSWORD $DB_HOST > ./wp-config.php
fi

# install WP
echo "WP database init"
echo -e -n "${ICYAN}Would you init new instance (y), or do nothing (n)? ${RYELLOW}(y/n)${COLOR_OFF}"

read -r item
case "$item" in
    y|Y)
    echo "WP database init new instance..."
    docker-compose -p cf7tg exec php sh -c "wp db reset --yes && wp core install --url=$PROJECT_BASE_URL --title=\"$WP_TITLE\" --admin_user=$WP_ADMIN --admin_password=$WP_ADMIN_PASS --admin_email=$WP_ADMIN_EMAIL --skip-email"
    docker-compose -p cf7tg exec php sh -c "wp plugin delete akismet hello"
    docker-compose -p cf7tg exec php sh -c "wp plugin activate --all"

    fake_posts
    printf "${RGREEN}Example content got added.${COLOR_OFF} \n"

    set_permalinks

    draw_line
    printf "${ICYAN}WordPress credentials:${COLOR_OFF} \n"
    printf "WP User Admin: ${RYELLOW}%s \n${COLOR_OFF}WP User Pass: ${RYELLOW}%s${COLOR_OFF}\n" $WP_ADMIN $WP_ADMIN_PASS

    printf "\n${ICYAN}Put this Application Key to Postman \`ApplicationApiKey\` variable value: ${RYELLOW} \n"
    docker-compose -p cf7tg exec php sh -c "wp user application-password create 1 postman --porcelain"
    printf "${COLOR_OFF}"
    draw_line
      ;;

    *)
      echo "WP database has not been touched."
      ;;
esac

echo -e "${ICYAN}Do not forget update the hosts file with line:"
echo -e "${BIGREEN}127.0.0.1 cf7tgdev.loc${COLOR_OFF}"
echo "Done."
