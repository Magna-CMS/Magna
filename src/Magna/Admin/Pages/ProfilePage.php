<?php

declare(strict_types=1);

namespace Magna\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;
use Magna\Media\MediaIngestor;
use Magna\Users\User;

/**
 * @property Schema $form
 */
class ProfilePage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationLabel = 'Profile';

    protected static ?string $title = 'My Profile';

    protected string $view = 'magna::admin.profile';

    // Exclude from sidebar nav — only accessible via the user menu.
    protected static bool $shouldRegisterNavigation = false;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $this->form->fill([
            'avatar_path' => $user->avatar_path,
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => '',
            'password' => '',
            'password_confirmation' => '',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Personal information')
                    ->schema([
                        FileUpload::make('avatar_path')
                            ->label('Profile photo')
                            ->avatar()
                            ->imageEditor()
                            ->circleCropper()
                            ->disk('public')
                            ->directory('avatars')
                            ->visibility('public')
                            ->maxSize(2048)
                            // S1-03 companion finding: Filament's ->image() helper accepts
                            // image/svg+xml, and the raw file is written to the public disk
                            // the instant it's selected — before MediaIngestor's sanitizer
                            // ever runs in save() below. Explicitly excluding SVG here closes
                            // that XSS window; raster types are re-encoded by MediaIngestor
                            // once the form is actually saved.
                            ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                            ->helperText('JPG, PNG, or WebP up to 2 MB.'),

                        TextInput::make('name')
                            ->label('Full name')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Email address')
                            ->email()
                            ->required()
                            ->maxLength(255),
                    ]),

                Section::make('Change password')
                    ->description('Leave blank to keep your current password.')
                    ->schema([
                        TextInput::make('current_password')
                            ->label('Current password')
                            ->password()
                            ->nullable()
                            ->requiredWith('password')
                            ->currentPassword()
                            ->helperText('Enter your current password to confirm changes.'),

                        TextInput::make('password')
                            ->label('New password')
                            ->password()
                            ->nullable()
                            ->rule(Password::min(8)->mixedCase()->numbers())
                            ->same('password_confirmation'),

                        TextInput::make('password_confirmation')
                            ->label('Confirm new password')
                            ->password()
                            ->nullable()
                            ->dehydrated(false),
                    ]),
            ]);
    }

    public function save(): void
    {
        /** @var array{avatar_path: ?string, name: string, email: string, current_password: ?string, password: ?string, password_confirmation: ?string} $data */
        $data = $this->form->getState();

        /** @var User $user */
        $user = auth()->user();

        $avatarPath = $data['avatar_path'] ?? null;

        // Ingest a freshly-uploaded avatar into the media library so it appears
        // in /media. Skip if unchanged or already a managed media/ path.
        if (is_string($avatarPath) && $avatarPath !== ''
            && ! str_starts_with($avatarPath, 'media/')
            && $avatarPath !== $user->avatar_path
        ) {
            $absolute = Storage::disk('public')->path($avatarPath);
            if (is_file($absolute)) {
                try {
                    $ingested = app(MediaIngestor::class)->ingest($absolute, basename($avatarPath), 'public');
                    $avatarPath = $ingested->path;
                } catch (\Throwable) {
                    // Keep original path on failure
                }
            }
        }

        $user->avatar_path = $avatarPath;
        $user->name = $data['name'];

        if ($data['email'] !== $user->email) {
            $user->email = $data['email'];
            $user->email_verified_at = null;
        }

        if (filled($data['password'])) {
            $user->password = Hash::make($data['password']);
        }

        $user->save();

        // Re-fill with updated data, clearing password fields.
        $this->form->fill([
            'avatar_path' => $avatarPath,
            'name' => $user->name,
            'email' => $user->email,
            'current_password' => '',
            'password' => '',
            'password_confirmation' => '',
        ]);

        Notification::make()
            ->title('Profile updated.')
            ->success()
            ->send();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('save')
                ->label('Save profile')
                ->action(fn () => $this->save()),
        ];
    }
}
