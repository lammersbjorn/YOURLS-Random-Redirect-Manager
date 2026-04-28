# YOURLS Random Redirect Manager

A YOURLS plugin that routes configured keywords to one of multiple destination URLs, with optional weighted chances per URL.

Listed in [![Listed in Awesome YOURLS!](https://img.shields.io/badge/Awesome-YOURLS-C5A3BE)](https://github.com/YOURLS/awesome-yourls/)

## Features

- Per-keyword redirect lists with enable/disable toggle.
- Weighted random destination selection.
- Automatic create/update of matching YOURLS shortlinks.
- Admin shortlink picker for quick URL selection.
- Safe fallback behavior when weights are empty or invalid.

## Compatibility

- YOURLS: requires 1.7.3+, tested up to 1.10.3.
- PHP: requires 7.4+, tested up to 8.5.
- Existing option key and stored option structure stay compatible: `random_redirect_settings`.
- Uses fallback edit calls when newer YOURLS helper functions are unavailable.

## Structure

- `plugin.php`: YOURLS plugin header, direct-access guard, bootstrap include.
- `includes/bootstrap.php`: guarded loader and plugin initializer.
- `includes/class-random-redirect-manager.php`: runtime behavior, admin page, settings, redirect logic.
- `assets/admin.css`: admin interface styles.
- `assets/admin.js`: admin interface behavior for rows, totals, and shortlink picker.
- `tests/`: lightweight PHPUnit coverage using the YOURLS plugin test-suite bootstrap.

## Installation

1. Place the plugin folder in `user/plugins/` as `Random-Redirect-Manager`.
2. Activate **Random Redirect Manager** in YOURLS admin.
3. Open the plugin page and manage redirect lists.

## Configuration Details

- Keywords may contain letters, numbers, `-`, `_`, and `/`.
- Reserved YOURLS keywords are rejected.
- Each redirect list needs at least one valid URL.
- Positive chance percentages are normalized automatically; they do not need to total exactly 100.
- If every chance is `0` or empty, the plugin falls back to equal random selection.
- Resetting lists does not delete the underlying YOURLS shortlinks automatically.

## Testing

```bash
phpunit -c tests/phpunit.xml
php -l plugin.php
php -l includes/bootstrap.php
php -l includes/class-random-redirect-manager.php
```

## License

MIT. See [LICENSE](LICENSE).
