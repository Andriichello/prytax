<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tax_rules', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->date('effective_to')->nullable();

            $table->decimal('single_tax_rate', 6, 4);
            $table->decimal('military_levy_rate', 6, 4);
            $table->decimal('excess_rate', 6, 4);
            $table->decimal('min_wage', 10, 2);
            $table->decimal('esv_monthly', 10, 2);
            $table->boolean('esv_mandatory')->default(true);
            $table->unsignedTinyInteger('esv_due_day')->default(19);
            $table->decimal('income_cap_uah', 15, 2);

            $table->timestamps();
        });

        // The rules of 3 група (design.md §1), which every environment needs. Each ends with its year, so a year, whose
        // rules (minimum wage, ЄСВ, cap) aren't added by a migration yet, has none, rather than last year's figures
        DB::table('tax_rules')->insert([
            [
                'effective_from' => '2025-01-01',
                'effective_to' => '2025-12-31',
                'single_tax_rate' => '0.0500',
                'military_levy_rate' => '0.0100',
                'excess_rate' => '0.1500',
                'min_wage' => '8000.00',
                'esv_monthly' => '1760.00',
                'esv_mandatory' => true,
                'esv_due_day' => 19,
                'income_cap_uah' => '9336000.00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'effective_from' => '2026-01-01',
                'effective_to' => '2026-12-31',
                'single_tax_rate' => '0.0500',
                'military_levy_rate' => '0.0100',
                'excess_rate' => '0.1500',
                'min_wage' => '8647.00',
                'esv_monthly' => '1902.34',
                'esv_mandatory' => true,
                'esv_due_day' => 19,
                'income_cap_uah' => '10091049.00',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tax_rules');
    }
};
