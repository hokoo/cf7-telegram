#!/bin/bash

# import variables from .env file
. ./.env

PARAM=""

if [ -n "$1" ]; then
  PARAM=$1
fi

echo -e "${BBLACK}"
echo "Synchronizing working directory"

[ ! -d ./wordpress/wp-content/plugins/cf7-telegram ] && mkdir -p ./wordpress/wp-content/plugins/cf7-telegram
rsync -cav"${PARAM}" --delete ./cf7-telegram/ ./wordpress/wp-content/plugins/cf7-telegram/

echo "Done"
echo -e "${COLOR_OFF}"
