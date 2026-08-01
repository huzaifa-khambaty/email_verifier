<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Single-tenant app (DECISIONS.md "Auth & users"): no public
     * registration, exactly one admin account, seeded from config rather
     * than hardcoded (config, not env() directly — see config/verifier.php
     * for why). Idempotent, and updates the one existing admin in place
     * even if its email is changing, rather than matching on email and
     * risking an orphaned duplicate if that's what actually changed.
     */
    public function run(): void
    {
        $attributes = [
            'name' => config('verifier.admin.name'),
            'email' => config('verifier.admin.email'),
            'password' => Hash::make(config('verifier.admin.password')),
            'email_verified_at' => now(),
        ];

        $admin = User::first();

        if ($admin) {
            $admin->update($attributes);
        } else {
            User::create($attributes);
        }
    }
}
