<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Dom;

use ItsJustVita\LaravelBfsg\Dom\AccessibleName;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class AccessibleNameTest extends TestCase
{
    private function nameOf(string $html, string $xpath = '//*[@data-t]'): string
    {
        $doc = HtmlDocument::fromHtml('<html><body>'.$html.'</body></html>');

        return AccessibleName::of($doc->query($xpath)[0], $doc);
    }

    public function test_aria_labelledby_wins_and_joins_references(): void
    {
        $this->assertSame('Vorname Pflichtfeld', $this->nameOf('<span id="a">Vorname</span><span id="b" hidden>Pflichtfeld</span><input data-t aria-labelledby="a  b" aria-label="ignored">'));
        $this->assertSame('fallback', $this->nameOf('<input data-t aria-labelledby="missing" aria-label="fallback">'));
    }

    public function test_unrendered_content_is_skipped_unless_reached_through_aria_labelledby(): void
    {
        $this->assertSame('', $this->nameOf('<button data-t><span hidden>X</span></button>'));
        $this->assertSame('Save', $this->nameOf('<button data-t>Save<span style="display: none">draft</span><span style="visibility:hidden">!</span></button>'));
        $this->assertSame('Main menu', $this->nameOf('<button data-t aria-labelledby="l"></button><span id="l" hidden>Main <span hidden>menu</span></span>'), 'aria-labelledby includes hidden text');
    }

    public function test_aria_label_then_title(): void
    {
        $this->assertSame('Suche', $this->nameOf('<input data-t aria-label=" Suche ">'));
        $this->assertSame('Suchbegriff', $this->nameOf('<input data-t type="search" title="Suchbegriff">'));
    }

    public function test_images_and_areas_use_alt(): void
    {
        $this->assertSame('Acme GmbH', $this->nameOf('<img data-t src="l.svg" alt="Acme GmbH">'));
        $this->assertSame('', $this->nameOf('<img data-t src="l.svg" alt="">'));
        $this->assertSame('', $this->nameOf('<img data-t src="l.svg">'));
        $this->assertSame('Zone', $this->nameOf('<map><area data-t href="#" alt="Zone"></map>'));
    }

    public function test_inputs_use_labels_and_values(): void
    {
        $this->assertSame('E-Mail', $this->nameOf('<label for="e">E-Mail</label><input data-t id="e">'));
        $this->assertSame('Nachricht', $this->nameOf('<label>Nachricht <textarea data-t></textarea></label>'));
        $this->assertSame('', $this->nameOf('<label for="x"></label><input data-t id="x">'), 'empty label is no name');
        $this->assertSame('Senden', $this->nameOf('<input data-t type="submit" value="Senden">'));
        $this->assertSame('Suchen', $this->nameOf('<input data-t type="image" src="go.png" alt="Suchen">'));
        $this->assertSame('E-Mail', $this->nameOf('<label for="o\'x">E-Mail</label><input data-t id="o\'x">'));
    }

    public function test_links_buttons_and_headings_use_content_including_descendant_names(): void
    {
        $this->assertSame('Read more', $this->nameOf('<a data-t href="/">Read <b>more</b></a>'));
        $this->assertSame('Acme GmbH', $this->nameOf('<a data-t href="/"><img src="logo.svg" alt="Acme GmbH"></a>'));
        $this->assertSame('', $this->nameOf('<a data-t href="https://twitter.com/x"><i class="fa fa-twitter"></i></a>'));
        $this->assertSame('Twitter', $this->nameOf('<a data-t href="/"><svg><title>Twitter</title></svg></a>'));
        $this->assertSame('Schließen', $this->nameOf('<button data-t><span aria-hidden="true">×</span><span class="sr-only">Schließen</span></button>'));
        $this->assertSame('Acme', $this->nameOf('<h1 data-t><a href="/"><img src="l.svg" alt="Acme"></a></h1>'));
        $this->assertSame('Menü öffnen', $this->nameOf('<button data-t><span aria-label="Menü öffnen"></span></button>'));
    }

    public function test_tables_figures_and_fieldsets(): void
    {
        $this->assertSame('Preise', $this->nameOf('<table data-t><caption>Preise</caption><tr><td>x</td></tr></table>'));
        $this->assertSame('Grafik', $this->nameOf('<figure data-t><img src="x" alt=""><figcaption>Grafik</figcaption></figure>'));
        $this->assertSame('Anrede', $this->nameOf('<fieldset data-t><legend>Anrede</legend></fieldset>'));
    }

    public function test_authored_name_ignores_content_and_native_sources(): void
    {
        $doc = HtmlDocument::fromHtml('<html><body><h2 id="t">Anmelden</h2>'
            .'<div role="dialog" aria-labelledby="t" id="a"><p>Inhalt</p></div>'
            .'<div role="dialog" id="b"><p>Inhalt</p></div>'
            .'<iframe id="c" title=" Karte "></iframe>'
            .'<section id="d" aria-label="Neuigkeiten"><h2>x</h2></section>'
            .'</body></html>');
        $byId = $doc->elementsById();

        $this->assertSame('Anmelden', AccessibleName::authored($byId['a'], $doc));
        $this->assertSame('', AccessibleName::authored($byId['b'], $doc));
        $this->assertSame('Inhalt', AccessibleName::of($byId['b'], $doc));
        $this->assertSame('Karte', AccessibleName::authored($byId['c'], $doc));
        $this->assertSame('Neuigkeiten', AccessibleName::authored($byId['d'], $doc));
    }
}
