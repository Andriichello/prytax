<?php

namespace PryTax\Services\Invoices;

use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use PryTax\Enums\InvoiceSource;
use PryTax\Models\Account;
use PryTax\Models\AccountRequisiteSet;
use PryTax\Models\Client;
use PryTax\Models\Invoice;
use PryTax\Models\InvoicePaymentSection;

/**
 * Class PaymentSections.
 *
 * The «Payment details (…)» sections of an invoice (invoice.md §4): sets of its receiving account's requisites, in
 * order, each with the text in its parentheses (null: the set's name) and whether it prints them. A new invoice, or
 * one paid into another account, takes those of the client's last invoice to the account, while their sets are
 * active, else the account's default set; of two sections or more, every one prints its parentheses.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Services\Invoices
 */
class PaymentSections
{
    /**
     * How many sections an invoice may print.
     *
     * @var int
     */
    public const int MAX = 5;

    /**
     * Get the sections, which an invoice paid into the account starts with: those of the client's last invoice to
     * the account, whose sets are active, else the account's default set, else none.
     *
     * @param Client|null $client
     * @param Account $account
     * @param Invoice|null $except The invoice, which is being changed, not to be taken as the client's last one.
     *
     * @return list<array{requisite_set_id: int, title: string|null, show_title: bool}>
     */
    public function defaultsFor(?Client $client, Account $account, ?Invoice $except = null): array
    {
        $last = $client?->invoices()
            ->where('receiving_account_id', $account->getKey())
            ->where('source', '!=', InvoiceSource::Virtual->value)
            ->whereHas('paymentSections')
            ->when($except?->exists, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->latest('issued_at')
            ->latest('id')
            ->first();

        $sections = $last === null ? [] : $last->paymentSections()
            ->whereHas('requisiteSet', fn ($query) => $query->where('is_active', true))
            ->get()
            ->map(fn (InvoicePaymentSection $section) => [
                'requisite_set_id' => $section->requisite_set_id,
                'title' => $section->title,
                'show_title' => $section->show_title,
            ])
            ->values()
            ->all();

        if ($sections !== []) {
            return $sections;
        }

        $default = $account->defaultRequisiteSet();

        return $default === null ? [] : [['requisite_set_id' => $default->getKey(), 'title' => null, 'show_title' => false]];
    }

    /**
     * Check the sections of an invoice paid into the account, and get them in order: each a set of the account's,
     * once, active or already printed by the invoice; one alone with its parentheses or without, two or more each
     * with them.
     *
     * @param Account $account
     * @param array<int, array{requisite_set_id?: mixed, title?: string|null, show_title?: bool}> $sections
     * @param Invoice|null $invoice
     *
     * @return list<array{requisite_set_id: int, position: int, title: string|null, show_title: bool}>
     * @throws ValidationException
     */
    public function checked(Account $account, array $sections, ?Invoice $invoice = null): array
    {
        $sections = array_values($sections);
        $sets = $account->requisiteSets()->get()->keyBy('id');
        $printed = $invoice?->exists ? $invoice->paymentSections()->pluck('requisite_set_id')->all() : [];
        $seen = [];
        $checked = [];

        foreach ($sections as $index => $section) {
            $id = (int) ($section['requisite_set_id'] ?? 0);
            /** @var AccountRequisiteSet|null $set */
            $set = $sets->get($id);

            if ($set === null) {
                throw ValidationException::withMessages(["payment_sections.$index.requisite_set_id" => [__('The requisites aren\'t the receiving account\'s.')]]);
            }

            if (!$set->is_active && !in_array($id, $printed, true)) {
                throw ValidationException::withMessages(["payment_sections.$index.requisite_set_id" => [__('The requisites are switched off.')]]);
            }

            if (in_array($id, $seen, true)) {
                throw ValidationException::withMessages(["payment_sections.$index.requisite_set_id" => [__('The invoice prints these requisites already.')]]);
            }

            $title = trim((string) ($section['title'] ?? ''));
            $showTitle = count($sections) > 1 || (bool) ($section['show_title'] ?? false);

            if ($showTitle && $title === '' && blank($set->name)) {
                throw ValidationException::withMessages(["payment_sections.$index.title" => [count($sections) > 1
                    ? __('Of several payment details, each prints its name in parentheses: give it one.')
                    : __('Give the payment details a name to print in parentheses.')]]);
            }

            $seen[] = $id;
            $checked[] = ['requisite_set_id' => $id, 'position' => $index + 1, 'title' => $title === '' ? null : $title, 'show_title' => $showTitle];
        }

        return $checked;
    }

    /**
     * Replace the invoice's sections with the checked ones.
     *
     * @param Invoice $invoice
     * @param list<array{requisite_set_id: int, position: int, title: string|null, show_title: bool}> $sections
     *
     * @return void
     */
    public function replace(Invoice $invoice, array $sections): void
    {
        $invoice->paymentSections()->get()->each->delete();
        $invoice->paymentSections()->createMany($sections);
    }

    /**
     * Give the invoice the sections it starts with, when it has none: an invoice issued from a receipt.
     *
     * @param Invoice $invoice
     *
     * @return void
     */
    public function ensure(Invoice $invoice): void
    {
        $account = $invoice->receivingAccount;

        if ($account === null || $invoice->paymentSections()->exists()) {
            return;
        }

        $sections = $this->defaultsFor($invoice->client, $account, $invoice);
        $this->replace($invoice, array_map(fn (array $section, int $index) => [...$section, 'position' => $index + 1], $sections, array_keys($sections)));
    }

    /**
     * Get the sections as the invoice's snapshot keeps them, each with its title (null, when it prints none), its
     * account and its requisites in their order (`InvoiceRequisites`); without sections of its own, those it starts with.
     *
     * @param Invoice $invoice
     *
     * @return list<array<string, mixed>>
     */
    public function snapshot(Invoice $invoice): array
    {
        $invoice->loadMissing(['paymentSections.requisiteSet.account']);

        /** @var Collection<int, InvoicePaymentSection> $sections */
        $sections = $invoice->paymentSections;

        if ($sections->isEmpty() && $invoice->receivingAccount !== null) {
            $sections = collect($this->defaultsFor($invoice->client, $invoice->receivingAccount, $invoice))
                ->map(fn (array $section) => (new InvoicePaymentSection($section))->setRelation(
                    'requisiteSet',
                    AccountRequisiteSet::query()->with('account')->find($section['requisite_set_id']),
                ));
        }

        return $sections
            ->filter(fn (InvoicePaymentSection $section) => $section->requisiteSet !== null)
            ->map(fn (InvoicePaymentSection $section) => [
                'title' => $section->show_title ? $section->titleText() : null,
                'account' => [
                    'name' => $section->requisiteSet->account->name,
                    'currency' => $section->requisiteSet->account->currency,
                ],
                ...$section->requisiteSet->requisites(),
            ])
            ->values()
            ->all();
    }
}
