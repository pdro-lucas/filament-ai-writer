<?php

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
  public function run(): void
  {
    User::updateOrCreate(
      ['email' => 'demo@example.test'],
      ['name' => 'Demo Writer', 'password' => Hash::make('password')],
    );
  }
}
