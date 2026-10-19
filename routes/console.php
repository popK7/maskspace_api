<?php

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

// Purge des refresh tokens expires/revoques et des codes OTP perimes.
Schedule::command('model:prune')->daily();

Artisan::command('makspace:create-admin {email} {name=Admin}', function (string $email, string $name) {
    $password = $this->secret('Mot de passe (min. 8 caracteres, lettres + chiffres)');

    if (strlen((string) $password) < 8) {
        $this->error('Mot de passe trop court.');

        return 1;
    }

    if (User::where('email', strtolower($email))->exists()) {
        $this->error('Cet e-mail existe deja.');

        return 1;
    }

    (new User)->forceFill([
        'name' => $name,
        'email' => strtolower($email),
        'phone' => '+0 000000',
        'password' => Hash::make($password),
        'role' => UserRole::Admin,
        'status' => UserStatus::Active,
        'email_verified_at' => now(),
    ])->save();

    $this->info("Administrateur {$email} cree.");

    return 0;
})->purpose('Cree un compte administrateur (le role admin n\'est pas auto-inscriptible)');
