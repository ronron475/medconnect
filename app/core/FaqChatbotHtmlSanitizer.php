<?php
/**
 * Context-aware HTML sanitizer for FAQ chatbot patient-facing markup.
 *
 * Preserves safe formatting (paragraphs, emphasis, fcb-* layout classes) while
 * stripping event handlers, scripts, and other executable vectors.
 * Does not rely on strip_tags alone as the security boundary.
 */
final class FaqChatbotHtmlSanitizer
{
    /** @var array<string, true> */
    private const ALLOWED_TAGS = [
        'p' => true,
        'br' => true,
        'div' => true,
        'span' => true,
        'strong' => true,
        'em' => true,
        'b' => true,
        'i' => true,
        'u' => true,
        'small' => true,
        'ul' => true,
        'ol' => true,
        'li' => true,
    ];

    /** @var array<string, true> */
    private const FORBIDDEN_TAGS = [
        'script' => true,
        'style' => true,
        'iframe' => true,
        'object' => true,
        'embed' => true,
        'link' => true,
        'meta' => true,
        'base' => true,
        'form' => true,
        'input' => true,
        'button' => true,
        'textarea' => true,
        'select' => true,
        'option' => true,
        'svg' => true,
        'math' => true,
        'video' => true,
        'audio' => true,
        'source' => true,
        'img' => true,
        'picture' => true,
        'canvas' => true,
        'template' => true,
        'noscript' => true,
        'applet' => true,
        'frame' => true,
        'frameset' => true,
    ];

    /** @var array<string, true> */
    private const ALLOWED_ROLES = [
        'note' => true,
        'alert' => true,
        'status' => true,
        'presentation' => true,
    ];

    /**
     * Sanitize FAQ HTML for safe insertion into an HTML body context.
     */
    public static function sanitize(string $html): string
    {
        $html = str_replace("\0", '', $html);
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // Plain text → escape into paragraphs (no raw angle brackets).
        if (!preg_match('/<[a-zA-Z!\/]/', $html)) {
            return self::escapeAsParagraphs($html);
        }

        if (!class_exists('DOMDocument')) {
            return self::fallbackStripAndEscape($html);
        }

        $prev = libxml_use_internal_errors(true);
        try {
            $dom = new DOMDocument('1.0', 'UTF-8');
            $wrapped = '<div id="fcb-sanitize-root">' . $html . '</div>';
            $ok = @$dom->loadHTML(
                '<?xml encoding="UTF-8">' . $wrapped,
                LIBXML_HTML_NODEFDTD | LIBXML_NONET
            );
            if (!$ok) {
                return self::fallbackStripAndEscape($html);
            }
            $root = $dom->getElementById('fcb-sanitize-root');
            if (!$root instanceof DOMElement) {
                return self::fallbackStripAndEscape($html);
            }
            self::sanitizeElement($root);
            $out = '';
            foreach (iterator_to_array($root->childNodes) as $child) {
                $out .= $dom->saveHTML($child);
            }
            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }
    }

    private static function sanitizeElement(DOMElement $el): void
    {
        $children = [];
        foreach ($el->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                continue;
            }
            if (!($child instanceof DOMElement)) {
                $el->removeChild($child);
                continue;
            }

            $tag = strtolower($child->tagName);
            if (isset(self::FORBIDDEN_TAGS[$tag])) {
                $el->removeChild($child);
                continue;
            }

            self::sanitizeElement($child);

            if (!isset(self::ALLOWED_TAGS[$tag])) {
                while ($child->firstChild) {
                    $el->insertBefore($child->firstChild, $child);
                }
                $el->removeChild($child);
                continue;
            }

            self::sanitizeAttributes($child);
        }
    }

    private static function sanitizeAttributes(DOMElement $el): void
    {
        $names = [];
        if ($el->hasAttributes()) {
            foreach ($el->attributes as $attr) {
                $names[] = $attr->name;
            }
        }

        foreach ($names as $name) {
            $lname = strtolower($name);
            $value = $el->getAttribute($name);

            if (str_starts_with($lname, 'on') || $lname === 'style' || $lname === 'srcset'
                || $lname === 'href' || $lname === 'src' || $lname === 'xlink:href'
                || $lname === 'formaction' || $lname === 'action' || $lname === 'poster') {
                $el->removeAttribute($name);
                continue;
            }

            if ($lname === 'class') {
                $safe = self::filterClasses($value);
                if ($safe === '') {
                    $el->removeAttribute($name);
                } else {
                    $el->setAttribute('class', $safe);
                }
                continue;
            }

            if ($lname === 'data-kb-key') {
                if (preg_match('/^[\w.-]{1,80}$/', $value)) {
                    $el->setAttribute('data-kb-key', $value);
                } else {
                    $el->removeAttribute($name);
                }
                continue;
            }

            if ($lname === 'aria-hidden') {
                $v = strtolower(trim($value));
                if ($v === 'true' || $v === 'false') {
                    $el->setAttribute('aria-hidden', $v);
                } else {
                    $el->removeAttribute($name);
                }
                continue;
            }

            if ($lname === 'lang') {
                if (preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/i', trim($value))) {
                    $el->setAttribute('lang', strtolower(trim($value)));
                } else {
                    $el->removeAttribute($name);
                }
                continue;
            }

            if ($lname === 'role') {
                $v = strtolower(trim($value));
                if (isset(self::ALLOWED_ROLES[$v])) {
                    $el->setAttribute('role', $v);
                } else {
                    $el->removeAttribute($name);
                }
                continue;
            }

            $el->removeAttribute($name);
        }
    }

    private static function filterClasses(string $class): string
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($class)) ?: [] as $token) {
            if ($token !== '' && preg_match('/^fcb-[\w-]+$/', $token)) {
                $out[] = $token;
            }
        }
        return implode(' ', array_unique($out));
    }

    private static function escapeAsParagraphs(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim($text));
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
        $parts = preg_split('/\n\s*\n/', $text) ?: [$text];
        $html = '';
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $safe = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $html .= '<p>' . nl2br($safe, false) . '</p>';
        }
        return $html;
    }

    /**
     * Last-resort path when DOMDocument cannot parse: strip tags then escape.
     * Attribute-bearing markup never survives as executable HTML.
     */
    private static function fallbackStripAndEscape(string $html): string
    {
        $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        return self::escapeAsParagraphs($plain);
    }
}
