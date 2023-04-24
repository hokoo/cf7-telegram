#!/bin/bash

# run from project root directory
bash ./install/setup-env.sh

# import variables from .env file
. ./.env

# import functions
. ./install/setup-functions.sh

setup-container
