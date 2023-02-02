# DEV Environment for cf7-telegram WordPress plugin

## Requirements
Linux, Docker Compose

## Notice
Call all commands from root project directory.

## Installation

`make setup.all`

Don't forget update your hosts file
`127.0.0.1     cf7tgdev.loc`.

## Development
Working directory `cf7-telegram`.

Use `make sync` for mirroring project files to WordPress installation.

## Clear installation

To remove all the configs and WP-files, run

`make clear.all`
