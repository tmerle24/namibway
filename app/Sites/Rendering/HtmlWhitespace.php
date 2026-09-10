<?php

namespace App\Sites\Rendering;

/**
 * The Blade indentation, taken off the delivered page.
 *
 * Nested block views indent every line, and on a page with every band that
 * was about half of the markup (25 of 53 KB, measured 2026-09-10) — bytes a
 * visitor on a prepaid bundle paid for and the browser threw away. Only the
 * whitespace after a line break goes; the break stays, so text still has the
 * space between words and an inline script still ends its `//` comments.
 * `<pre>` and `<textarea>` keep theirs, where it is content.
 */
class HtmlWhitespace
{
    public static function strip(string $html): string
    {
        return (string) preg_replace_callback(
            '#<(pre|textarea)\b.*?</\1>|\n[ \t]+#is',
            fn (array $m): string => ($m[1] ?? '') !== '' ? $m[0] : "\n",
            $html,
        );
    }
}
