<?php

namespace PdroLucas\FilamentAiWriter\Tests\Fixtures;

use Filament\Panel;
use Workbench\App\Models\User;

/** The workbench user, allowed into every panel a test builds. */
class AnyPanelUser extends User
{
  protected $table = 'users';

  public function canAccessPanel(Panel $panel): bool
  {
    return true;
  }
}
