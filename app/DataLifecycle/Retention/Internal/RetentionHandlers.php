<?php

namespace PryTax\DataLifecycle\Retention\Internal;

use Illuminate\Contracts\Container\Container;
use LogicException;
use PryTax\DataLifecycle\Contracts\RetentionFamilyHandler;

/**
 * Class RetentionHandlers.
 *
 * The family handlers, which the app and the engine tag (RetentionFamilyHandler::TAG), by family.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Retention\Internal
 */
final class RetentionHandlers
{
    /**
     * RetentionHandlers constructor.
     *
     * @param Container $container
     */
    public function __construct(private readonly Container $container) {}

    /**
     * Get the handler of the family.
     *
     * @param string $family
     *
     * @return RetentionFamilyHandler
     * @throws LogicException When no handler does it.
     */
    public function for(string $family): RetentionFamilyHandler
    {
        foreach ($this->container->tagged(RetentionFamilyHandler::TAG) as $handler) {
            if ($handler instanceof RetentionFamilyHandler && in_array($family, $handler->families(), true)) {
                return $handler;
            }
        }

        throw new LogicException("No retention handler does the family $family.");
    }

    /**
     * Get every family, which a handler does.
     *
     * @return array<int, string>
     */
    public function families(): array
    {
        $families = [];

        foreach ($this->container->tagged(RetentionFamilyHandler::TAG) as $handler) {
            $families = [...$families, ...$handler->families()];
        }

        return $families;
    }
}
