<?php require '../vendor/autoload.php';

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