<?php

namespace PryTax\Console\Starter;

use Laravel\Prompts\Key;
use Laravel\Prompts\Terminal;

/**
 * Class EscapeTerminal.
 *
 * The prompts' terminal for the starter: Esc reaches a prompt as Ctrl+C, which cancels it and quits, unless a menu,
 * which chooses Quit on Esc itself, is asking.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
class EscapeTerminal extends Terminal
{
    /**
     * Whether Esc reaches the prompt as itself, rather than as Ctrl+C.
     *
     * @var bool
     */
    public bool $passesEscape = false;

    /**
     * Read a key from the terminal.
     *
     * @return string
     */
    public function read(): string
    {
        $key = parent::read();

        return $key === Key::ESCAPE && !$this->passesEscape ? Key::CTRL_C : $key;
    }
}
