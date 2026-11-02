<?php

namespace ItsJustVita\LaravelBfsg\Dom;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use ItsJustVita\LaravelBfsg\Http\UrlFetcher;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

final class HtmlDocument
{
    private const ENCODING_HINT = '<?xml encoding="UTF-8">';

    /**
     * HTML5 void elements unknown to libxml's HTML parser: without an end tag they swallow their following siblings.
     * Comments and raw-text elements (script, style, textarea, title, xmp) are matched first and kept verbatim, so
     * a "<source" inside JavaScript, CSS or a comment is never touched.
     */
    private const UNKNOWN_VOID_ELEMENTS = '~<!--.*?-->|<(script|style|textarea|title|xmp)(?![\w-])[^>]*>.*?</\1\s*>|<(track|source|wbr|embed|keygen)(?![\w-])(?:[^>"\']|"[^"]*"|\'[^\']*\')*>(?!\s*</\2\s*>)~is';

    /** A document starts, after whitespace, comments and processing instructions, with a doctype, html, head or body tag. */
    private const DOCUMENT_START = '~\A(?:\s++|<!--.*?-->|<\?.*?\?>)*+<(?:!doctype|html|head|body)\b~is';

    /** LIBXML_PARSEHUGE lifts libxml's safety limits, so it is only used for inputs up to the size the fetcher accepts. */
    public const MAX_HUGE_PARSE_BYTES = UrlFetcher::MAX_PAGE_BYTES;

    /** libxml >= 2.14 still stops at 2048 levels with PARSEHUGE; deeper nesting is flattened to this depth. */
    private const FLATTEN_DEPTH = 1500;

    private const VOID_TAGS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr', 'keygen'];

    private ?DOMXPath $xpath = null;

    private ?CssParser $cssParser = null;

    /** @var array<string, DOMElement>|null */
    private ?array $byId = null;

    /** @var array<string, int> */
    private array $duplicateIds = [];

    /** @var array<string, list<DOMElement>>|null */
    private ?array $labelsByFor = null;

    private function __construct(
        private DOMDocument $dom,
        private bool $fragment,
        private bool $empty,
    ) {}

    /**
     * @param  array{fragment?: ?bool, ignoredSelectors?: list<string>}  $options
     */
    public static function fromHtml(string $html, array $options = []): self
    {
        $dom = new DOMDocument;

        if (trim($html) === '') {
            return new self($dom, false, true);
        }

        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }

        $fragment = $options['fragment'] ?? (preg_match(self::DOCUMENT_START, $html) !== 1);
        $html = self::closeUnknownVoidElements($html);
        $hinted = self::needsEncodingHint($html);

        if ($hinted) {
            $html = self::ENCODING_HINT.$html;
        }

        $bounded = strlen($html) <= self::MAX_HUGE_PARSE_BYTES;
        $flags = LIBXML_HTML_NODEFDTD | ($bounded ? LIBXML_PARSEHUGE : 0) | ($fragment ? LIBXML_HTML_NOIMPLIED : 0);
        $previous = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML($html, $flags);

            if ($bounded && self::hitDepthLimit()) {
                $dom = new DOMDocument;
                $dom->loadHTML(self::flattenDeepNesting($html), $flags);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($hinted) {
            self::removeEncodingHint($dom);
        }

        $document = new self($dom, $fragment, false);

        if (! empty($options['ignoredSelectors'])) {
            $document->removeMatching($options['ignoredSelectors']);
        }

        return $document;
    }

    public function dom(): DOMDocument
    {
        return $this->dom;
    }

    public function xpath(): DOMXPath
    {
        return $this->xpath ??= new DOMXPath($this->dom);
    }

    /** The document's stylesheets, parsed once and shared by every analyzer; the element index is built lazily. */
    public function cssParser(): CssParser
    {
        return $this->cssParser ??= (new CssParser)->parse($this);
    }

