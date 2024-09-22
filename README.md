# WP Shortcodes

The standalone version of [WordPress shortcodes](https://github.com/WordPress/WordPress/blob/d2c835af27d4e4c42b97f422a0aa98b8fd3fb7cd/wp-includes/shortcodes.php#L63) system for use outside of WordPress.

**Notice:** The kses-related parts have been removed from the shortcode system and are currently not supported.

## 🫡 Usage

### 🚀 Installation

You can install the package via composer:

```bash
composer require nabeghe/wp-shortcodes
```

### 📁 Localization Directory

### Example

```php
use Nabeghe\WPShortcodes\Shortcodes;

$shortcodes = new Shortcodes();
$shortcodes->add('hash', function ($atts, $content = null) {
    $atts['algo'] ??= 'md5';
    return hash($atts['algo'], $content);
});

$result = $shortcodes->do('
MD5 = [hash algo="md5"]https://github.com/nabeghe/wp-shortcodes[/hash]
SJA256 = [hash algo="sha256"]https://github.com/nabeghe/wp-shortcodes[/hash]
');
echo $result;
```
## 📖 License

Copyright (c) 2024 Hadi Akbarzadeh

Licensed under the GPL-2.0+ license, see [LICENSE.md](LICENSE.md) for details.