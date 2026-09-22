<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Magna\Users\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Filament's bell counts unread rows with where('data->format', 'filament')
// (filament/notifications DatabaseNotifications::getUnreadNotificationsQuery).
// On PostgreSQL that compiles to the ->> operator, which is defined for
// json/jsonb and not for text — so while notifications.data was a text column,
// every admin panel request on a fresh PostgreSQL install died with
// "SQLSTATE[42883] ... operator does not exist: text ->> unknown". MySQL
// tolerated the text column, so the suite stayed green until the first
// PostgreSQL deployment.
//
// SQLite maps json onto text and would run this query either way, so on the
// default test driver these assertions are cheap; they earn their keep on the
// MySQL and PostgreSQL CI legs, where a text column fails them outright.

function bellNotificationRow(User $user, string $format): void
{
    DB::table('notifications')->insert([
        'id' => (string) Str::uuid(),
        'type' => 'Filament\\Notifications\\DatabaseNotification',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(['format' => $format, 'body' => 'Backup finished.']),
        'read_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('counts unread bell notifications through a JSON path on data', function (): void {
    $user = User::factory()->create();

    bellNotificationRow($user, 'filament');
    bellNotificationRow($user, 'filament');
    bellNotificationRow($user, 'something-else');

    $unread = $user->notifications()
        ->where('data->format', 'filament')
        ->whereNull('read_at')
        ->count();

    expect($unread)->toBe(2);
});
