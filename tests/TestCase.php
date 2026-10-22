<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
  use WithWorkbench;

  protected function getPackageProviders($app): array
  {
    return [
      \BladeUI\Icons\BladeIconsServiceProvider::class,
      \BladeUI\Heroicons\BladeHeroiconsServiceProvider::class,
      \Livewire\LivewireServiceProvider::class,
      \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
      \Filament\Support\SupportServiceProvider::class,
      \Filament\Actions\ActionsServiceProvider::class,
      \Filament\Forms\FormsServiceProvider::class,
      \Filament\Infolists\InfolistsServiceProvider::class,
      \Filament\Notifications\NotificationsServiceProvider::class,
      \Filament\Schemas\SchemasServiceProvider::class,
      \Filament\Tables\TablesServiceProvider::class,
      \Filament\Widgets\WidgetsServiceProvider::class,
      \Filament\FilamentServiceProvider::class,
      \PdroLucas\FilamentAiWriter\FilamentAiWriterServiceProvider::class,
      ...parent::getPackageProviders($app),
    ];
  }

  protected function defineEnvironment($app): void
  {
    $app['config']->set([
      'app.env' => 'testing',
      'database.default' => 'testing',
      'database.connections.testing' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
      ],
      'session.driver' => 'array',
    ]);
  }
}
