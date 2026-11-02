<?php

namespace Tests\Feature\Services\Installation;

use Illuminate\Support\Facades\ParallelTesting;
use PHPUnit\Framework\Attributes\DataProvider;
use PryTax\Services\Installation\InstallationWork;
use Tests\TestCase;

/**
 * Class InstallationWorkTest.
 *
 * Where full backups are made, uploaded and opened (builds.md §4.11): `storage/framework/installation/`, and a
 * directory of its own for each process of a parallel test run, so a test removing its work leaves the others' be.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Installation
 */
class InstallationWorkTest extends TestCase
{
    /**
     * Test that each process of a parallel test run works in a directory of its own, and anything else in the shared
     * one.
     *
     * @param string|false $token
     * @param string $expected
     *
     * @return void
     */
    #[DataProvider('processes')]
    public function testGivesEachProcessOfAParallelRunADirectoryOfItsOwn(string|false $token, string $expected): void
    {
        ParallelTesting::resolveTokenUsing(fn () => $token);

        $path = InstallationWork::path('uploads/a.zip');

        $this->assertSame(storage_path($expected), $path);
    }

    /**
     * Get the processes' tokens, with the path each works in.
     *
     * @return array<string, array{string|false, string}>
     */
    public static function processes(): array
    {
        return [
            'a parallel run\'s third process' => ['3', 'framework/installation_test_3/uploads/a.zip'],
            'no parallel run' => [false, 'framework/installation/uploads/a.zip'],
        ];
    }
}
