<?php

namespace App\Sites\Rendering;

/**
 * The inlined stylesheet, without the notes written for the next developer.
 *
 * styles.blade.php explains itself at length in CSS comments, and those were
 * shipped to every visitor on every page — about half of the stylesheet's
 * bytes, measured 2026-09-10, on a page whose first view has a budget. The
 * comments stay in the source, where they are read; this takes them out of
 * the response, where they are not.
 *
 * Deliberately modest: comments out, runs of whitespace down to one space.
 * Nothing smarter — `.a :hover` and `.a:hover` select different things, and
 * `calc(1px + 2px)` needs its spaces — and a rule still reads as written, so
 * a test can look for `.card { display: block; }` in the output.
 */
class InlineCss
{
    public static function minify(string $css): string
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);

        return trim((string) preg_replace('/\s+/', ' ', $css));
    }
}
