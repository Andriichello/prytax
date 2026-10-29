<?php

use Illuminate\Database\Migrations\Migration;
use PryTax\Helpers\CheckConstraint;

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
        CheckConstraint::add(
            'invoices',
            'invoices_virtual_without_document',
            "source <> 'virtual' OR (number IS NULL AND due_at IS NULL AND sent_at IS NULL AND snapshot IS NULL)",
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        CheckConstraint::drop('invoices', 'invoices_virtual_without_document');
    }
};
