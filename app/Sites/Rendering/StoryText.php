<?php

namespace App\Sites\Rendering;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * The about text, cut into the paragraphs the page shows one at a time.
 *
 * The story slideshow used to find its slides with a `<p>…</p>` regex, which
 * assumed the text arrives marked up. Most of it does not. A description a
 * business typed, pasted or had scraped is stored as plain text — and
 * Listing::sanitizeRichText HTML-escapes a value with no tags in it rather than
 * running it through the purifier, so there is not a single `<p>` to find. The
 * whole text then rendered as one unbroken wall, which is the opposite of the
 * promise the toggle makes. The same regex also dropped anything that was not a
 * paragraph: a `<ul>` between two `<p>`s simply did not appear on any slide.
 *
 * So paragraphs are recovered from whatever the text actually is, in this
 * order:
 *
 *  - **Markup** is walked as a tree, not matched with a pattern. Every
 *    block-level child is its own paragraph and nothing is left behind; a run
 *    of inline nodes becomes one, and a heading joins the block under it rather
 *    than taking a slide to itself.
 *  - **Plain text** breaks on blank lines, then on single ones. Newlines are
 *    invisible in HTML, which is why this text looked like one paragraph while
 *    being written as several.
 *  - **Text with no breaks at all** — one long run of sentences, the common
 *    shape of a pasted "About us" — is cut at sentence boundaries into slides
 *    of roughly a screenful.
 *
 * Only the last of those invents a boundary, and it only ever does so inside a
 * paragraph that is pure text: cutting HTML on a sentence would cut it inside a
 * tag. A long paragraph carrying a link stays whole, and reads as one slide.
 */
final class StoryText
{
    /**
     * Roughly how much prose a manufactured slide gets before the next one
     * starts. Sized so a slide is read without scrolling on a phone.
     */
    private const SLIDE_TARGET = 420;

    /**
     * A paragraph only gets cut into sentences if it is longer than this.
     * Below it, a single dense paragraph is still one paragraph, and slicing it
     * would be inventing structure rather than recovering it.
     */
    private const RECUT_ABOVE = 640;

    /**
     * The most slides a story is shown as. Past this the text is genuinely long
     * and the home page is the wrong place for all of it — what is cut is one
     * click away behind "Read the full story", which is shown whenever the
     * slideshow is.
     */
    private const SLIDE_MAX = 8;

    /**
     * Tags that end a paragraph. Anything else is inline and accumulates.
     *
     * @var array<int, string>
     */
    private const BLOCKS = [
        'p', 'ul', 'ol', 'dl', 'blockquote', 'pre', 'table', 'figure', 'hr',
        'div', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    ];

    /**
     * Tags that hold a story rather than being part of one — stepped inside
     * when they are all a text turns out to be.
     *
     * @var array<int, string>
     */
    private const WRAPPERS = ['div', 'section', 'article', 'main'];

    /**
     * The slides the story is shown as — HTML, one entry per slide.
     *
     * Fewer than two means there is no story to page through, and the caller
     * renders the text as it is.
     *
     * @return array<int, string>
     */
    public static function slides(?string $body): array
    {
        $paragraphs = self::paragraphs($body);

        if (count($paragraphs) < 2) {
            return [];
        }

        return array_slice($paragraphs, 0, self::SLIDE_MAX);
    }

    /**
     * The same text as one block of HTML, for the pages that show all of it —
     * the full story page, and the about block with the slideshow turned off.
     *
     * Worth doing there too: plain text with newlines in it is a wall of text
     * in a `<div>` no matter how many paragraphs it was written as.
     */
    public static function html(?string $body): string
    {
        $paragraphs = self::paragraphs($body);

        if ($paragraphs === []) {
            return (string) $body;
        }

        return implode("\n", $paragraphs);
    }

