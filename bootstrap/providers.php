<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use Modules\IdentityVerification\IdentityVerificationServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    IdentityVerificationServiceProvider::class,
];
