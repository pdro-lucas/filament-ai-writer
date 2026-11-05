<?php

namespace Workbench\App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
  protected $guarded = [];

  protected $hidden = ['password', 'remember_token'];

  public function canAccessPanel(Panel $panel): bool
  {
    return $panel->getId() === 'demo';
  }
}
