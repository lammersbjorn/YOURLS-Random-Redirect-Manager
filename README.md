# YOURLS Random Redirect Manager

A plugin for YOURLS that redirects specific keywords to randomly selected URLs from predefined lists with customizable probability weights.

Listed in [![Listed in Awesome YOURLS!](https://img.shields.io/badge/Awesome-YOURLS-C5A3BE)](https://github.com/YOURLS/awesome-yourls/)

## Features

- Create multiple redirect lists triggered by different keywords
- Set custom probability percentages for each destination URL
- Automatic shortlink creation for configured keywords
- User-friendly admin interface with percentage calculation
- Enable/disable redirect lists as needed

## Compatibility

- YOURLS: requires 1.7.3+, tested up to 1.10.3
- PHP: requires 7.4+, tested up to 8.5
- Uses fallback edit calls when newer YOURLS helper functions are unavailable.

## Installation

1. Download the plugin
2. Place the plugin folder in your `user/plugins` directory as `Random-Redirect-Manager`
3. Activate the plugin in the YOURLS admin interface

## Usage

1. Go to "Random Redirect Manager" in your YOURLS admin panel
2. Create redirect lists by specifying:
   - A keyword that triggers the redirect
   - Multiple destination URLs
   - Optional percentage chances for each URL
3. Save your settings

When users visit your shortlink with the specified keyword, they'll be randomly redirected to one of the destination URLs based on your configured probabilities.

## Configuration Details

- Keywords may contain letters, numbers, `-`, `_`, and `/`.
- Reserved YOURLS keywords are rejected.
- Each redirect list needs at least one valid URL.
- Positive chance percentages are normalized automatically; they do not need to total exactly 100.
- If every chance is `0` or empty, the plugin falls back to equal random selection.
- Resetting lists does not delete the underlying YOURLS shortlinks automatically.

## Testing

Run the lightweight PHPUnit suite:

```bash
phpunit -c tests/phpunit.xml
```

For plugin changes, also run:

```bash
php -l plugin.php
```

## License

MIT. See [LICENSE](LICENSE).

## Example Use Cases

- A/B testing different landing pages
- Traffic distribution among multiple affiliate offers
- Load balancing between different servers
- Creating a "surprise" link that goes to different content each time
