<?php

namespace Tests\Unit\Console\Starter;

use Dotenv\Dotenv;
use PHPUnit\Framework\TestCase;
use PryTax\Console\Starter\DockerSettings;

/**
 * Class DockerSettingsTest.
 *
 * The settings of the web app in Docker, which the starter makes from the real `.env.docker.example`: SQLite by
 * default, MySQL in a container with a password of its own, or an external MySQL; each with a key of its own and the
 * file compose finds them in.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Unit\Console\Starter
 */
class DockerSettingsTest extends TestCase
{
    /**
     * Test that the settings are SQLite's, a file in the storage volume, with a key and the file compose reads.
     *
     * @return void
     */
    public function testMakesSqliteSettingsByDefault(): void
    {
        $settings = $this->make(DockerSettings::SQLITE);

        $this->assertSame('sqlite', $settings['DB_CONNECTION']);
        $this->assertSame('/app/storage/database/prytax.sqlite', $settings['DB_DATABASE']);
        $this->assertArrayNotHasKey('DB_HOST', $settings);
        $this->assertSame('.env.docker', $settings['PRYTAX_ENV_FILE']);
        $this->assertSame(32, strlen(base64_decode(substr($settings['APP_KEY'], strlen('base64:')))));
    }

    /**
     * Test that MySQL in a container replaces SQLite's lines with its own and a password of its own, and that an
     * external one points at the host.
     *
     * @return void
     */
    public function testMakesMysqlSettingsInAContainerOrOutside(): void
    {
        $container = $this->make(DockerSettings::MYSQL);
        $external = $this->make(DockerSettings::EXTERNAL);

        $this->assertSame(['mysql', 'mysql', 'prytax', 'prytax'], [$container['DB_CONNECTION'], $container['DB_HOST'], $container['DB_DATABASE'], $container['DB_USERNAME']]);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', $container['DB_PASSWORD']);
        $this->assertSame(['mysql', 'host.docker.internal'], [$external['DB_CONNECTION'], $external['DB_HOST']]);
        $this->assertNotSame($container['DB_PASSWORD'], $external['DB_PASSWORD']);
    }

    /**
     * Make the settings of the example for a database, and read them as compose does.
     *
     * @param string $database
     *
     * @return array<string, string|null>
     */
    private function make(string $database): array
    {
        $example = (string) file_get_contents(dirname(__DIR__, 4) . '/.env.docker.example');

        return Dotenv::parse(DockerSettings::make($example, '.env.docker', $database));
    }
}
