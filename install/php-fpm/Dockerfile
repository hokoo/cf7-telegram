ARG NWP_PHP_VER

FROM wodby/wordpress-php:$NWP_PHP_VER

LABEL maintaner="Igor Tron <public@itron.pro>"

WORKDIR /var/www/html

USER root

RUN echo 'alias ll="ls -l --color"' >>  /etc/bash.bashrc
RUN echo 'alias ll="ls -l --color"' >>  /home/wodby/.bashrc

USER wodby