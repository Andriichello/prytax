<?php

namespace PryTax\Helpers;

use Core\Providers\MorphServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * Class MorphMap.
 *
 * Registers models in the morph map under api-core's aliases (`models.invoices`), which polymorphic columns (files,
 * settings, sequences, the audit log) and permissions store. An alias is made of a class's last namespace segment and
 * its name, so a PryTax model with the name of an api-core one (a `PryTax\Models\File`) would get `models.files` too,
 * and the morph map, an alias → class array, would silently give the alias to the later one: every `files` row would
 * then load the wrong model. So an alias, which another class has already, is refused at boot (backups.md §4.2).
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Helpers
 */
class MorphMap
{
    /**
     * Register the models under their aliases.
     *
     * @param array<int, class-string> $models
     *
     * @return void
     * @throws LogicException When an alias is another class's already, or two of the models have the same alias.
     */
    public static function register(array $models): void
    {
        $map = [];

        foreach ($models as $model) {
            $alias = MorphServiceProvider::slugWithFolderOf($model);
            $taken = $map[$alias] ?? Relation::getMorphedModel($alias);

            if ($taken !== null && $taken !== $model) {
                throw new LogicException("The morph alias '$alias' of $model is $taken's already.");
            }

            $map[$alias] = $model;
        }

        Relation::morphMap($map);
    }
}
