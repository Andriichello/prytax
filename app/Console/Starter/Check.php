<?php

namespace PryTax\Console\Starter;

/**
 * Class Check.
 *
 * One thing the doctor checked: fine, a warning (some ways of running PryTax won't work), or a failure, with what
 * fixes it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Console\Starter
 */
final readonly class Check
{
    /**
     * The status of a check, which is fine.
     *
     * @var string
     */
    public const string OK = 'ok';

    /**
     * The status of a check, which some ways of running PryTax need.
     *
     * @var string
     */
    public const string WARN = 'warn';

    /**
     * The status of a check, which PryTax needs.
     *
     * @var string
     */
    public const string FAIL = 'fail';

    /**
     * Check constructor.
     *
     * @param string $name
     * @param string $status One of OK, WARN and FAIL.
     * @param string $detail What was found.
     * @param string|null $hint How to fix it.
     */
    public function __construct(public string $name, public string $status, public string $detail, public ?string $hint = null) {}

    /**
     * Get a check, which is fine.
     *
     * @param string $name
     * @param string $detail
     *
     * @return self
     */
    public static function ok(string $name, string $detail): self
    {
        return new self($name, self::OK, $detail);
    }

    /**
     * Get a check, which some ways of running PryTax need.
     *
     * @param string $name
     * @param string $detail
     * @param string $hint
     *
     * @return self
     */
    public static function warn(string $name, string $detail, string $hint): self
    {
        return new self($name, self::WARN, $detail, $hint);
    }

    /**
     * Get a check, which PryTax needs.
     *
     * @param string $name
     * @param string $detail
     * @param string $hint
     *
     * @return self
     */
    public static function fail(string $name, string $detail, string $hint): self
    {
        return new self($name, self::FAIL, $detail, $hint);
    }

    /**
     * Determine whether the check failed.
     *
     * @return bool
     */
    public function failed(): bool
    {
        return $this->status === self::FAIL;
    }
}
