<?php

namespace App\Filament\Pages;

use App\Filament\Actions\UpdateEmailAction;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\IdentityVerification\Filament\Concerns\InteractsWithIdentityVerification;

class AccountSettings extends Page
{
    use InteractsWithIdentityVerification;

    protected string $view = 'filament.pages.account-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserCircle;

    protected static ?string $title = 'Account settings';

    protected function getHeaderActions(): array
    {
        return [
            UpdateEmailAction::make(),
        ];
    }
}
