<?php

namespace PryTax\Console\Starter;

use Laravel\Prompts\Key;
use Laravel\Prompts\SelectPrompt;

/**
 * Class Menu.
 *
 * The starter's select: its options in the box, and below it the lines that lead out of it (Tools, Back, Quit),
 * which the arrows reach after the options. Esc chooses Quit; `escapeQuits()` makes it cancel any other prompt too,
 * as Ctrl+C does.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class Menu extends SelectPrompt
{
    /**
     * Menu constructor.
     *
     * @param string $label
     * @param array<string, string> $options The options in the box, by value.
     * @param array<string, string> $below The lines below the box, by value, Quit (`quit`) last.
     * @param string|null $default The value highlighted first: an option's or a line's below.
     * @param string $hint
     */
    public function __construct(string $label, array $options, public array $below = ['quit' => 'Quit'], ?string $default = null, string $hint = '')
    {
        parent::__construct($label, $options, array_key_exists((string) $default, $options) ? $default : null, max(1, count($options)), hint: $hint);

        $this->default = $default;
        $total = count($this->values());

        if (array_key_exists((string) $default, $below)) {
            $this->highlight(array_search($default, $this->values(), true));
        }

        $this->clearListeners();
        $this->on('key', fn (string $key) => match ($key) {
            Key::UP, Key::UP_ARROW, Key::LEFT, Key::LEFT_ARROW, Key::SHIFT_TAB, Key::CTRL_P, Key::CTRL_B, 'k', 'h' => $this->highlightPrevious($total),
            Key::DOWN, Key::DOWN_ARROW, Key::RIGHT, Key::RIGHT_ARROW, Key::TAB, Key::CTRL_N, Key::CTRL_F, 'j', 'l' => $this->highlightNext($total),
            Key::oneOf([Key::HOME, Key::CTRL_A], $key) => $this->highlight(0),
            Key::oneOf([Key::END, Key::CTRL_E], $key) => $this->highlight($total - 1),
            Key::ESCAPE => $this->quit(),
            Key::ENTER => $this->submit(),
            default => null,
        });
    }

    /**
     * Make Esc quit every prompt of the starter: a menu by choosing Quit, any other prompt as Ctrl+C does.
     *
     * @return void
     */
    public static function escapeQuits(): void
    {
        static::$terminal = new EscapeTerminal;
    }

    /**
     * Render the menu and listen for input, with Esc reaching it as itself.
     *
     * @return mixed
     */
    public function prompt(): mixed
    {
        $terminal = static::terminal();

        if ($terminal instanceof EscapeTerminal) {
            $terminal->passesEscape = true;
        }

        try {
            return parent::prompt();
        } finally {
            if ($terminal instanceof EscapeTerminal) {
                $terminal->passesEscape = false;
            }
        }
    }

    /**
     * Get the value of the highlighted option or line.
     *
     * @return int|string|null
     */
    public function value(): int|string|null
    {
        if (static::$interactive === false) {
            return $this->default;
        }

        return $this->values()[$this->highlighted] ?? null;
    }

    /**
     * Get the label of the highlighted option or line.
     *
     * @return string|null
     */
    public function label(): ?string
    {
        return [...array_values($this->options), ...array_values($this->below)][$this->highlighted] ?? null;
    }

    /**
     * Get the values of the options, then of the lines below them.
     *
     * @return list<int|string>
     */
    protected function values(): array
    {
        return [...array_keys($this->options), ...array_keys($this->below)];
    }

    /**
     * Highlight the given index, scrolling the box only to its options.
     *
     * @param int|null $index
     *
     * @return void
     */
    protected function highlight(?int $index): void
    {
        if ($index !== null && $index >= count($this->options)) {
            $this->highlighted = $index;

            return;
        }

        parent::highlight($index);
    }

    /**
     * Choose Quit.
     *
     * @return void
     */
    protected function quit(): void
    {
        $index = array_search('quit', $this->values(), true);

        if ($index !== false) {
            $this->highlight($index);
            $this->submit();
        }
    }

    /**
     * Get the menu's renderer.
     *
     * @return callable
     */
    protected function getRenderer(): callable
    {
        return new MenuRenderer($this);
    }
}
