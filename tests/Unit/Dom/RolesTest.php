<?php

namespace ItsJustVita\LaravelBfsg\Tests\Unit\Dom;

use DOMElement;
use ItsJustVita\LaravelBfsg\Dom\HtmlDocument;
use ItsJustVita\LaravelBfsg\Dom\Roles;
use ItsJustVita\LaravelBfsg\Tests\TestCase;

class RolesTest extends TestCase
{
    private function el(string $html): DOMElement
    {
        return HtmlDocument::fromHtml('<html><body>'.$html.'</body></html>')->query('//*[@data-t]')[0];
    }

    public function test_validity_covers_aria_dpub_and_graphics_and_rejects_abstract(): void
    {
        foreach (['button', 'cell', 'generic', 'meter', 'doc-toc', 'graphics-symbol', 'switch', 'none', 'presentation'] as $role) {
            $this->assertTrue(Roles::isValid($role), $role);
        }
        $this->assertFalse(Roles::isValid('widget'));
        $this->assertTrue(Roles::isAbstract('widget'));
        $this->assertFalse(Roles::isValid('foo'));
    }

    public function test_effective_role_is_the_first_valid_token(): void
    {
        $this->assertSame('none', Roles::effective($this->el('<table data-t role="none presentation">')));
        $this->assertSame('navigation', Roles::effective($this->el('<div data-t role="doc-tocx navigation">')));
        $this->assertNull(Roles::effective($this->el('<div data-t role="bogus">')));
        $this->assertNull(Roles::effective($this->el('<div data-t>')));
    }

    public function test_implicit_roles(): void
    {
        $this->assertSame('main', Roles::implicit($this->el('<main data-t>')));
        $this->assertSame('navigation', Roles::implicit($this->el('<nav data-t>')));
        $this->assertSame('banner', Roles::implicit($this->el('<header data-t>')));
        $this->assertNull(Roles::implicit($this->el('<article><header data-t></header></article>')));
        $this->assertSame('contentinfo', Roles::implicit($this->el('<footer data-t>')));
        $this->assertSame('region', Roles::implicit($this->el('<section data-t aria-label="x">')));
        $this->assertNull(Roles::implicit($this->el('<section data-t>')));
        $this->assertSame('link', Roles::implicit($this->el('<a data-t href="/">')));
        $this->assertNull(Roles::implicit($this->el('<a data-t>')));
        $this->assertSame('presentation', Roles::implicit($this->el('<img data-t alt="">')));
        $this->assertSame('img', Roles::implicit($this->el('<img data-t alt="x">')));
        $this->assertSame('status', Roles::implicit($this->el('<output data-t>')));
        $this->assertSame('checkbox', Roles::implicit($this->el('<input data-t type="checkbox">')));
        $this->assertSame('slider', Roles::implicit($this->el('<input data-t type="range">')));
        $this->assertSame('textbox', Roles::implicit($this->el('<input data-t>')));
        $this->assertSame('button', Roles::implicit($this->el('<input data-t type="submit">')));
        $this->assertSame('heading', Roles::implicit($this->el('<h2 data-t>')));
        $this->assertSame('listbox', Roles::implicit($this->el('<select data-t multiple>')));
        $this->assertSame('combobox', Roles::implicit($this->el('<select data-t>')));
        $this->assertNull(Roles::implicit($this->el('<div data-t>')));
    }

    public function test_required_and_supported_states(): void
    {
        $this->assertSame(['aria-valuenow'], Roles::requiredStates('slider'));
        $this->assertSame(['aria-checked'], Roles::requiredStates('checkbox'));
        $this->assertSame(['aria-expanded'], Roles::requiredStates('combobox'));
        $this->assertSame(['aria-level'], Roles::requiredStates('heading'));
        $this->assertSame([], Roles::requiredStates('tab'));

        $this->assertTrue(Roles::supportsState('row', 'aria-selected'));
        $this->assertTrue(Roles::supportsState('treeitem', 'aria-selected'));
        $this->assertTrue(Roles::supportsState('menuitemcheckbox', 'aria-checked'));
        $this->assertTrue(Roles::supportsState('button', 'aria-pressed'));
        $this->assertFalse(Roles::supportsState('generic', 'aria-selected'));
        $this->assertFalse(Roles::supportsState('link', 'aria-checked'));
    }

    public function test_live_and_roving(): void
    {
        $this->assertTrue(Roles::isLive('status'));
        $this->assertTrue(Roles::isLive('marquee'));
        $this->assertFalse(Roles::isLive('progressbar'));
        $this->assertFalse(Roles::isLive('timer'));

        $this->assertTrue(Roles::isRoving($this->el('<button data-t role="tab" tabindex="-1">')));
        $this->assertTrue(Roles::isRoving($this->el('<div role="toolbar"><button data-t tabindex="-1"></button></div>')));
        $this->assertFalse(Roles::isRoving($this->el('<button data-t tabindex="-1">')));
    }
}
