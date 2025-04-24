#!/bin/bash

# import functions
. ./install/setup-functions.sh

setup-env
configure-nginx

docker-compose -p cf7t up -d

# Remove symlink if it exists so that installation will not affect plugin-dir.
docker-compose -p cf7t exec php sh -c "\
if [ -L ./dev-content/plugins/cf7-telegram ]; then \
    echo 'Removing cf7-telegram symlink' && \
    rm ./dev-content/plugins/cf7-telegram; \
fi"

echo -e "Composers installation... Yes, there are two composers here ${RYELLOW}:-D${COLOR_OFF}"
docker-compose -p cf7t exec php sh -c "composer install"

# Setup plugins for migration tests.
docker-compose -p cf7t exec php sh -c "\
mkdir ./dev-content && \
mkdir ./betas-content && \
cp -r ./wordpress/wp-content/* ./dev-content && \
cp -r ./wordpress/wp-content/* ./betas-content && \
rm -rf ./wordpress/wp-content && \
rm -rf ./dev-content/plugins/cf7-telegram"

docker-compose -p cf7t exec php sh -c "cd ./plugin-dir && composer install"

# Create symlink for the plugin
echo "Symlinking plugin..."
docker-compose -p cf7t exec php sh -c "ln -s /var/www/html/plugin-dir /var/www/html/dev-content/plugins/cf7-telegram"
echo -e "${ICYAN}Result: ${RYELLOW}$(ls -l ./dev-content/plugins/ | grep cf7-telegram)${COLOR_OFF}"

echo "WP setup preparing..."
# prepare file structure

[ ! -f ./index.php ] && echo "<?php
define( 'WP_USE_THEMES', true );
require( './wordpress/wp-blog-header.php' );" > index.php

if [ ! -f _dev-config.php ]; then
  WPCONFIG=$(< ./install/.example/dev-config.php.template)
  printf "$WPCONFIG" $PROJECT_BASE_URL $PROJECT_BASE_URL $DB_NAME $DB_USER $DB_PASSWORD $DB_HOST > ./_dev-config.php
fi

if [ ! -f _betas-config.php ]; then
  WPCONFIG=$(< ./install/.example/betas-config.php.template)
  printf "$WPCONFIG" $BETAS_PROJECT_URL $BETAS_PROJECT_URL $DB_NAME $DB_USER $DB_PASSWORD $DB_HOST > ./_betas-config.php
fi

if [ ! -f wp-config.php ]; then
  ## Just copy wp-config.template as is.
  cp ./install/.example/wp-config.template ./wp-config.php
fi

# install WP
echo "WP dev database init"
echo -e -n "${ICYAN}Would you init new instance (y), or do nothing (n)? ${RYELLOW}(y/n)${COLOR_OFF}"

read -r item
case "$item" in
    y|Y)
    echo "WP database init new instance..."
    docker-compose -p cf7t exec php sh -c "wp db reset --yes && wp core install --url=$PROJECT_BASE_URL --title=\"$WP_TITLE\" --admin_user=$WP_ADMIN --admin_password=$WP_ADMIN_PASS --admin_email=$WP_ADMIN_EMAIL --skip-email"
    docker-compose -p cf7t exec php sh -c "wp plugin delete akismet hello"
    docker-compose -p cf7t exec php sh -c "wp plugin activate --all"

    fake_posts
    printf "${RGREEN}Example content got added.${COLOR_OFF} \n"

    set_permalinks

    # Install WP for betas with another prefix.
    # First, replacing "$current = 'dev';" with "$current = 'betas';" in the wp-config.php file.
    sed -i "s/\$current = 'dev';/\$current = 'betas';/" ./wp-config.php

    echo -e "${RYELLOW}WP database init for betas...${COLOR_OFF}"

    docker-compose -p cf7t exec php sh -c "wp core install --url=$BETAS_PROJECT_URL --title=\"$BETAS_WP_TITLE\" --admin_user=$WP_ADMIN --admin_password=$WP_ADMIN_PASS --admin_email=$WP_ADMIN_EMAIL --skip-email"
    docker-compose -p cf7t exec php sh -c "wp plugin delete akismet hello"
    docker-compose -p cf7t exec php sh -c "wp plugin activate --all"

    set_permalinks

    # Revert wp-config.php to the original state.
    sed -i "s/\$current = 'betas';/\$current = 'dev';/" ./wp-config.php

    draw_line
    printf "${ICYAN}WordPress credentials:${COLOR_OFF} \n"
    printf "WP User Admin: ${RYELLOW}%s \n${COLOR_OFF}WP User Pass: ${RYELLOW}%s${COLOR_OFF}\n" $WP_ADMIN $WP_ADMIN_PASS

    printf "\n${ICYAN}Put this Application Key to Postman \`ApplicationApiKey\` variable value: ${RYELLOW} \n"
    docker-compose -p cf7t exec php sh -c "wp user application-password create 1 postman --porcelain"
    printf "${COLOR_OFF}"
    draw_line
      ;;
    *)
      echo "WP database has not been touched."
      ;;
esac

echo -e "${ICYAN}Do not forget update the hosts file with lines:"
echo -e "${BIGREEN}127.0.0.1 cf7t.local${COLOR_OFF}"
echo -e "${BIGREEN}127.0.0.1 cf7t.betas${COLOR_OFF}"
echo "Done."
