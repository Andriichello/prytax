<?php

use Core\Http\Controllers\Auth\AuthController;
use Core\Http\Controllers\NotificationController;
use Core\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use PryTax\DataLifecycle\Backup\Http\Controllers\BackupController;
use PryTax\DataLifecycle\Backup\Http\Controllers\BackupPolicyController;
use PryTax\DataLifecycle\Backup\Http\Controllers\BackupRunController;
use PryTax\DataLifecycle\Backup\Http\Controllers\ExportController;
use PryTax\Http\Controllers\AccountController;
use PryTax\Http\Controllers\AccountingController;
use PryTax\Http\Controllers\AccountMovementController;
use PryTax\Http\Controllers\AccountOpeningBalanceController;
use PryTax\Http\Controllers\AccountTransferController;
use PryTax\Http\Controllers\AlertController;
use PryTax\Http\Controllers\BooksController;
use PryTax\Http\Controllers\ChargeTypeController;
use PryTax\Http\Controllers\ClientController;
use PryTax\Http\Controllers\ContractController;
use PryTax\Http\Controllers\DeletedProfileController;
use PryTax\Http\Controllers\EsvObligationController;
use PryTax\Http\Controllers\ExchangeRateController;
use PryTax\Http\Controllers\InvoiceController;
use PryTax\Http\Controllers\InvoiceFileController;
use PryTax\Http\Controllers\PaymentController;
use PryTax\Http\Controllers\PracticeOverviewController;
use PryTax\Http\Controllers\ProfileController;
use PryTax\Http\Controllers\ProfileSettingController;
use PryTax\Http\Controllers\ProfileSignatureController;
use PryTax\Http\Controllers\ProjectController;
use PryTax\Http\Controllers\RefundController;
use PryTax\Http\Controllers\RegistrationController;
use PryTax\Http\Controllers\TaxOpeningBalanceController;
use PryTax\Http\Controllers\TaxPaymentController;
use PryTax\Http\Controllers\TaxPaymentRequisiteController;
use PryTax\Http\Controllers\TaxPeriodController;
use PryTax\Http\Controllers\TaxReportController;

/**
 * Register the routes of a CrudController's operations under the URI, named `{name}.{operation}`.
 *
 * @param string $uri
 * @param class-string $controller
 * @param array<int, string> $operations
 * @param string $name
 *
 * @return void
 */
$crud = function (string $uri, string $controller, array $operations, string $name): void {
    $routes = [
        'index' => ['get', ''],
        'store' => ['post', ''],
        'show' => ['get', '/{id}'],
        'update' => ['patch', '/{id}'],
        'destroy' => ['delete', '/{id}'],
        'restore' => ['post', '/{id}/restore'],
    ];

    foreach ($operations as $operation) {
        [$method, $suffix] = $routes[$operation];

        Route::$method($uri . $suffix, [$controller, $operation])->name("$name.$operation");
    }
};

$all = ['index', 'store', 'show', 'update', 'destroy'];

Route::prefix('auth')->group(fn () => AuthController::routes());

// A new organization from the sign-in page, when `prytax.registration` allows it
RegistrationController::routes();

