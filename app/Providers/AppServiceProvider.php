<?php

namespace PryTax\Providers;

use Core\Http\Middleware\RequireUpdatedPassword;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\ServiceProvider;
use PryTax\Helpers\MorphMap;
use PryTax\Http\Middleware\HandleIdempotencyKey;
use PryTax\Http\Middleware\ResolveProfile;
use PryTax\Models\Account;
use PryTax\Models\AccountMovement;
use PryTax\Models\AccountOpeningBalance;
use PryTax\Models\AccountTransfer;
use PryTax\Models\Alert;
use PryTax\Models\ChargeType;
use PryTax\Models\Client;
use PryTax\Models\Contract;
use PryTax\Models\ContractRate;
use PryTax\Models\EsvObligation;
use PryTax\Models\ExchangeRate;
use PryTax\Models\Holiday;
use PryTax\Models\Invoice;
use PryTax\Models\InvoiceItem;
use PryTax\Models\Payment;
use PryTax\Models\Profile;
use PryTax\Models\Project;
use PryTax\Models\Refund;
use PryTax\Models\TaxOpeningBalance;
use PryTax\Models\TaxPayment;
use PryTax\Models\TaxPaymentRequisite;
use PryTax\Models\TaxPeriod;
use PryTax\Models\TaxRule;
use PryTax\Models\TransferAllocation;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Models of PryTax, which the morph map names by stable aliases (`models.invoices`): their permissions, files,
     * settings, sequences and audit log entries use them.
     *
     * @var array<int, class-string>
     */
    public const array MODELS = [
        Account::class,
        AccountMovement::class,
        AccountOpeningBalance::class,
        AccountTransfer::class,
        Alert::class,
        ChargeType::class,
        Client::class,
        Contract::class,
        ContractRate::class,
        EsvObligation::class,
        ExchangeRate::class,
        Holiday::class,
        Invoice::class,
        InvoiceItem::class,
        Payment::class,
        Profile::class,
        Project::class,
        Refund::class,
        TaxOpeningBalance::class,
        TaxPayment::class,
        TaxPaymentRequisite::class,
        TaxPeriod::class,
        TaxRule::class,
        TransferAllocation::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // api-core's own models are mapped by its provider, which boots first. A model left out of the map throws, rather
        // than writing its class name into a polymorphic column (backups.md §4.2)
        MorphMap::register(self::MODELS);
        Relation::requireMorphMap();

        $this->app['router']->aliasMiddleware(ResolveProfile::ALIAS, ResolveProfile::class);
        $this->app['router']->aliasMiddleware(HandleIdempotencyKey::ALIAS, HandleIdempotencyKey::class);

        // The profile is looked up within the organization, which api-core's middleware sets before it
        $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel) {
            if ($kernel instanceof Kernel) {
                $kernel->addToMiddlewarePriorityAfter(RequireUpdatedPassword::class, ResolveProfile::class);
            }
        });
    }
}
