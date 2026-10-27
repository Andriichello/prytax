<?php

namespace PryTax\DataLifecycle\Registry;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Class MorphAliases.
 *
 * The morph aliases of tables, from the morph map (alias → model → its table), which morph pairs store as their type:
 * `models.invoices` for `invoices`. The morph strategy derives the possible owners of morph rows from it, and an
 * import resolves a pair's table through it.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\DataLifecycle\Registry
 */
final class MorphAliases
{
    /**
     * Get the morph alias of every table, whose model is mapped.
     *
     * @return array<string, string> Table → alias.
     */
    public static function byTable(): array
    {
        $aliases = [];

        foreach (Relation::morphMap() as $alias => $class) {
            if (is_string($class) && is_subclass_of($class, Model::class)) {
                $aliases[(new $class)->getTable()] ??= $alias;
            }
        }

        return $aliases;
    }

    /**
     * Get the table of a morph alias, or null, when it maps no model.
     *
     * @param string $alias
     *
     * @return string|null
     */
    public static function tableOf(string $alias): ?string
    {
        $class = Relation::getMorphedModel($alias);

        return $class !== null && is_subclass_of($class, Model::class) ? (new $class)->getTable() : null;
    }
}