    /** @return list<DOMElement> */
    public function query(string $expression, ?DOMNode $context = null): array
    {
        set_error_handler(fn () => true);

        try {
            $list = $context === null ? $this->xpath()->query($expression) : $this->xpath()->query($expression, $context);
        } catch (Throwable) {
            $list = false;
        } finally {
            restore_error_handler();
        }

        if ($list === false) {
            return [];
        }

        $elements = [];

        foreach ($list as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    public function isFragment(): bool
    {
        return $this->fragment;
    }

    public function isEmpty(): bool
    {
        return $this->empty;
    }

    public function root(): ?DOMElement
    {
        return $this->dom->documentElement;
    }

    public function head(): ?DOMElement
    {
        return $this->query('/html/head')[0] ?? null;
    }

    public function body(): ?DOMElement
    {
        return $this->query('/html/body')[0] ?? null;
    }

    /** @return array<string, DOMElement> */
    public function elementsById(): array
    {
        if ($this->byId !== null) {
            return $this->byId;
        }

        $this->byId = [];
        $this->duplicateIds = [];

        foreach ($this->query('//*[@id]') as $element) {
            $id = $element->getAttribute('id');

            if ($id === '') {
                continue;
            }

            if (isset($this->byId[$id])) {
                $this->duplicateIds[$id] = ($this->duplicateIds[$id] ?? 1) + 1;

                continue;
            }

            $this->byId[$id] = $element;
        }

        return $this->byId;
    }

    /**
     * The <label for="…"> elements pointing at an id, in document order. The for-value is matched
     * exactly (like the XPath //label[@for=…] it replaces); the map is built once per document.
     *
     * @return list<DOMElement>
     */
    public function labelsFor(string $id): array
    {
        if ($this->labelsByFor === null) {
            $this->labelsByFor = [];

            foreach ($this->query('//label[@for]') as $label) {
                $this->labelsByFor[$label->getAttribute('for')][] = $label;
            }
        }

        return $this->labelsByFor[$id] ?? [];
    }

    /** @return array<string, int> id => number of occurrences (only ids that occur more than once) */
    public function duplicateIds(): array
    {
        $this->elementsById();

        return $this->duplicateIds;
    }

    /**
     * <style> elements whose media attribute applies to screen (absent, all, screen, feature-only queries);
     * styles inside <template> or <noscript> never apply.
     *
     * @return list<DOMElement>
     */
    public function styleElements(): array
    {
        return array_values(array_filter(
            $this->query('//style[not(ancestor::template) and not(ancestor::noscript)]'),
            fn (DOMElement $style): bool => self::mediaAppliesToScreen($style->getAttribute('media')),
        ));
    }

    /** @return list<string> text of every screen stylesheet, in document order */
    public function styleSheets(): array
    {
        return array_map(fn (DOMElement $style): string => $style->textContent, $this->styleElements());
    }

    /**
     * Whether a media query list (a media attribute or an @media prelude) applies to a screen.
     * Only the media type and the not/only prefixes are evaluated; media features are ignored.
     */
    public static function mediaAppliesToScreen(string $media): bool
    {
        $media = strtolower(trim($media));

        if ($media === '') {
            return true;
        }

        foreach (explode(',', $media) as $query) {
            $query = trim($query);

            if ($query === '') {
                continue;
            }

            $negated = false;

            if (str_starts_with($query, 'only ')) {
                $query = ltrim(substr($query, 5));
            } elseif (str_starts_with($query, 'not ')) {
                $negated = true;
                $query = ltrim(substr($query, 4));
            }

            $type = str_starts_with($query, '(') ? 'all' : (preg_split('/[\s(]/', $query)[0] ?? '');

            if (in_array($type, ['all', 'screen'], true) !== $negated) {
                return true;
            }
        }

        return false;
    }

    /**
     * Remove every element matching one of the CSS selectors. Invalid selectors are ignored.
     *
     * @param  list<string>  $cssSelectors
     * @return int number of removed elements
     */
    public function removeMatching(array $cssSelectors): int
    {
        $converter = new CssSelectorConverter;
        $victims = [];

        foreach ($cssSelectors as $selector) {
            try {
                $expression = $converter->toXPath($selector);
            } catch (Throwable) {
                continue;
            }

            foreach ($this->query($expression) as $element) {
                $victims[spl_object_id($element)] = $element;
            }
        }

        foreach ($victims as $element) {
            $element->parentNode?->removeChild($element);
        }

        $this->byId = null;
        $this->duplicateIds = [];
        $this->labelsByFor = null;
        $this->cssParser = null;

        return count($victims);
    }

    /** Absolute, indexed XPath such as /html[1]/body[1]/main[1]/img[2]. */
    public function selectorFor(DOMElement $element): string
    {
        $parts = [];

        for ($node = $element; $node instanceof DOMElement; $node = $node->parentNode) {
            $index = 1;

            for ($sibling = $node->previousSibling; $sibling !== null; $sibling = $sibling->previousSibling) {
                if ($sibling instanceof DOMElement && $sibling->nodeName === $node->nodeName) {
                    $index++;
                }
            }

            $parts[] = $node->nodeName.'['.$index.']';
        }

        return '/'.implode('/', array_reverse($parts));
    }

    /** Quote a value for safe use inside an XPath expression; static so CssParser can use it without a document. */
    public static function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        if (! str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        return "concat('".str_replace("'", "', \"'\", '", $value)."')";
    }

    private static function closeUnknownVoidElements(string $html): string
    {
        return preg_replace_callback(
            self::UNKNOWN_VOID_ELEMENTS,
            fn (array $m) => ($m[2] ?? null) === null ? $m[0] : $m[0].'</'.$m[2].'>',
            $html,
            flags: PREG_UNMATCHED_AS_NULL,
        ) ?? $html;
    }

    private static function hitDepthLimit(): bool
    {
        foreach (libxml_get_errors() as $error) {
            if (str_contains($error->message, 'Excessive depth')) {
                return true;
            }
        }

        return false;
    }

    /** Drops the tags that would nest deeper than FLATTEN_DEPTH (and their end tags), keeping their content. */
    private static function flattenDeepNesting(string $html): string
    {
        $stack = [];
        $depth = 0;

        return preg_replace_callback(
            '~<(/?)([a-zA-Z][\w:-]*)(?:[^>"\']|"[^"]*"|\'[^\']*\')*>~',
            function (array $m) use (&$stack, &$depth) {
                $name = strtolower($m[2]);

                if (in_array($name, self::VOID_TAGS, true) || str_ends_with($m[0], '/>')) {
                    return $m[0];
                }

                if ($m[1] === '') {
                    $drop = $depth >= self::FLATTEN_DEPTH;
                    $stack[] = $drop;
                    $drop ? null : $depth++;

                    return $drop ? '' : $m[0];
                }

                if ($stack === []) {
                    return $m[0];
                }

                if (array_pop($stack)) {
                    return '';
                }

                $depth--;

                return $m[0];
            },
            $html,
        ) ?? $html;
    }

    /** libxml < 2.14 parses the hint as a processing instruction, >= 2.14 as a comment; neither may stay in the DOM. */
    private static function removeEncodingHint(DOMDocument $dom): void
    {
        foreach (iterator_to_array($dom->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
            }
        }

        $comments = (new DOMXPath($dom))->query('//comment()');

        foreach ($comments === false ? [] : iterator_to_array($comments) as $comment) {
            if (trim($comment->data) === '?xml encoding="UTF-8"') {
                $comment->parentNode?->removeChild($comment);

                return;
            }
        }
    }

    private static function needsEncodingHint(string $html): bool
    {
        if (str_starts_with(ltrim($html), '<?xml')) {
            return false;
        }

        if (! mb_check_encoding($html, 'UTF-8')) {
            return false;
        }

        $ascii = strspn($html, implode('', array_map('chr', range(0, 127))));
        $head = substr($html, 0, $ascii);

        if (preg_match('/charset\s*=/i', $head) !== 1) {
            return true;
        }

        return preg_match('/charset\s*=\s*["\']?\s*utf-?8\b/i', $head) === 1;
    }
}
