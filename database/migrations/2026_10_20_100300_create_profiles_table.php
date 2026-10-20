<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One ФОП of an organization: its tax identity, and the tenant of every domain record
        Schema::create('profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('tax_group', 2)->default('3');
            $table->string('full_name');
            $table->string('full_name_en')->nullable();
            $table->text('tax_id');
            $table->string('tax_id_kind', 16)->default('rnokpp');
            $table->char('tax_id_hash', 64);
            $table->date('registered_at')->nullable();

            $table->text('address')->nullable();
            $table->text('address_en')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->char('default_currency', 3)->default('USD');
            $table->string('locale', 8)->default('en');

            $table->boolean('is_active')->default(true);
            $table->date('closed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // tax_id is encrypted, so its HMAC is what is unique
            $table->unique(['organization_id', 'tax_id_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
