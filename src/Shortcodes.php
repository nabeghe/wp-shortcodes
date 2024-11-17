<?php namespace Nabeghe\WPShortcodes;

/**
 * WordPress API for creating bbcode-like tags or what WordPress calls
 * "shortcodes". The tag and attribute parsing or regular expression code is
 * based on the Textpattern tag parser.
 *
 * A few examples are below:
 *
 * [shortcode /]
 * [shortcode foo="bar" baz="bing" /]
 * [shortcode foo="bar"]content[/shortcode]
 *
 * Shortcode tags support attributes and enclosed content, but does not entirely
 * support inline shortcodes in other shortcodes. You will have to call the
 * shortcode parser in your function to account for that.
 *
 * {@internal
 * Please be aware that the above note was made during the beta of WordPress 2.6
 * and in the future may not be accurate. Please update the note when it is no
 * longer the case.}}
 *
 * To apply shortcode tags to content:
 *
 *     $out = do_shortcode( $content );
 *
 * @link https://developer.wordpress.org/plugins/shortcodes/
 *
 * @package WordPress
 * @subpackage Shortcodes
 */

/**
 * Container for storing shortcode tags and their hook to call for the shortcode.
 */
class Shortcodes
{
    public array $shortcodes = [];

    protected ?\Closure $actionCallback = null;
    protected ?\Closure $filterCallback = null;


    /**
     * Adds a new shortcode.
     *
     * Care should be taken through prefixing or other means to ensure that the
     * shortcode tag being added is unique and will not conflict with other,
     * already-added shortcode tags. In the event of a duplicated tag, the tag
     * loaded last will take precedence.
     *
     * @param  string  $tag  Shortcode tag to be searched in post content.
     * @param  callable  $callback  The callback function to run when the shortcode is found.
     *                           Every shortcode callback is passed three parameters by default,
     *                           including an array of attributes (`$atts`), the shortcode content
     *                           or null if not set (`$content`), and finally the shortcode tag
     *                           itself (`$shortcode_tag`), in that order.
     * @throws ShortcodeException
     */
    public function add(string $tag, $callback): void
    {
        if ('' === trim($tag)) {
            throw new ShortcodeException('Invalid shortcode name: Empty name given.');
        }

        if (0 !== preg_match('@[<>&/\[\]\x00-\x20=]@', $tag)) {
            throw new ShortcodeException(sprintf(
                'Invalid shortcode name: %1$s. Do not use spaces or reserved characters: %2$s',
                $tag,
                '& / < > [ ] ='
            ));
        }

        $this->shortcodes[$tag] = $callback;
    }

    /**
     * Removes hook for shortcode.
     *
     * @param  string  $tag  Shortcode tag to remove hook for.
     */
    public function remove(string $tag): void
    {
        unset($this->shortcodes[$tag]);
    }

    /**
     * Clears all shortcodes.
     *
     * This function clears all of the shortcode tags by replacing the shortcodes global with
     * an empty array. This is actually an efficient method for removing all shortcodes.
     */
    public function removeAll(): void
    {
        $this->shortcodes = [];
    }

    /**
     * Determines whether a registered shortcode exists named $tag.
     *
     * @param  string  $tag  Shortcode tag to check.
     * @return bool Whether the given shortcode exists.
     */
    public function defined(string $tag): bool
    {
        return array_key_exists($tag, $this->shortcodes);
    }

