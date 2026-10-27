<?php

use PryTax\DataLifecycle\DataLifecycleServiceProvider;
use PryTax\Providers\AppServiceProvider;
use PryTax\Providers\DataLifecycleBindings;

return [
    AppServiceProvider::class,
    DataLifecycleServiceProvider::class,
    DataLifecycleBindings::class,
];