    /**
     * @return array<int, string>
     */
    public static function paragraphs(?string $body): array
    {
        $body = trim((string) $body);

        if ($body === '') {
            return [];
        }

        // The same real-tag-shape test Listing::sanitizeRichText decides with,
        // so the two agree about what counts as markup. A value it escaped
        // cannot look like markup here.
        $paragraphs = preg_match('/<\/?[a-z][a-z0-9]*(?:\s[^<>]*)?>/i', $body) === 1
            ? self::fromMarkup($body)
            : self::fromPlainText($body);

        return self::recut($paragraphs);
    }

    /**
     * @return array<int, string>
     */
    private static function fromMarkup(string $html): array
    {
        $body = self::parse($html);

        if ($body === null) {
            return [$html];
        }

        return self::attachHeadings(self::walk($body));
    }

    /**
     * @return array<int, string>
     */
    private static function walk(DOMNode $parent): array
    {
        // A story wrapped in one container is one block to a walk and the whole
        // text to a reader. `div` is in the purifier's allow-list, so this is a
        // shape that really arrives — pasted markup is full of wrappers.
        $wrapper = self::onlyWrapper($parent);

        if ($wrapper !== null) {
            return self::walk($wrapper);
        }

        $paragraphs = [];
        $inline = '';

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement && in_array(strtolower($node->tagName), self::BLOCKS, true)) {
                $paragraphs = [...$paragraphs, ...self::inlineParagraphs($inline)];
                $inline = '';
                $paragraphs = [...$paragraphs, ...self::breakOnRules(self::render($node))];

                continue;
            }