    /**
     * Determines whether the passed content contains the specified shortcode.
     *
     * @param  string  $content  Content to search for shortcodes.
     * @param  string  $tag  Shortcode tag to check.
     * @return bool Whether the passed content contains the given shortcode.
     */
    public function has(string $content, string $tag): bool
    {
        if (!str_contains($content, '[')) {
            return false;
        }

        if ($this->defined($tag)) {
            preg_match_all('/'.$this->getRegex().'/', $content, $matches, PREG_SET_ORDER);
            if (empty($matches)) {
                return false;
            }

            foreach ($matches as $shortcode) {
                if ($tag === $shortcode[2]) {
                    return true;
                } elseif (!empty($shortcode[5]) && $this->has($shortcode[5], $tag)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Searches content for shortcodes and filter shortcodes through their hooks.
     *
     * This function is an alias for do_shortcode().
     *
     * @param  string  $content  Content to search for shortcodes.
     * @return string Content with shortcodes filtered out.
     * @see do()
     */
    public function apply(string $content): string
    {
        return $this->do($content);
    }

    /**
     * Searches content for shortcodes and filter shortcodes through their hooks.
     *
     * If there are no shortcode tags defined, then the content will be returned
     * without any filtering. This might cause issues when plugins are disabled but
     * the shortcode will still show up in the post or content.
     *
     * @param  string  $content  Content to search for shortcodes.
     * @return string Content with shortcodes filtered out.
     */
    public function do(string $content): string
    {
        if (!str_contains($content, '[')) {
            return $content;
        }

        if (!$this->shortcodes) {
            return $content;
        }

        // Find all registered tag names in $content.
        preg_match_all('@\[([^<>&/\[\]\x00-\x20=]++)@', $content, $matches);
        $tagnames = array_intersect(array_keys($this->shortcodes), $matches[1]);

        if (empty($tagnames)) {
            return $content;
        }

        $pattern = $this->getRegex($tagnames);
        $content = preg_replace_callback("/$pattern/", [&$this, 'doTag'], $content);

        // Always restore square braces so we don't break things like <!--[if IE ]>.
        $content = $this->unescapeInvalid($content);

        return $content;
    }

    /**
     * Retrieves the shortcode regular expression for searching.
     *
     * The regular expression combines the shortcode tags in the regular expression
     * in a regex class.
     *
     * The regular expression contains 6 different sub matches to help with parsing.
     *
     * 1 - An extra [ to allow for escaping shortcodes with double [[]]
     * 2 - The shortcode name
     * 3 - The shortcode argument list
     * 4 - The self closing /
     * 5 - The content of a shortcode when it wraps some content.
     * 6 - An extra ] to allow for escaping shortcodes with double [[]]
     *
     * @param  array|null  $tagnames  Optional. List of shortcodes to find. Defaults to all registered shortcodes.
     * @return string The shortcode search regular expression
     */
    public function getRegex(?array $tagnames = null): string
    {
        if (empty($tagnames)) {
            $tagnames = array_keys($this->shortcodes);
        }
        $tagregexp = implode('|', array_map('preg_quote', $tagnames));

        // WARNING! Do not change this regex without changing do_shortcode_tag() and strip_shortcode_tag().
        // Also, see shortcode_unautop() and shortcode.js.

        // phpcs:disable Squiz.Strings.ConcatenationSpacing.PaddingFound -- don't remove regex indentation
        return '\\['                             // Opening bracket.
            .'(\\[?)'                           // 1: Optional second opening bracket for escaping shortcodes: [[tag]].
            ."($tagregexp)"                     // 2: Shortcode name.
            .'(?![\\w-])'                       // Not followed by word character or hyphen.
            .'('                                // 3: Unroll the loop: Inside the opening shortcode tag.
            .'[^\\]\\/]*'                   // Not a closing bracket or forward slash.
            .'(?:'
            .'\\/(?!\\])'               // A forward slash not followed by a closing bracket.
            .'[^\\]\\/]*'               // Not a closing bracket or forward slash.
            .')*?'
            .')'
            .'(?:'
            .'(\\/)'                        // 4: Self closing tag...
            .'\\]'                          // ...and closing bracket.
            .'|'
            .'\\]'                          // Closing bracket.
            .'(?:'
            .'('                        // 5: Unroll the loop: Optionally, anything between the opening and closing shortcode tags.
            .'[^\\[]*+'             // Not an opening bracket.
            .'(?:'
            .'\\[(?!\\/\\2\\])' // An opening bracket not followed by the closing shortcode tag.
            .'[^\\[]*+'         // Not an opening bracket.
            .')*+'
            .')'
            .'\\[\\/\\2\\]'             // Closing shortcode tag.
            .')?'
            .')'
            .'(\\]?)';                          // 6: Optional second closing brocket for escaping shortcodes: [[tag]].
        // phpcs:enable
    }

    /**
     * Regular Expression callable for do_shortcode() for calling shortcode hook.
     *
     * @param  array  $m  Regular expression match array.
     * @return string|false Shortcode output on success, false on failure.
     *
     * @throws ShortcodeException
     * @see getRegex() for details of the match array contents.
     */
    public function doTag(array $m): string|false
    {
        // Allow [[foo]] syntax for escaping a tag.
        if ('[' === $m[1] && ']' === $m[6]) {
            return substr($m[0], 1, -1);
        }

        $tag = $m[2];
        $attr = $this->parseAtts($m[3]);

        if (!is_callable($this->shortcodes[$tag])) {
            throw new ShortcodeException(sprintf('Attempting to parse a shortcode without a valid callback: %s', $tag));
            //return $m[0];
        }

        /**
         * Filters whether to call a shortcode callback.
         *
         * Returning a non-false value from filter will short-circuit the
         * shortcode generation process, returning that value instead.
         *
         * @param  false|string  $output  Short-circuit return value. Either false or the value to replace the shortcode with.
         * @param  string  $tag  Shortcode name.
         * @param  array|string  $attr  Shortcode attributes array or empty string.
         * @param  array  $m  Regular expression match array.
         */
        $return = $this->applyFilters('pre_do_shortcode_tag', false, $tag, $attr, $m);
        if (false !== $return) {
            return $return;
        }

        $content = $m[5] ?? null;

        $output = $m[1].call_user_func($this->shortcodes[$tag], $attr, $content, $tag).$m[6];

        /**
         * Filters the output created by a shortcode callback.
         *
         * @param  string  $output  Shortcode output.
         * @param  string  $tag  Shortcode name.
         * @param  array|string  $attr  Shortcode attributes array or empty string.
         * @param  array  $m  Regular expression match array.
         */
        return $this->applyFilters('do_shortcode_tag', $output, $tag, $attr, $m);
    }

    /**
     * Removes placeholders added by do_shortcodes_in_html_tags().
     *
     * @param  string  $content  Content to search for placeholders.
     * @return string Conútent with placeholders removed.
     */
    public function unescapeInvalid(string $content): string
    {
        // Clean up entire string, avoids re-parsing HTML.
        $trans = [
            '&#91;' => '[',
            '&#93;' => ']',
        ];

        $content = strtr($content, $trans);

        return $content;
    }

    /**
     * Retrieves the shortcode attributes regex.
     *
     * @return string The shortcode attribute regular expression.
     */
    public function getAttsRegex(): string
    {
        return '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|\'([^\']*)\'(?:\s|$)|(\S+)(?:\s|$)/';
    }

    /**
     * Retrieves all attributes from the shortcodes tag.
     *
     * The attributes list has the attribute name as the key and the value of the
     * attribute as the value in the key/value pair. This allows for easier
     * retrieval of the attributes, since all attributes have to be known.
     *
     * @param  string  $text
     * @return array|string List of attribute values.
     *                      Returns empty array if '""' === trim( $text ).
     *                      Returns empty string if '' === trim( $text ).
     *                      All other matches are checked for not empty().
     */
    public function parseAtts(string $text): array|string
    {
        $atts = [];
        $pattern = $this->getAttsRegex();
        $text = preg_replace("/[\x{00a0}\x{200b}]+/u", ' ', $text);
        if (preg_match_all($pattern, $text, $match, PREG_SET_ORDER)) {
            foreach ($match as $m) {
                if (!empty($m[1])) {
                    $atts[strtolower($m[1])] = stripcslashes($m[2]);
                } elseif (!empty($m[3])) {
                    $atts[strtolower($m[3])] = stripcslashes($m[4]);
                } elseif (!empty($m[5])) {
                    $atts[strtolower($m[5])] = stripcslashes($m[6]);
                } elseif (isset($m[7]) && strlen($m[7])) {
                    $atts[] = stripcslashes($m[7]);
                } elseif (isset($m[8]) && strlen($m[8])) {
                    $atts[] = stripcslashes($m[8]);
                } elseif (isset($m[9])) {
                    $atts[] = stripcslashes($m[9]);
                }
            }

            // Reject any unclosed HTML elements.
            foreach ($atts as &$value) {
                if (str_contains($value, '<')) {
                    if (1 !== preg_match('/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value)) {
                        $value = '';
                    }
                }
            }
        } else {
            $atts = ltrim($text);
        }

        return $atts;
    }

    /**
     * Combines user attributes with known attributes and fill in defaults when needed.
     *
     * The pairs should be considered to be all of the attributes which are
     * supported by the caller and given as a list. The returned attributes will
     * only contain the attributes in the $pairs list.
     *
     * If the $atts list has unsupported attributes, then they will be ignored and
     * removed from the final returned list.
     *
     * @param  array  $pairs  Entire list of supported attributes and their defaults.
     * @param  array  $atts  User defined attributes in shortcode tag.
     * @param  string  $shortcode  Optional. The name of the shortcode, provided for context to enable filtering
     * @return array Combined and filtered attribute list.
     *
     */
    public function atts(array $pairs, iterable $atts, string $shortcode = ''): array
    {
        $atts = (array) $atts;
        $out = [];
        foreach ($pairs as $name => $default) {
            if (array_key_exists($name, $atts)) {
                $out[$name] = $atts[$name];
            } else {
                $out[$name] = $default;
            }
        }

        if ($shortcode) {
            /**
             * Filters shortcode attributes.
             *
             * If the third parameter of the shortcode_atts() function is present then this filter is available.
             * The third parameter, $shortcode, is the name of the shortcode.
             *
             * @param  array  $out  The output array of shortcode attributes.
             * @param  array  $pairs  The supported attributes and their defaults.
             * @param  array  $atts  The user defined shortcode attributes.
             * @param  string  $shortcode  The shortcode name.
             */
            $out = $this->applyFilters("shortcode_atts_$shortcode", $out, $pairs, $atts, $shortcode);
        }

        return $out;
    }

    /**
     * Removes all shortcode tags from the given content.
     *
     * @param  string  $content  Content to remove shortcode tags.
     * @return string Content without shortcode tags.
     */
    public function strip(string $content): string
    {
        if (!str_contains($content, '[')) {
            return $content;
        }

        if (!$this->shortcodes) {
            return $content;
        }

        // Find all registered tag names in $content.
        preg_match_all('@\[([^<>&/\[\]\x00-\x20=]++)@', $content, $matches);

        $tags_to_remove = array_keys($this->shortcodes);

        /**
         * Filters the list of shortcode tags to remove from the content.
         *
         * @param  array  $tags_to_remove  Array of shortcode tags to remove.
         * @param  string  $content  Content shortcodes are being removed from.
         */
        $tags_to_remove = $this->applyFilters('strip_shortcodes_tagnames', $tags_to_remove, $content);

        $tagnames = array_intersect($tags_to_remove, $matches[1]);

        if (empty($tagnames)) {
            return $content;
        }

        $pattern = $this->getRegex($tagnames);
        $content = preg_replace_callback("/$pattern/", [&$this, 'stripTag'], $content);

        // Always restore square braces so we don't break things like <!--[if IE ]>.
        $content = $this->unescapeInvalid($content);

        return $content;
    }

    /**
     * Strips a shortcode tag based on RegEx matches against post content.
     *
     * @param  array  $m  RegEx matches against post content.
     * @return string|false The content stripped of the tag, otherwise false.
     */
    private function stripTag(array $m): string|false
    {
        // Allow [[foo]] syntax for escaping a tag.
        if ('[' === $m[1] && ']' === $m[6]) {
            return substr($m[0], 1, -1);
        }
        return $m[1].$m[6];
    }

    protected function applyFilters(string $name, mixed $value, ...$args): mixed
    {
        if ($this->filterCallback) {
            $callback = $this->filterCallback;
            return $callback($name, $value, $args);
        }
        return $value;
    }
}