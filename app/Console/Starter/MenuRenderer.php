<?php

namespace PryTax\Console\Starter;

use Laravel\Prompts\SelectPrompt;
use Laravel\Prompts\Themes\Default\SelectPromptRenderer;

/**
 * Class MenuRenderer.
 *
 * Draws the starter's menu as a select's box, with the lines that lead out of it below the box, aligned with its
 * options, and Esc beside Quit.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class MenuRenderer extends SelectPromptRenderer
{
    /**
     * Render the menu.
     *
     * @param SelectPrompt $prompt
     *
     * @return string
     */
    public function __invoke(SelectPrompt $prompt): string
    {
        /** @var Menu $prompt */
        $label = $this->truncate($prompt->label, $prompt->terminal()->cols() - 6);

        if ($prompt->state === 'submit') {
            return (string) $this->box($this->dim($label), $this->truncate((string) $prompt->label(), $prompt->terminal()->cols() - 6));
        }

        $options = $this->renderOptions($prompt);
        $title = in_array($prompt->state, ['cancel', 'error'], true) ? $label : $this->cyan($label);
        $this->box($title, $options, color: match ($prompt->state) {
            'cancel' => 'red',
            'error' => 'yellow',
            default => 'gray',
        });
        $this->renderBelow($prompt, $this->longest([...explode(PHP_EOL, $options), $title]));

        return (string) match ($prompt->state) {
            'cancel' => $this->error($prompt->cancelMessage),
            'error' => $this->warning($this->truncate($prompt->error, $prompt->terminal()->cols() - 5)),
            default => $this->when($prompt->hint, fn () => $this->hint($prompt->hint), fn () => $this->newLine()),
        };
    }

    /**
     * Render the lines below the box, as its options are, with Esc at the box's right edge beside Quit.
     *
     * @param Menu $prompt
     * @param int $width The width of the box's content.
     *
     * @return void
     */
    protected function renderBelow(Menu $prompt, int $width): void
    {
        $index = count($prompt->options);

        foreach ($prompt->below as $value => $label) {
            $label = $this->truncate($label, $prompt->terminal()->cols() - 12);
            $line = match (true) {
                $prompt->state === 'cancel' => $this->dim("  ○ {$this->strikethrough($label)}"),
                $prompt->highlighted === $index => "{$this->cyan('›')} {$this->cyan('●')} {$label}",
                default => "  {$this->dim('○')} {$this->dim($label)}",
            };

            $this->line('   ' . ($value === 'quit' ? $this->pad($line, $width - 3) . $this->dim('Esc') : $line));
            $index++;
        }
    }

    /**
     * The number of lines to reserve outside of the scrollable area.
     *
     * @return int
     */
    public function reservedLines(): int
    {
        /** @var Menu $menu */
        $menu = $this->prompt;

        return parent::reservedLines() + count($menu->below);
    }
}
