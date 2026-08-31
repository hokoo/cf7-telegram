# DEV Environment for cf7-telegram WordPress plugin

## Requirements
Linux, Docker Compose

## Notice
Call all commands from root project directory.

## Stability Roadmap

Stabilization epics, completed evidence, planned fake Telegram E2E coverage, and
the deferred test-suite refactor are tracked in
`docs/stability/roadmap.md`. Detailed epic documents live in the same
`docs/stability/` directory.

## Installation & Use

To setup the development environment, run
```bash
make setup.all
```

Don't forget update your hosts file

```
127.0.0.1   cf7t.local
127.0.0.1   cf7t.betas
```

## Clear installation

To remove all the configs and WP-files, run

`make clear.all`
