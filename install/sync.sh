#!/bin/bash

[ ! -d ./wordpress/wp-content/plugins/cf7-telegram ] && mkdir -p ./wordpress/wp-content/plugins/cf7-telegram
rsync -Pcav --delete ./cf7-telegram/ ./wordpress/wp-content/plugins/cf7-telegram/
