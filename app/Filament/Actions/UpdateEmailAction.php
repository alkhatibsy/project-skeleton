<?php

namespace App\Filament\Actions;

use App\Actions\Account\UpdateEmailAddress;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Modules\IdentityVerification\Filament\Concerns\RequiresIdentityVerification;
use Modules\IdentityVerification\Filament\Contracts\HasIdentityVerification;

class UpdateEmailAction extends Action implements HasIdentityVerification
{
    use RequiresIdentityVerification;

    public static function getDefaultName(): ?string
    {
        return 'updateEmail';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Change email')
            ->icon(Heroicon::OutlinedEnvelope)
            ->record(fn (): ?User => Filament::auth()->user())
            ->authorize('updateEmail')
            ->requiresIdentityVerification(purpose: 'update_email')
            ->modalHeading('Change email address')
            ->fillForm(fn (User $record): array => ['email' => $record->email])
            ->schema([
                TextInput::make('email')
                    ->label('New email address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(User::class, 'email', ignoreRecord: true),
            ])
            ->action(function (User $record, array $data): void {
                app(UpdateEmailAddress::class)->handle($record, $data['email']);
            })
            ->successNotificationTitle('Email address updated');
    }
}
