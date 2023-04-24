#!/bin/bash

draw_line(){
  printf %"$(tput cols)"s |tr " " "-"
}

fake_posts(){
  docker-compose -p cf7tg exec php sh -c "\
  wp post create --post_type=cf7tg_chat --post_title=\"Chat 0\" --post_status=publish && \
  wp post create --post_type=cf7tg_chat --post_title=\"Chat 1\" --post_status=publish && \
  wp post create --post_type=cf7tg_bot --post_title=\"Bot example\" --post_status=publish && \
  wp post create --post_type=cf7tg_channel --post_title=\"Channel 0\" --post_status=publish && \
  wp post create --post_type=cf7tg_channel --post_title=\"Channel 1\" --post_status=publish && \
  wp post create --post_type=cf7tg_channel --post_title=\"Channel 2\" --post_status=publish"
}

set_permalinks(){
  docker-compose -p cf7tg exec php sh -c "wp rewrite structure '/%year%/%monthnum%/%postname%/'"
}

setup-container(){
  echo "Container is being created..."
  docker-compose -p cf7tg up -d
  echo "Container is up."
}
