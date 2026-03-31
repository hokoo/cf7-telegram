# DEV Environment for cf7-telegram WordPress plugin

## Requirements
Linux, Docker Compose

## Notice
Call all commands from root project directory.

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

## Migration admin-bar status plan

The plugin currently handles data migration in two ways:

- automatic migration is scheduled after a plugin update through `upgrader_process_complete`;
- manual migration is requested from the settings UI when legacy data is detected but was not migrated automatically.

Implementation plan:

1. Add a shared PHP-level migration status helper that distinguishes `scheduled`, `running`, and `manual-required`.
2. Persist an explicit runtime flag while the cron migration callback is executing so the "running now" state is visible even after the scheduled event has already started.
3. Show a bright status badge in the WordPress admin bar for users who can manage the plugin, with each state linking to the CF7 Telegram settings page.
4. Reuse the same status helper for the settings-page migration notice/button logic so the admin bar and settings UI stay in sync.
5. Validate the change with the existing React tests/build and PHP syntax checks for touched files.
