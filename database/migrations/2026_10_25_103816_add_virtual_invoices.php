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
        // A virtual invoice (design.md §4.2) records a payment without an invoice: it has no number, due date, sending
        // or document, so none of them can be set on it. (Its files are refused by the policy: a column with a
        // referential action can't take part in a CHECK.)
        DB::statement(
            'ALTER TABLE invoices ADD CONSTRAINT invoices_virtual_without_document'
            . " CHECK (source <> 'virtual' OR (number IS NULL AND due_at IS NULL AND sent_at IS NULL AND snapshot IS NULL))"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE invoices DROP CHECK invoices_virtual_without_document');
    }
};
