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

  /** SQLite :memory: unless AI_WRITER_TEST_DB_CONNECTION selects mysql or pgsql (the database is wiped by migrate:fresh). */
  protected static function testConnection(): array
  {
    $driver = env('AI_WRITER_TEST_DB_CONNECTION', 'sqlite');
    $database = env('AI_WRITER_TEST_DB_DATABASE', ':memory:');
    return match ($driver) {
      'sqlite' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => true],
      'mysql' => ['driver' => 'mysql', 'host' => env('AI_WRITER_TEST_DB_HOST', '127.0.0.1'), 'port' => env('AI_WRITER_TEST_DB_PORT', 3306), 'database' => $database,
        'username' => env('AI_WRITER_TEST_DB_USERNAME', 'root'), 'password' => env('AI_WRITER_TEST_DB_PASSWORD', ''), 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true],
      'pgsql' => ['driver' => 'pgsql', 'host' => env('AI_WRITER_TEST_DB_HOST', '127.0.0.1'), 'port' => env('AI_WRITER_TEST_DB_PORT', 5432), 'database' => $database,
        'username' => env('AI_WRITER_TEST_DB_USERNAME', 'postgres'), 'password' => env('AI_WRITER_TEST_DB_PASSWORD', ''), 'charset' => 'utf8', 'prefix' => '', 'schema' => 'public', 'sslmode' => 'prefer'],
      default => throw new \InvalidArgumentException("Unsupported test database driver [$driver]."),
    };
  }

  /** Portable failure injection: matching statements throw a QueryException until the returned closure is called. */
  protected function rejectStatements(string $verb, string $table, mixed $binding = null): \Closure
  {
    $active = true;
    \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $sql, array $bindings, \Illuminate\Database\Connection $connection) use (&$active, $verb, $table, $binding): void {
      if ($active && preg_match('/^\s*' . $verb . '\s+(?:into\s+|from\s+)?[`"]?' . preg_quote($table, '/') . '[`"]?[\s(]/i', $sql)
        && ($binding === null || ($binding instanceof \Closure ? $binding($sql, $bindings) : in_array((string) $binding, array_map('strval', array_filter($bindings, 'is_scalar')), true)))) {
        throw new \Illuminate\Database\QueryException($connection->getName(), $sql, $bindings, new \RuntimeException('PRIVATE database detail'));
      }
    });
    return function () use (&$active): void { $active = false; };
  }

  protected function defineEnvironment($app): void
  {
    $app['config']->set([
      'app.env' => 'testing',
      'database.default' => 'testing',
      'database.connections.testing' => static::testConnection(),
      'session.driver' => 'array',
    ]);
  }
}
