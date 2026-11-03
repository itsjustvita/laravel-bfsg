<?php

namespace ItsJustVita\LaravelBfsg\Dom;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use ItsJustVita\LaravelBfsg\Css\CssParser;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

final class HtmlDocument
{
    private const ENCODING_HINT = '<?xml encoding="UTF-8">';

    /** The first markup of a document (after whitespace, comments and processing instructions) is a doctype, html, head or body tag. */
    private const DOCUMENT_START = '~\G<(?:!doctype|html|head|body)(?![\w-])~i';

    /** LIBXML_PARSEHUGE lifts libxml's safety limits, so it is only used for inputs up to this size… */
    private const MAX_HUGE_PARSE_BYTES = 1048576;

    /** …whose markup nests at most this deep (MarkupScanner's bound); deeper input is cut off by libxml at 256 levels. */
    private const MAX_HUGE_PARSE_DEPTH = 2048;

    /** Elements nested deeper than this are unwrapped after parsing, so the recursive analyzers stay bounded. */
    public const MAX_DEPTH = 256;

    /** Whitespace around media queries and their tokens: trim()'s set plus the form feed, which CSS also treats as whitespace. */
    private const MEDIA_TRIM = " \t\n\r\0\x0B\f";

    /** Characters that end a media type (PCRE \s and the opening parenthesis of a feature). */
    private const MEDIA_TYPE_END = " \t\n\r\f\x0B(";

    private const IDENTIFIER_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789-_';

    /** Longest media feature name or value compared; longer ones cannot be a preference feature or its default. */
    private const MAX_FEATURE_NAME_BYTES = 32;

