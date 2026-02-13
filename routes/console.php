<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('admin:create', function () {
    $name = (string) $this->ask('Admin name', 'Admin');
    $email = (string) $this->ask('Admin email');
    $password = (string) $this->secret('Admin password (min 8 chars)');

    if ($email === '' || $password === '') {
        $this->error('Email/password required');
        return 1;
    }

    if (strlen($password) < 8) {
        $this->error('Password too short');
        return 1;
    }

    // Ensure role exists (guard api)
    $guard = config('auth.defaults.guard', 'api');
    $role = Role::query()->firstOrCreate(
        ['name' => 'admin', 'guard_name' => $guard],
        ['name' => 'admin', 'guard_name' => $guard]
    );

    $user = User::query()->firstOrNew(['email' => $email]);
    $user->name = $name;
    $user->password = Hash::make($password);

    // Admin لا يحتاج currency
    if (!$user->exists) {
        $user->save();
    } else {
        $user->save();
    }

    if (method_exists($user, 'assignRole')) {
        $user->assignRole($role);
    } else {
        // fallback legacy
        $user->role = 'admin';
        $user->save();
    }

    $this->info("Admin created/updated: {$user->email}");
    return 0;
})->purpose('Create admin user and assign admin role');
