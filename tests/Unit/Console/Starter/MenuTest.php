<?php

namespace Tests\Unit\Console\Starter;

use Laravel\Prompts\Key;
use Laravel\Prompts\Prompt;
use PHPUnit\Framework\TestCase;
use PryTax\Console\Starter\Menu;
use PryTax\Console\Starter\MenuRenderer;

/**
 * Class MenuTest.
 *
 * The starter's menu: Esc chooses Quit, the arrows go on from the options to the lines below the box, and those lines
 * are drawn under the box, aligned with its options, with Esc beside Quit.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Unit\Console\Starter
 */
class MenuTest extends TestCase
{
    /**
     * Test that Esc chooses Quit, wherever the highlight is.
     *
     * @return void
     */
    public function testEscapeChoosesQuit(): void
    {
        $menu = $this->menu();

        $menu->emit('key', Key::DOWN);
        $menu->emit('key', Key::ESCAPE);

        $this->assertSame('submit', $menu->state);
        $this->assertSame('quit', $menu->value());
        $this->assertSame('Quit', $menu->label());
    }

    /**
     * Test that the arrows go on from the last option to the lines below the box, and up from the first to Quit.
     *
     * @return void
     */
    public function testArrowsReachTheLinesBelowTheOptions(): void
    {
        $menu = $this->menu();

        $menu->emit('key', Key::DOWN);
        $menu->emit('key', Key::DOWN);
        $menu->emit('key', Key::ENTER);

        $this->assertSame('tools', $menu->value());

        $menu = $this->menu();
        $menu->emit('key', Key::UP);

        $this->assertSame('quit', $menu->value());
    }

    /**
     * Test that the lines below the box are drawn under it, aligned with its options, and Esc at its right edge.
     *
     * @return void
     */
    public function testDrawsTheLinesBelowTheBoxWithEscBesideQuit(): void
    {
        $menu = $this->menu();
        $menu->emit('key', Key::DOWN);
        $menu->emit('key', Key::DOWN);

        $lines = explode(PHP_EOL, preg_replace("/\e\\[[0-9;]*m/", '', (new MenuRenderer($menu))($menu)));
        $bottom = array_key_last(array_filter($lines, fn (string $line) => str_starts_with($line, ' └')));

        $this->assertSame('   › ● Tools', rtrim($lines[$bottom + 1]));
        $this->assertStringStartsWith('     ○ Quit ', $lines[$bottom + 2]);
        $this->assertSame(mb_strlen($lines[$bottom]) - 2, mb_strlen($lines[$bottom + 2]));
        $this->assertStringEndsWith('Esc', $lines[$bottom + 2]);
        $this->assertStringContainsString('│   ○ Desktop', $lines[$bottom - 1]);
    }

    /**
     * Get a menu of two platforms, with Tools and Quit below them.
     *
     * @return Menu
     */
    private function menu(): Menu
    {
        Prompt::interactive();

        return new Menu('Which platform?', ['web' => 'Web', 'desktop' => 'Desktop'], ['tools' => 'Tools', 'quit' => 'Quit']);
    }
}
