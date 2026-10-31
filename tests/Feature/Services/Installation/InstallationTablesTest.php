<?php

namespace Tests\Feature\Services\Installation;

use Illuminate\Database\Eloquent\Model;
use PryTax\Services\Installation\InstallationTables;
use ReflectionClass;
use Tests\TestCase;

/**
 * Class InstallationTablesTest.
 *
 * The encrypted columns, which a full backup's restore encrypts again with this installation's key (builds.md §4.11):
 * exactly those, which PryTax's and api-core's models cast as encrypted, so a new one can't stay unreadable after a
 * restore.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Installation
 */
class InstallationTablesTest extends TestCase
{
    /**
     * Test that the encrypted columns listed are those, which the models cast as encrypted.
     *
     * @return void
     */
    public function testListsEveryColumnTheModelsEncrypt(): void
    {
        $encrypted = [];
        $models = [
            'PryTax\\Models\\' => app_path('Models'),
            'Core\\Models\\' => base_path('vendor/andriichello/api-core/app/Models'),
        ];

        foreach ($models as $namespace => $directory) {
            foreach (glob("$directory/*.php") ?: [] as $file) {
                $class = $namespace . basename($file, '.php');

                if (!class_exists($class) || !is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                    continue;
                }

                $model = new $class;

                foreach ($model->getCasts() as $column => $cast) {
                    if (str_starts_with((string) $cast, 'encrypted')) {
                        $encrypted[$model->getTable()][] = $column;
                    }
                }
            }
        }

        ksort($encrypted);
        $listed = InstallationTables::ENCRYPTED;
        ksort($listed);

        $this->assertSame($listed, $encrypted);
    }
}
