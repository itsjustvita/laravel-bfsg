<?php

namespace ItsJustVita\LaravelBfsg\Dom;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

final class HtmlDocument
{
    private const ENCODING_HINT = '<?xml encoding="UTF-8">';

    private ?DOMXPath $xpath = null;

    /** @var array<string, DOMElement>|null */
    private ?array $byId = null;

    /** @var array<string, int> */
    private array $duplicateIds = [];

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

        $fragment = $options['fragment'] ?? (preg_match('/<html[\s>]/i', $html) !== 1);
        $hinted = self::needsEncodingHint($html);

        if ($hinted) {
            $html = self::ENCODING_HINT.$html;
        }

        $flags = LIBXML_HTML_NODEFDTD | ($fragment ? LIBXML_HTML_NOIMPLIED : 0);
        $previous = libxml_use_internal_errors(true);

        try {
            $dom->loadHTML($html, $flags);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($hinted) {
            foreach (iterator_to_array($dom->childNodes) as $node) {
                if ($node->nodeType === XML_PI_NODE) {
                    $dom->removeChild($node);
                }
            }
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

    /** @return array<string, int> id => number of occurrences (only ids that occur more than once) */
    public function duplicateIds(): array
    {
        $this->elementsById();

        return $this->duplicateIds;
    }

    /** @return list<string> */
    public function styleSheets(): array
    {
        $sheets = [];

        foreach ($this->query('//style') as $style) {
            $media = strtolower(trim($style->getAttribute('media')));

            if ($media !== '' && ! str_contains($media, 'all') && ! str_contains($media, 'screen')) {
                continue;
            }

            $sheets[] = $style->textContent;
        }

        return $sheets;
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

    public function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'".$value."'";
        }

        if (! str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        return "concat('".str_replace("'", "', \"'\", '", $value)."')";
    }

    private static function needsEncodingHint(string $html): bool
    {
        if (str_starts_with(ltrim($html), '<?xml')) {
            return false;
        }

        if (! mb_check_encoding($html, 'UTF-8')) {
            return false;
        }

        return preg_match('/<meta[^>]+charset\s*=/i', $html) !== 1;
    }
}
