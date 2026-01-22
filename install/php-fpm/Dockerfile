ARG PHP_VER

FROM wodby/wordpress-php:${PHP_VER}-4.62.1

LABEL maintaner="Igor Tron <public@itron.pro>"

WORKDIR /srv/web

USER root

RUN mkdir -p /var/log/php && chown -R wodby:wodby /var/log/php

RUN echo 'alias ll="ls -l --color"' >>  /etc/bash.bashrc
RUN echo 'alias ll="ls -l --color"' >>  /home/wodby/.bashrc

USER wodby