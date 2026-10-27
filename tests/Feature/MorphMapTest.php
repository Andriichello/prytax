<?php

namespace Tests\Feature;

use Core\Providers\MorphServiceProvider;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;
use PryTax\Helpers\MorphMap;
use PryTax\Providers\AppServiceProvider;
use Tests\TestCase;

/**
 * Class MorphMapTest.
 *
 * The morph map, whose aliases polymorphic columns store (backups.md §4.2): it is required, every model of api-core
 * and of PryTax has an alias of its own, and an alias, which another class has already, is refused.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature
 */
class MorphMapTest extends TestCase
{
    /**
     * Seed nothing: the test needs no database.
     *
     * @var bool
     */
    protected bool $seed = false;

    /**
     * Test that a model without an alias throws, rather than storing its class name.
     *
     * @return void
     */
    public function testRequiresAnAliasOfEveryModel(): void
    {
        $this->assertTrue(Relation::requiresMorphMap());

        $this->expectException(ClassMorphViolationException::class);

        (new class extends Model {})->getMorphClass();
    }

    /**
     * Test that every model of api-core and of PryTax is mapped to an alias of its own, and back.
     *
     * @return void
     */
    public function testMapsEveryModelToAnAliasOfItsOwn(): void
    {
        foreach ([...MorphServiceProvider::getModelClasses(), ...AppServiceProvider::MODELS] as $model) {
            $alias = MorphServiceProvider::slugWithFolderOf($model);

            $this->assertSame($alias, Relation::getMorphAlias($model), $model);
            $this->assertSame($model, Relation::getMorphedModel($alias), $alias);
        }

        $this->assertSame(Relation::morphMap(), array_unique(Relation::morphMap()));
    }

    /**
     * Test that a model, whose alias is an api-core model's, is refused, and the map is left as it was.
     *
     * @return void
     */
    public function testRefusesAnAliasOfAnotherModel(): void
    {
        $map = Relation::morphMap();

        try {
            MorphMap::register(['PryTax\Models\File']);
            $this->fail('The alias of Core\Models\File was given to another model.');
        } catch (LogicException $exception) {
            $this->assertSame("The morph alias 'models.files' of PryTax\Models\File is Core\Models\File's already.", $exception->getMessage());
        }

        $this->assertSame($map, Relation::morphMap());
    }

    /**
     * Test that two models with the same alias are refused together.
     *
     * @return void
     */
    public function testRefusesTwoModelsWithOneAlias(): void
    {
        $this->expectExceptionMessage("The morph alias 'models.reports' of PryTax\Reports\Models\Report is PryTax\Models\Report's already.");

        MorphMap::register(['PryTax\Models\Report', 'PryTax\Reports\Models\Report']);
    }
}