Route::middleware(['auth:sanctum', 'password.updated'])->group(function () use ($crud, $all) {
    UserController::routes();
    NotificationController::routes();

    Route::middleware('organization')
        ->where(['id' => '[0-9]+', 'profile' => '[0-9]+', 'year' => '20[0-9]{2}', 'quarter' => '[1-4]'])
        ->group(function () use ($crud, $all) {
            $crud('profiles', ProfileController::class, [...$all, 'restore'], 'profiles');

            // Backups of the organization's profiles (docs/specs/backups.md §4.7), whose ids are ULIDs
            Route::prefix('organization')->name('organization.')->where(['id' => '[0-9A-Za-z]{26}'])->group(function () {
                BackupPolicyController::routes();
                BackupRunController::routes();
                Route::get('deleted-profiles', [DeletedProfileController::class, 'deleted'])->name('deleted-profiles');
                BackupController::routes();
            });

            Route::get('overview/{year}/{quarter}', [PracticeOverviewController::class, 'overview'])->name('overview');

            Route::get('exchange-rates', [ExchangeRateController::class, 'index'])->name('exchange-rates.index');
            Route::get('exchange-rates/{currency}/{date}', [ExchangeRateController::class, 'lookup'])
                ->where(['currency' => '[A-Za-z]{3}', 'date' => '[0-9]{4}-[0-9]{2}-[0-9]{2}'])
                ->name('exchange-rates.lookup');

            // A profile's records: the profile is found within the organization, or the request is a 404
            Route::middleware('profile')
                ->prefix('profiles/{profile}')
                ->name('profiles.')
                ->where(['client' => '[0-9]+', 'account' => '[0-9]+', 'invoice' => '[0-9]+', 'payment' => '[0-9]+'])
                ->group(function () use ($crud, $all) {
                    ProfileSettingController::routes('settings', 'settings');
                    ExportController::routes();
                    ProfileSignatureController::routes('signatures', 'signatures');

                    $crud('accounts', AccountController::class, $all, 'accounts');
                    Route::get('accounts/{id}/statement', [AccountController::class, 'statement'])->name('accounts.statement');
                    $crud('accounts/{account}/opening-balances', AccountOpeningBalanceController::class, $all, 'accounts.opening-balances');
                    $crud('accounts/{account}/movements', AccountMovementController::class, $all, 'accounts.movements');

                    $crud('clients', ClientController::class, $all, 'clients');
                    Route::get('clients/{id}/stats', [ClientController::class, 'stats'])->name('clients.stats');
                    $crud('clients/{client}/contracts', ContractController::class, $all, 'clients.contracts');
                    $crud('clients/{client}/projects', ProjectController::class, $all, 'clients.projects');
                    $crud('clients/{client}/charge-types', ChargeTypeController::class, $all, 'clients.charge-types');

                    Route::get('invoices/counters', [InvoiceController::class, 'counters'])->name('invoices.counters');
                    Route::patch('invoices/counters', [InvoiceController::class, 'setCounter'])->name('invoices.counters.update');
                    Route::get('invoices/defaults', [InvoiceController::class, 'defaults'])->name('invoices.defaults');
                    Route::get('invoices/outstanding', [InvoiceController::class, 'outstanding'])->name('invoices.outstanding');
                    Route::get('invoices/facets', [InvoiceController::class, 'facets'])->name('invoices.facets');
                    Route::get('invoices/duplicates', [InvoiceController::class, 'duplicates'])->name('invoices.duplicates');
                    Route::get('invoices/number-preview', [InvoiceController::class, 'previewNumber'])->name('invoices.number-preview');
                    Route::get('invoices/style-preview', [InvoiceController::class, 'stylePreview'])->name('invoices.style-preview');
                    $crud('invoices', InvoiceController::class, $all, 'invoices');

                    foreach (['freeze', 'unfreeze', 'send', 'pay', 'cancel', 'issue'] as $operation) {
                        Route::post("invoices/{id}/$operation", [InvoiceController::class, $operation])->name("invoices.$operation");
                    }

                    Route::get('invoices/{id}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
                    InvoiceFileController::routes('invoices/{invoice}/files', 'invoices.files');

                    Route::middleware('idempotent')->group(function () use ($crud, $all) {
                        Route::post('payments/preview', [PaymentController::class, 'preview'])->name('payments.preview');
                        Route::post('payments/group', [PaymentController::class, 'group'])->name('payments.group');
                        $crud('payments', PaymentController::class, $all, 'payments');
                        $crud('payments/{payment}/refunds', RefundController::class, ['index', 'store', 'show', 'destroy'], 'payments.refunds');

                        Route::post('transfers/propose', [AccountTransferController::class, 'propose'])->name('transfers.propose');
                        $crud('transfers', AccountTransferController::class, $all, 'transfers');

                        $crud('tax-payments', TaxPaymentController::class, $all, 'tax-payments');
                    });

                    Route::get('payments/{id}/flow', [PaymentController::class, 'flow'])->name('payments.flow');
                    Route::post('payments/{id}/acknowledge-repatriation', [PaymentController::class, 'acknowledge'])
                        ->name('payments.acknowledge');
                    Route::get('transfers/stats', [AccountTransferController::class, 'stats'])->name('transfers.stats');

                    $crud('tax-periods', TaxPeriodController::class, ['index', 'show', 'update'], 'tax-periods');
                    $crud('tax-opening-balances', TaxOpeningBalanceController::class, $all, 'tax-opening-balances');
                    Route::get('tax-payment-requisites/current', [TaxPaymentRequisiteController::class, 'current'])
                        ->name('tax-payment-requisites.current');
                    $crud('tax-payment-requisites', TaxPaymentRequisiteController::class, $all, 'tax-payment-requisites');
                    $crud('esv-obligations', EsvObligationController::class, ['index', 'show'], 'esv-obligations');

                    Route::get('alerts/counts', [AlertController::class, 'counts'])->name('alerts.counts');
                    $crud('alerts', AlertController::class, ['index', 'show'], 'alerts');
                    Route::post('alerts/{id}/read', [AlertController::class, 'read'])->name('alerts.read');

                    Route::get('accounting/{year}/{quarter}', [AccountingController::class, 'quarter'])->name('accounting.quarter');
                    Route::get('accounting/{year}/{quarter}/export', [AccountingController::class, 'exportQuarter'])
                        ->name('accounting.export');
                    Route::get('accounting/{year}/export', [AccountingController::class, 'exportYear'])->name('accounting.export-year');
                    Route::get('esv/{year}', [AccountingController::class, 'esv'])->name('esv');
                    Route::get('fees/{year}', [AccountingController::class, 'fees'])->name('fees');
                    Route::get('books/{year}/income', [BooksController::class, 'income'])->whereNumber('year')->name('books.income');
                    Route::get('books/{year}/taxes', [BooksController::class, 'taxes'])->whereNumber('year')->name('books.taxes');
                    Route::get('books/{year}/{book}/export', [BooksController::class, 'export'])
                        ->whereNumber('year')->whereIn('book', ['income', 'taxes'])->name('books.export');
                    Route::get('income-book/{year}', [AccountingController::class, 'incomeBook'])->name('income-book');
                    Route::get('income-book/{year}/export', [AccountingController::class, 'exportIncomeBook'])
                        ->name('income-book.export');

                    Route::get('taxes/{year}', [TaxReportController::class, 'reconciliation'])->name('taxes.reconciliation');
                    Route::get('taxes/{year}/months', [TaxReportController::class, 'byMonth'])->name('taxes.months');
                    Route::get('taxes/{year}/ledger', [TaxReportController::class, 'ledger'])->name('taxes.ledger');
                    Route::get('taxes/{year}/{quarter}/payments', [TaxReportController::class, 'byPayment'])->name('taxes.payments');
                    Route::get('declarations/{year}/{quarter}', [TaxReportController::class, 'declaration'])->name('declarations');
                });
        });
});
