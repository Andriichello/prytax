<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Legal forms, which the abbreviation of a client leaves out of its name (written without dots).
     *
     * @var array<int, string>
     */
    protected const array LEGAL_FORMS = [
        'LLC', 'INC', 'LTD', 'LIMITED', 'GMBH', 'AG', 'SA', 'BV', 'OY', 'AB', 'AS', 'SP', 'Z', 'OO', 'SRO', 'CORP', 'CO',
        'PLC', 'OU', 'TOV', 'PP', 'AT', 'PRAT', 'FOP',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The prefix of a client's invoice numbers (design.md §5), such as BL, unique among the clients of a profile
        Schema::table('clients', function (Blueprint $table) {
            $table->string('abbreviation', 10)->nullable()->after('name');
        });

        // Clients, which existed before, get one derived from the name
        $taken = [];

        DB::table('clients')->orderBy('id')->each(function (object $client) use (&$taken) {
            $abbreviation = $this->unique($this->derive($client->name), $taken[$client->profile_id] ?? []);
            $taken[$client->profile_id][] = $abbreviation;

            DB::table('clients')->where('id', $client->id)->update(['abbreviation' => $abbreviation]);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->string('abbreviation', 10)->nullable(false)->change();
            $table->unique(['profile_id', 'abbreviation']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['profile_id', 'abbreviation']);
            $table->dropColumn('abbreviation');
        });
    }

    /**
     * Derive an abbreviation from a client's name, as the client form suggests it: the initials of up to four words,
     * legal forms left out, or the first three letters of a one-word name, in upper-case Latin letters and digits.
     *
     * @param string $name
     *
     * @return string
     */
    protected function derive(string $name): string
    {
        $words = collect(preg_split('/[\s,]+/u', Str::ascii($name)))
            ->map(fn (string $word) => strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $word)))
            ->filter(fn (string $word) => $word !== '' && !in_array($word, self::LEGAL_FORMS, true))
            ->values();

        $abbreviation = $words->count() > 1
            ? $words->take(4)->map(fn (string $word) => $word[0])->implode('')
            : substr($words->first() ?? '', 0, 3);

        return $abbreviation !== '' ? $abbreviation : 'C';
    }

    /**
     * Make the abbreviation unique among the ones taken in the profile, with a digit suffix: BL, BL2, BL3, …
     *
     * @param string $abbreviation
     * @param array<int, string> $taken
     *
     * @return string
     */
    protected function unique(string $abbreviation, array $taken): string
    {
        $candidate = $abbreviation;

        for ($suffix = 2; in_array($candidate, $taken, true); $suffix++) {
            $candidate = substr($abbreviation, 0, 10 - strlen((string) $suffix)) . $suffix;
        }

        return $candidate;
    }
};
