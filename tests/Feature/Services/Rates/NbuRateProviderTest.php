<?php

namespace Tests\Feature\Services\Rates;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PryTax\Exceptions\ExchangeRateUnavailableException;
use PryTax\Services\Rates\NbuRateProvider;
use Tests\TestCase;

/**
 * Class NbuRateProviderTest.
 *
 * NBU rates, fetched once and cached (design.md §10): the rate is read from the raw body, never through a float,
 * and a failure is an exception, never a silent default.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package Tests\Feature\Services\Rates
 */
class NbuRateProviderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that the rate is fetched for the currency and the date, kept with its digits, and cached.
     *
     * @return void
     */
    public function testFetchesTheRateOnceAndCachesIt(): void
    {
        Http::fake([
            'bank.gov.ua/NBUStatService/v1/statdirectory/exchange*' => Http::response(
                '[{"r030":840,"txt":"Долар США","rate":42.2297,"cc":"USD","exchangedate":"05.10.2026"}]'
            ),
        ]);
        $date = CarbonImmutable::parse('2026-10-05');

        $first = app(NbuRateProvider::class)->rate('usd', $date);
        $second = app(NbuRateProvider::class)->rate('USD', $date);

        $this->assertSame('42.2297', $first->rate);
        $this->assertTrue($first->is($second));
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['valcode'] === 'USD' && $request['date'] === '20261005');
    }

    /**
     * Test that a rate, which a float would round, keeps its decimal digits.
     *
     * @return void
     */
    public function testParsesTheRateWithoutAFloat(): void
    {
        $this->assertSame('48.0412', NbuRateProvider::parseRate('[{"rate": 48.0412}]'));
        $this->assertSame('0.2765', NbuRateProvider::parseRate('[{"rate":0.27654}]'));
        $this->assertNull(NbuRateProvider::parseRate('[]'));
    }

    /**
     * Test that an empty answer or a server error throws, and caches nothing.
     *
     * @return void
     */
    public function testThrowsWhenTheRateIsUnavailable(): void
    {
        Http::fake(['bank.gov.ua/*' => Http::response('[]')]);

        try {
            app(NbuRateProvider::class)->rate('XYZ', CarbonImmutable::parse('2026-10-05'));
            $this->fail('No exception was thrown.');
        } catch (ExchangeRateUnavailableException $exception) {
            $this->assertSame('The NBU rate of XYZ on 05.10.2026 is unavailable. Enter the rate manually.', $exception->getMessage());
        }

        $this->assertDatabaseCount('exchange_rates', 0);
    }
}
