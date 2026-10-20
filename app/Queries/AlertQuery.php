<?php

namespace PryTax\Queries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Class AlertQuery.
 *
 * Query of the notifications of a profile.
 *
 * @author Andrii Prykhodko <andriichello@gmail.com>
 * @package PryTax\Queries
 */
class AlertQuery extends TenantQuery
{
    /**
     * Only the notifications, whose condition still holds.
     *
     * @return static
     */
    public function unresolved(): static
    {
        $this->whereNull($this->qualifyColumn('resolved_at'));

        return $this;
    }

    /**
     * Only the notifications, which the user hasn't read.
     *
     * @param Model $user
     *
     * @return static
     */
    public function unreadBy(Model $user): static
    {
        $this->whereNotExists(fn ($query) => $query->from('alert_reads')
            ->whereColumn('alert_reads.alert_id', $this->qualifyColumn('id'))
            ->where('alert_reads.user_id', $user->getKey()));

        return $this;
    }

    /**
     * Add `read_at`: when the user read each notification, or null.
     *
     * @param Model $user
     *
     * @return static
     */
    public function withReadAtOf(Model $user): static
    {
        if (empty($this->getQuery()->columns)) {
            $this->select($this->qualifyColumn('*'));
        }

        $this->addSelect([
            'read_at' => DB::table('alert_reads')
                ->select('read_at')
                ->whereColumn('alert_reads.alert_id', $this->qualifyColumn('id'))
                ->where('alert_reads.user_id', $user->getKey())
                ->limit(1),
        ]);

        return $this;
    }
}