            $inline .= self::render($node);
        }

        return [...$paragraphs, ...self::inlineParagraphs($inline)];
    }

    /**
     * The one container element a node's content sits in, if that is all there
     * is — otherwise null, and the children are the paragraphs.
     */
    private static function onlyWrapper(DOMNode $parent): ?DOMElement
    {
        $wrapper = null;

        foreach ($parent->childNodes as $node) {
            if ($node instanceof DOMElement) {
                if ($wrapper !== null || ! in_array(strtolower($node->tagName), self::WRAPPERS, true)) {
                    return null;
                }

                $wrapper = $node;

                continue;
            }

            // Whitespace between tags is not content; anything else is, and the
            // wrapper is then one part of the story rather than all of it.
            if (! self::isEmpty(self::render($node))) {
                return null;
            }
        }

        return $wrapper?->childNodes->length > 0 ? $wrapper : null;
    }

    /**
     * Text with no markup: blank lines first, then single ones.
     *
     * The value is already escaped — it went through e() on the way in — so it
     * is wrapped, never escaped again.
     *
     * @return array<int, string>
     */
    private static function fromPlainText(string $text): array
    {
        $parts = self::split('/\R\s*\R/u', $text);

        if (count($parts) < 2) {
            $parts = self::split('/\R/u', $text);
        }

        return array_map(fn (string $part): string => '<p>'.$part.'</p>', $parts);
    }

    /**
     * A run of inline nodes is one paragraph, unless it uses `<br><br>` to mean
     * what `<p>` means — pasted markup often does.
     *
     * @return array<int, string>
     */
    private static function inlineParagraphs(string $html): array
    {
        if (self::isEmpty($html)) {
            return [];
        }

        return array_map(
            fn (string $part): string => '<p>'.$part.'</p>',
            self::split('/(?:<br\s*\/?>\s*){2,}/i', $html),
        );
    }

    /**
     * A paragraph split by blank lines inside it, for the same reason.
     *
     * Only `<p>` is opened up this way: the inner HTML of a list or a table
     * carries its own structure, and a `<br>` in there is not a paragraph
     * break.
     *
     * @return array<int, string>
     */
    private static function breakOnRules(string $paragraph): array
    {
        if (preg_match('/^<p(?:\s[^<>]*)?>([\s\S]*)<\/p>$/i', trim($paragraph), $m) !== 1) {
            return self::isEmpty($paragraph) ? [] : [$paragraph];
        }

        return self::inlineParagraphs($m[1]);
    }

    /**
     * A heading belongs to the text under it.
     *
     * On a slide of its own it is two words on an empty screen, and the reader
     * has to page forward to find out what it announced.
     *
     * @param  array<int, string>  $paragraphs
     * @return array<int, string>
     */
    private static function attachHeadings(array $paragraphs): array
    {
        $merged = [];
        $pending = '';

        foreach ($paragraphs as $paragraph) {
            if (preg_match('/^<h[1-6](?:\s|>)/i', trim($paragraph)) === 1) {
                $pending .= $paragraph;

                continue;
            }

            $merged[] = $pending.$paragraph;
            $pending = '';
        }

        if ($pending !== '') {
            $merged[] = $pending;
        }

        return $merged;
    }

    /**
     * Cut a paragraph that is long enough to be several into sentences.
     *
     * Pure text only — see the class comment. Two boundaries count: sentence
     * punctuation followed by space, and sentence punctuation running straight
     * into a capital, which is what text stripped of its tags looks like
     * ("uniquely yours.Culturally inspired"). The second boundary deliberately
     * requires a letter or digit before the punctuation, so an initial or an
     * abbreviation ("U.S. Route") is not treated as the end of a sentence.
     *
     * @param  array<int, string>  $paragraphs
     * @return array<int, string>
     */
    private static function recut(array $paragraphs): array
    {
        $out = [];

        foreach ($paragraphs as $paragraph) {
            if (preg_match('/^<p(?:\s[^<>]*)?>([^<>]*)<\/p>$/i', trim($paragraph), $m) !== 1
                || mb_strlen(self::text($paragraph)) <= self::RECUT_ABOVE) {
                $out[] = $paragraph;

                continue;
            }

            $sentences = self::split('/(?<=[.!?…])\s+|(?<=[\p{Ll}\p{N})\]"”][.!?…])(?=\p{Lu})/u', $m[1]);
            $slides = [];
            $slide = '';

            foreach ($sentences as $sentence) {
                $slide = $slide === '' ? $sentence : $slide.' '.$sentence;

                if (mb_strlen(self::text($slide)) >= self::SLIDE_TARGET) {
                    $slides[] = $slide;
                    $slide = '';
                }
            }

            if ($slide !== '') {
                // A short tail joins the slide before it rather than becoming a
                // slide holding one line.
                if ($slides !== [] && mb_strlen(self::text($slide)) < 120) {
                    $slides[count($slides) - 1] .= ' '.$slide;
                } else {
                    $slides[] = $slide;
                }
            }

            foreach ($slides as $text) {
                $out[] = '<p>'.$text.'</p>';
            }
        }

        return $out;
    }

    /**
     * Split on a pattern, keeping only the parts with something in them.
     *
     * @return array<int, string>
     */
    private static function split(string $pattern, string $subject): array
    {
        $parts = preg_split($pattern, $subject) ?: [];

        return array_values(array_filter(
            array_map('trim', $parts),
            fn (string $part): bool => ! self::isEmpty($part),
        ));
    }

    /**
     * Whether a fragment has nothing in it.
     *
     * Not the same question as "has no words": a photograph or a table is
     * content, and treating a paragraph holding only an image as empty deletes
     * it from the page it was written for.
     */
    private static function isEmpty(string $html): bool
    {
        return self::text($html) === ''
            && preg_match('/<(?:img|picture|figure|video|audio|iframe|table|hr)\b/i', $html) !== 1;
    }

    private static function text(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Parse a fragment and hand back the node its content sits under.
     *
     * The charset meta is the documented way to stop libxml reading UTF-8 as
     * Latin-1; libxml moves it into the head it invents, so the body holds
     * exactly the fragment that went in.
     */
    private static function parse(string $html): ?DOMNode
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
            LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $dom->getElementsByTagName('body')->item(0) : null;
    }

    private static function render(DOMNode $node): string
    {
        return (string) $node->ownerDocument?->saveHTML($node);
    }
}
