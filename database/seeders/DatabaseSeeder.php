<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Idempotent demo seed. firstOrCreate means re-running the
     * seeder (the entrypoint runs it on every container start)
     * never duplicates the demo user.
     *
     * Demo-only credentials for a throwaway seeded database.
     * Change or delete this user before real use.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'demo@example.com'],
            [
                'name' => 'Demo User',
                'password' => Hash::make('Demo1234!'),
                'email_verified_at' => now(),
            ]
        );
    }
}
