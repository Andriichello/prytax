<?php

namespace PryTax\Models;

use Carbon\Carbon;
use Core\Models\BaseModel;
use Core\Models\Interfaces\LockableInterface;
use Core\Models\Traits\Audited;
use Core\Models\Traits\LockedByParent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Class InvoicePaymentSection.
 *
 * A «Payment details (…)» section of an invoice (invoice.md §4): one of its receiving account's requisite sets, in
 * its place, with the text in the parentheses (null: the set's name) and whether it prints. Of two sections or more,
 * every one prints its parentheses. It can't change while its invoice is locked: the snapshot keeps what it printed.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $requisite_set_id
 * @property int $position
 * @property string|null $title
 * @property bool $show_title
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @property Invoice $invoice
 * @property AccountRequisiteSet $requisiteSet
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Models
 */
class InvoicePaymentSection extends BaseModel
{
    use Audited;
    use LockedByParent;

    /**
     * The attributes that are mass-assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'requisite_set_id',
        'position',
        'title',
        'show_title',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'requisite_set_id' => 'int',
        'position' => 'int',
        'show_title' => 'boolean',
    ];

    /**
     * Invoice of the section.
     *
     * @return BelongsTo
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Set of requisites, which the section prints.
     *
     * @return BelongsTo
     */
    public function requisiteSet(): BelongsTo
    {
        return $this->belongsTo(AccountRequisiteSet::class, 'requisite_set_id');
    }

    /**
     * Get the text in the parentheses: the section's own, else its set's name, or null.
     *
     * @return string|null
     */
    public function titleText(): ?string
    {
        $title = trim((string) ($this->title ?? $this->requisiteSet?->name));

        return $title === '' ? null : $title;
    }

    /**
     * The record, which locks the section: its invoice, found without the profile's scope, so the lock holds in code,
     * which runs outside of a profile, such as a console command.
     *
     * @return (Model&LockableInterface)|null
     */
    public function lockingParent(): (Model&LockableInterface)|null
    {
        if ($this->relationLoaded('invoice') && $this->getRelation('invoice') !== null) {
            return $this->getRelation('invoice');
        }

        return Invoice::query()->withoutGlobalScopes()->find($this->getAttribute('invoice_id'));
    }
}