    /** Nesting levels of parentheses a media condition is evaluated through; deeper conditions are unknown (viewport). */
    private const MAX_MEDIA_DEPTH = 16;

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
        /** @var list<string> */
        private array $warnings = [],
    ) {}

    /**
     * @param  array{fragment?: ?bool, ignoredSelectors?: list<string>}  $options
     */
    public static function fromHtml(string $html, array $options = []): self
    {
        $dom = new DOMDocument;
        $bounded = strlen($html) <= self::MAX_HUGE_PARSE_BYTES;

        if (str_starts_with($html, "\xEF\xBB\xBF")) {
            $html = substr($html, 3);
        }

        if (trim($html) === '') {
            return new self($dom, false, true);
        }

        $fragment = $options['fragment'] ?? ! (self::startsLikeDocument($html) || preg_match('/<html[\s>]/i', $html) === 1);
        $html = MarkupScanner::normalize($html, $depthBound);
        $huge = $bounded && $depthBound <= self::MAX_HUGE_PARSE_DEPTH;
        $hinted = self::needsEncodingHint($html);

        if ($hinted) {
            $html = self::ENCODING_HINT.$html;
        }

        $flags = LIBXML_HTML_NODEFDTD | ($huge ? LIBXML_PARSEHUGE : 0) | ($fragment ? LIBXML_HTML_NOIMPLIED : 0);
        $previous = libxml_use_internal_errors(true);
        $warnings = [];

        try {
            libxml_clear_errors();
            $dom->loadHTML($html, $flags);

            if (self::hitDepthLimit()) {
                $warnings[] = 'The document is nested too deeply for the parser; content after that point was not analyzed.';
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($hinted) {
            self::removeEncodingHint($dom);
        }

        $document = new self($dom, $fragment, false, $warnings);

        // Before flattening, so ignored elements below the cap are removed too; both detach deepest first.
        if (! empty($options['ignoredSelectors'])) {
            $document->removeMatching($options['ignoredSelectors']);
        }

        if (self::flattenDeepNesting($dom)) {
            array_unshift($document->warnings, 'Nesting deeper than '.self::MAX_DEPTH.' levels was flattened; the structure below that depth is approximate.');
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

    /** @return list<string> Plain-English notes on input the parser could only handle approximately. */
    public function warnings(): array
    {
        return $this->warnings;
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

    /** @return list<string> the media attribute of every screen stylesheet ('' when absent), in the order of styleSheets() */
    public function styleSheetMedia(): array
    {
        return array_map(fn (DOMElement $style): string => $style->getAttribute('media'), $this->styleElements());
    }

    /**
     * Whether a media query list (a media attribute or an @media prelude) applies to a screen: a query for the media
     * type all or screen (not negated), or a media condition without a type (`(…)`, `not (…)`). Media features
     * decide the condition (mediaCondition()), not whether the list applies.
     */
    public static function mediaAppliesToScreen(string $media): bool
    {
        return self::mediaCondition($media) !== false;
    }

    /**
     * The condition a media query list puts on the rules it guards: false when no query applies to a screen (see
     * mediaAppliesToScreen()), else the weakest condition among the queries that apply. A query's media condition is
     * evaluated with the default user preferences (CssParser::PREFERENCE_DEFAULTS) and every other media feature
     * unknown: null when it holds (or there is none, or the list is empty), 'preference' when it fails (another theme),
     * 'viewport' when it depends on another feature or cannot be parsed. One linear pass: strspn()/strcspn() bounded by
     * the query, parentheses followed at most MAX_MEDIA_DEPTH levels deep, no regular expression, and only tokens of at
     * most MAX_FEATURE_NAME_BYTES are copied.
     *
     * @return 'preference'|'viewport'|false|null
     */
    public static function mediaCondition(string $media): string|false|null
    {
        $length = strlen($media);

        if (strspn($media, self::MEDIA_TRIM) === $length) {
            return null;
        }

        $result = false;

        for ($position = 0; $position <= $length; $position = $end + 1) {
            $end = $position + strcspn($media, ',', $position);
            $condition = self::queryCondition($media, $position, $end);

            if ($condition === null) {
                return null;
            }

            if ($condition !== false && ($result === false || $condition === 'viewport')) {
                $result = $condition;
            }
        }

        return $result;
    }

    /**
     * The condition of the media query between $start and $end: false when it is empty or does not apply to a screen.
     * `[only|not] <type> [and <condition>]` keeps 3.0's reading (`not` negates the type); a query that starts with a
     * parenthesis, `not (` or `only (` is a media condition for every media type.
     *
     * @return 'preference'|'viewport'|false|null
     */
    private static function queryCondition(string $media, int $start, int $end): string|false|null
    {
        $position = $start + strspn($media, self::MEDIA_TRIM, $start, $end - $start);
        $last = $end;

        while ($last > $position && str_contains(self::MEDIA_TRIM, $media[$last - 1])) {
            $last--;
        }

        if ($position >= $last) {
            return false;
        }

        $word = self::mediaKeyword($media, $position, $last);
        $next = $position + strlen($word);
        $next += strspn($media, self::MEDIA_TRIM, $next, $last - $next);

        if ($media[$position] === '(' || (($word === 'not' || $word === 'only') && $next < $last && $media[$next] === '(')) {
            $position = $word === 'only' ? $next : $position;

            return self::conditionFrom($media, $position, $last, true);
        }

        $negated = false;
        $prefix = strtolower(substr($media, $position, 5));

        if ($prefix === 'only ' && $position + 5 < $last) {
            $position += 5;
        } elseif (str_starts_with($prefix, 'not ') && $position + 4 < $last) {
            $negated = true;
            $position += 4;
        }

        $position += strspn($media, self::MEDIA_TRIM, $position, $last - $position);
        $typeLength = strcspn($media, self::MEDIA_TYPE_END, $position, $last - $position);
        $type = $typeLength > 0 && $typeLength <= 6 ? strtolower(substr($media, $position, $typeLength)) : '';

        if (in_array($type, ['all', 'screen'], true) === $negated) {
            return false;
        }

        $position += $typeLength;
        $position += strspn($media, self::MEDIA_TRIM, $position, $last - $position);

        if ($position >= $last) {
            return null; // a media type alone
        }

        if (self::mediaKeyword($media, $position, $last) !== 'and') {
            return 'viewport'; // not a valid query: unknown
        }

        $position += 3;

        return self::conditionFrom($media, $position, $last, false);
    }

    /**
     * The condition of the media condition from $position to the end of its query: null when it holds, 'preference'
     * when it fails, 'viewport' when it is unknown or cannot be parsed.
     *
     * @return 'preference'|'viewport'|null
     */
    private static function conditionFrom(string $media, int $position, int $last, bool $allowOr): ?string
    {
        $failed = false;
        $value = self::evaluateCondition($media, $position, $last, 0, $allowOr, $failed);
        $position += strspn($media, self::MEDIA_TRIM, $position, $last - $position);

        return match (true) {
            $failed || $position < $last, $value === null => 'viewport',
            $value => null,
            default => 'preference',
        };
    }

    /**
     * Evaluate `not <in-parens>` or `<in-parens> [and|or <in-parens>]*` (one operator kind; `or` only where $allowOr),
     * three-valued: true, false, or null (unknown). Stops before a `)` or the end; sets $failed on a syntax error.
     */
    private static function evaluateCondition(string $media, int &$position, int $last, int $depth, bool $allowOr, bool &$failed): ?bool
    {
        $position += strspn($media, self::MEDIA_TRIM, $position, $last - $position);

        if (self::mediaKeyword($media, $position, $last) === 'not') {
            $position += 3;
            $value = self::evaluateInParens($media, $position, $last, $depth, $failed);

            return $value === null ? null : ! $value;
        }

        $value = self::evaluateInParens($media, $position, $last, $depth, $failed);
        $operator = null;

        while (! $failed) {
            $position += strspn($media, self::MEDIA_TRIM, $position, $last - $position);

            if ($position >= $last || $media[$position] === ')') {
                break;
            }

            $word = self::mediaKeyword($media, $position, $last);

            if (! ($word === 'and' || ($word === 'or' && $allowOr)) || ($operator !== null && $word !== $operator)) {
                $failed = true;

                break;
            }

            $operator = $word;
            $position += strlen($word);
            $operand = self::evaluateInParens($media, $position, $last, $depth, $failed);
            $value = $word === 'and'
                ? ($value === false || $operand === false ? false : ($value === true && $operand === true ? true : null))
                : ($value === true || $operand === true ? true : ($value === false && $operand === false ? false : null));
        }

        return $value;
    }

    /** Evaluate `( <condition> )` or `( <feature> )` (anything else in parentheses is unknown), advancing past the `)`. */
    private static function evaluateInParens(string $media, int &$position, int $last, int $depth, bool &$failed): ?bool
    {
        $position += strspn($media, self::MEDIA_TRIM, $position, $last - $position);

        if ($failed || $position >= $last || $media[$position] !== '(' || $depth >= self::MAX_MEDIA_DEPTH) {
            $failed = true;

            return null;
        }

        $position++;
        $position += strspn($media, self::MEDIA_TRIM, $position, $last - $position);

        if ($position < $last && ($media[$position] === '(' || self::mediaKeyword($media, $position, $last) === 'not')) {
            $value = self::evaluateCondition($media, $position, $last, $depth + 1, true, $failed);

            if ($failed || $position >= $last || $media[$position] !== ')') {
                $failed = true;

                return null;
            }

            $position++;

            return $value;
        }

        $start = $position;

        for ($nesting = 0; ; $position++) { // to the matching parenthesis: values may hold functions such as calc()
            $position += strcspn($media, '()', $position, $last - $position);

            if ($position >= $last) {
                $failed = true;

                return null;
            }

            if ($media[$position] === '(') {
                $nesting++;
            } elseif ($nesting-- === 0) {
                break;
            }
        }

        $value = self::evaluateFeature($media, $start, $position);
        $position++;

        return $value;
    }

    /**
     * A media feature between its parentheses: a preference feature compared with its default value (in a boolean
     * context true unless the default is `none` or `no-preference`); every other feature, a range and general-enclosed
     * text are unknown (null).
     */
    private static function evaluateFeature(string $media, int $start, int $end): ?bool
    {
        $colon = $start + strcspn($media, ':', $start, $end - $start);
        $name = self::mediaToken($media, $start, $colon);
        $default = $name === null ? null : CssParser::PREFERENCE_DEFAULTS[$name] ?? null;

        if ($default === null) {
            return null;
        }

        if ($colon === $end) {
            return ! in_array($default, ['none', 'no-preference'], true);
        }

        return self::mediaToken($media, $colon + 1, $end) === $default;
    }

    /** The lowercased identifier between $start and $end (whitespace trimmed), or null when it is none or longer than MAX_FEATURE_NAME_BYTES. */
    private static function mediaToken(string $media, int $start, int $end): ?string
    {
        $start += strspn($media, self::MEDIA_TRIM, $start, $end - $start);

        while ($end > $start && str_contains(self::MEDIA_TRIM, $media[$end - 1])) {
            $end--;
        }

        $length = $end - $start;

        if ($length === 0 || $length > self::MAX_FEATURE_NAME_BYTES || strspn($media, self::IDENTIFIER_CHARACTERS, $start, $length) !== $length) {
            return null;
        }

        return strtolower(substr($media, $start, $length));
    }

    /** The keyword (an identifier of at most four bytes, lowercased) at $position, else ''. */
    private static function mediaKeyword(string $media, int $position, int $last): string
    {
        $length = strspn($media, self::IDENTIFIER_CHARACTERS, $position, $last - $position);

        return $length > 0 && $length <= 4 ? strtolower(substr($media, $position, $length)) : '';
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
            self::detach($element);
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

    private static function hitDepthLimit(): bool
    {
        foreach (libxml_get_errors() as $error) {
            if (str_contains($error->message, 'Excessive depth')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unwraps container elements nested deeper than MAX_DEPTH into the element at the cap, iteratively and in linear
     * time. Leaf elements and text are kept in document order; only the container wrappers disappear.
     */
    private static function flattenDeepNesting(DOMDocument $dom): bool
    {
        $xpath = new DOMXPath($dom);
        $probe = $xpath->query('/'.implode('/', array_fill(0, self::MAX_DEPTH + 1, '*')));

        if ($probe === false || $probe->length === 0) {
            return false;
        }

        $anchors = $xpath->query('/'.implode('/', array_fill(0, self::MAX_DEPTH - 1, '*')));

        foreach ($anchors === false ? [] : iterator_to_array($anchors) as $anchor) {
            if (! self::hasContainerChild($anchor)) {
                continue;
            }

            $kept = [];
            $tops = [];
            $stack = array_reverse(iterator_to_array($anchor->childNodes));

            while ($stack !== []) {
                $node = array_pop($stack);
                $children = $node instanceof DOMElement ? iterator_to_array($node->childNodes) : [];

                if (! self::hasElement($children)) {
                    $kept[] = $node;
                } else {
                    array_push($stack, ...array_reverse($children));

                    if ($node->parentNode === $anchor) {
                        $tops[] = $node;
                    }
                }
            }

            // Moving the kept nodes behind the containers leaves nothing but containers below them.
            foreach ($kept as $node) {
                $anchor->appendChild($node);
            }

            foreach ($tops as $top) {
                self::detach($top);
            }
        }

        return true;
    }

    /**
     * Detaches $node after removing its descendants deepest first. ext/libxml frees a detached subtree recursively,
     * which overflows the C stack at tens of thousands of levels; this way no node is detached with children.
     */
    private static function detach(DOMNode $node): void
    {
        $current = $node;

        while (true) {
            while ($current->lastChild !== null) {
                $current = $current->lastChild;
            }

            if ($current === $node) {
                break;
            }

            $parent = $current->parentNode;
            $parent->removeChild($current);
            $current = $parent;
        }

        $node->parentNode?->removeChild($node);
    }

    private static function hasContainerChild(DOMNode $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && self::hasElement(iterator_to_array($child->childNodes))) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, DOMNode>  $nodes */
    private static function hasElement(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                return true;
            }
        }

        return false;
    }

    private static function startsLikeDocument(string $html): bool
    {
        $offset = 0;

        while (true) {
            $offset += strspn($html, " \t\n\r\f\v", $offset);

            if (substr_compare($html, '<!--', $offset, 4) === 0) {
                $end = strpos($html, '-->', $offset + 4);
            } elseif (substr_compare($html, '<?', $offset, 2) === 0) {
                $end = strpos($html, '?>', $offset + 2);
            } else {
                break;
            }

            if ($end === false) {
                return false;
            }

            $offset = $end + ($html[$end] === '-' ? 3 : 2);
        }

        return preg_match(self::DOCUMENT_START, $html, $m, 0, $offset) === 1;
    }

    /** libxml < 2.14 parses the hint as a processing instruction, >= 2.14 as a comment; neither may stay in the DOM. */
    private static function removeEncodingHint(DOMDocument $dom): void
    {
        foreach (iterator_to_array($dom->childNodes) as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
            }
        }

        $comment = (new DOMXPath($dom))->query('(//comment())[1]')->item(0);

        if ($comment !== null && trim($comment->data) === trim(self::ENCODING_HINT, '<>')) {
            $comment->parentNode?->removeChild($comment);
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

        $head = substr($html, 0, strspn($html, implode('', array_map('chr', range(0, 127)))));

        $offset = 0;

        while (($start = stripos($head, '<meta', $offset)) !== false && ($end = strpos($head, '>', $start)) !== false) {
            $offset = $end + 1;
            $charset = self::metaCharset(substr($head, $start + 5, $end - $start - 5));

            if ($charset !== null) {
                return preg_match('/^utf-?8$/i', $charset) === 1;
            }
        }

        return true;
    }

    /** The charset a meta tag declares: its charset attribute, or the content of an http-equiv="content-type" one. */
    private static function metaCharset(string $attributes): ?string
    {
        preg_match_all('~([^\s"\'<>/=]++)(?:\s*+=\s*+(?:"([^"]*+)"|\'([^\']*+)\'|([^\s"\'>]++)))?~', $attributes, $m, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        $values = [];

        foreach ($m as $attribute) {
            $values[strtolower($attribute[1])] ??= trim($attribute[2] ?? $attribute[3] ?? $attribute[4] ?? '');
        }

        if (($values['charset'] ?? '') !== '') {
            return $values['charset'];
        }

        if (strtolower($values['http-equiv'] ?? '') === 'content-type'
            && preg_match('/charset\s*+=\s*+["\']?+\s*+([\w:.-]++)/i', $values['content'] ?? '', $c) === 1) {
            return $c[1];
        }

        return null;
    }
}
