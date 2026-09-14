<?php

declare(strict_types=1);

namespace Magna\Admin\Resources\ApiKey;

use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Carbon;
use Magna\Admin\Resources\ApiKeyResource;
use Magna\Auth\ApiKeyService;
use Magna\Users\User;

class ManageApiKeys extends ManageRecords
{
    protected static string $resource = ApiKeyResource::class;

    protected string $view = 'magna::admin.api-keys';

    /** Plaintext key shown to the admin exactly once after generation. */
    public ?string $generatedKey = null;

    /** Plaintext secret shown to the admin exactly once after generation. */
    public ?string $generatedSecret = null;

    /** Clear the one-time credentials display (user acknowledged). */
    public function clearGenerated(): void
    {
        $this->generatedKey = null;
        $this->generatedSecret = null;
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label('Generate new key')
                ->icon('heroicon-m-plus')
                ->modalHeading('Generate API key')
                ->modalDescription('Choose a scope and name for the new key. The secret will be shown exactly once — copy it immediately.')
                ->modalWidth('lg')
                ->form([
                    TextInput::make('name')
                        ->label('Key name')
                        ->placeholder('e.g. Flutter App — Production')
                        ->required()
                        ->maxLength(255),

                    Select::make('scope')
                        ->label('Scope')
                        ->options([
                            'delivery' => 'Delivery — read-only public content (recommended for apps)',
                            'management' => 'Management — full read/write access (use with caution)',
                        ])
                        ->default('delivery')
                        ->required()
                        ->helperText('Delivery keys can read content. Management keys can also create and modify it.'),

                    TextInput::make('rate_limit_per_minute')
                        ->label('Rate limit (req / min)')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(10_000)
                        ->nullable()
                        ->placeholder('Default: 1000 delivery / 120 management')
                        ->helperText('Leave blank to use the scope default.'),

                    DateTimePicker::make('expires_at')
                        ->label('Expiry date')
                        ->nullable()
                        ->helperText('Leave blank for a non-expiring key.'),
                ])
                ->action(function (array $data): void {
                    /** @var User $user */
                    $user = auth()->user();

                    // The form validates these; the shapes below only restate
                    // what validation guarantees, in types the service declares.
                    $rateLimit = $data['rate_limit_per_minute'] ?? null;
                    $expiresAt = $data['expires_at'] ?? null;

                    $credentials = app(ApiKeyService::class)->generate([
                        'name' => is_string($data['name'] ?? null) ? $data['name'] : '',
                        'scope' => is_string($data['scope'] ?? null) ? $data['scope'] : 'delivery',
                        'rate_limit_per_minute' => is_numeric($rateLimit) ? (int) $rateLimit : null,
                        'expires_at' => is_string($expiresAt) || $expiresAt instanceof Carbon ? $expiresAt : null,
                    ], $user);

                    $this->generatedKey = $credentials['key'];
                    $this->generatedSecret = $credentials['secret'];

                    Notification::make()
                        ->title('API key generated.')
                        ->body('Copy the secret now — it cannot be shown again.')
                        ->success()
                        ->send();
                }),
        ];
    }
}
