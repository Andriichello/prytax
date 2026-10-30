<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A client's address is the text its invoices print as typed, line by line (the owner, 2026-10-30), no longer
        // parts: its lines, then the city, the state and the postal code, then the country, as the invoices printed them
        $this->convert(function (array $address, ?string $country): ?array {
            if (array_key_exists('text', $address)) {
                return $address;
            }

            $lines = array_values(array_filter([
                ...array_map(fn ($line) => trim((string) $line), $address['lines'] ?? []),
                trim(implode(' ', array_filter([$address['city'] ?? null, $address['state'] ?? null, $address['zip'] ?? null], fn ($part) => filled($part)))),
                trim((string) $country),
            ], fn (string $line) => $line !== ''));

            return $lines === [] ? null : ['text' => implode("\n", $lines)];
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // The text's lines become the address lines; what was the city, the postal code or the country can't be told
        $this->convert(function (array $address): ?array {
            if (!array_key_exists('text', $address)) {
                return $address;
            }

            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $address['text']) ?: []), fn (string $line) => $line !== ''));

            return $lines === [] ? null : ['lines' => $lines];
        });
    }

    /**
     * Rewrite the address of every client, which has one.
     *
     * @param Closure(array, ?string): ?array $rewrite
     *
     * @return void
     */
    protected function convert(Closure $rewrite): void
    {
        DB::table('clients')->whereNotNull('address')->orderBy('id')->select(['id', 'address', 'country'])
            ->each(function (object $client) use ($rewrite) {
                $address = json_decode((string) $client->address, true);

                if (!is_array($address)) {
                    return;
                }

                $rewritten = $rewrite($address, $client->country);

                DB::table('clients')->where('id', $client->id)->update(['address' => $rewritten === null ? null : json_encode($rewritten)]);
            });
    }
};
