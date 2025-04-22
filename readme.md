# DEV Environment for cf7-telegram WordPress plugin

## Requirements
Linux, Docker Compose

## Notice
Call all commands from root project directory.

## Installation & Use

```bash
make setup.all
make docker.up
make docker.down
```

Don't forget update your hosts file
`127.0.0.1     cf7tgdev.loc`.

## Development
Working directory `cf7-telegram`.

## Clear installation

To remove all the configs and WP-files, run

`make clear.all`
