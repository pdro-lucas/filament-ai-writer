<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Usage\UsageStatistics;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\DatabaseSeeder;

class UsageStatisticsTest extends TestCase
{
  use DatabaseMigrations;

  public function test_statistics_are_current_panel_only_with_exact_subtotals_and_measurement_coverage(): void
  {
    $this->login();
    $this->row('demo', ['actor_id' => 'other-actor', 'cost_usd' => '999999999999.999999999999', 'cost_source' => 'provider', 'input_tokens' => 100]);
    $this->row('demo', ['cost_usd' => '0.000000000001', 'cost_source' => 'provider', 'input_tokens' => 0]);
    $this->row('demo', ['cost_usd' => '0.100000000001', 'cost_source' => 'estimated', 'status' => 'failed']);
    $this->row('demo', ['provider' => 'gemini', 'model' => 'unknown-cost', 'status' => 'pending']);
    $this->row('other-panel', ['provider' => 'PRIVATE-provider', 'model' => 'PRIVATE-model', 'actor_id' => 'PRIVATE-actor', 'cost_usd' => '100.000000000000', 'cost_source' => 'provider']);
    $service = app(UsageStatistics::class);
    $summary = $service->summary(\PdroLucas\FilamentAiWriter\Models\UsageAttempt::query());
    $this->assertSame(4, $summary['attempts']);
    $this->assertSame(2, $summary['delivered']);
    $this->assertSame(1, $summary['failed']);
    $this->assertSame(1, $summary['active']);
    $this->assertSame('1000000000000.000000000000', $summary['provider_cost']);
    $this->assertSame('0.100000000001', $summary['estimated_cost']);
    $this->assertSame(1, $summary['unknown_cost']);
    $this->assertSame(['total' => '100', 'known' => 2, 'unknown' => 2], $summary['input_tokens']);
    $this->assertCount(4, $service->query()->get());
    $this->assertNull($service->optionLabel('model', 'PRIVATE-model'));
    foreach (['provider', 'model', 'actor'] as $filter) {
      $this->assertStringNotContainsString('PRIVATE', json_encode($service->options($filter)));
      $this->assertSame([], $service->options($filter, 'PRIVATE'));
    }
  }

  private function login(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    AiWriterPlugin::get()->authorizeViewStatisticsUsing(fn () => true);
  }

  private function row(string $panel, array $changes = []): int
  {
    return DB::table('filament_ai_writer_usage')->insertGetId(array_replace([
      'operation_id' => (string) \Illuminate\Support\Str::uuid(), 'attempt_number' => 1,
      'panel_id' => $panel, 'actor_type' => 'HostActor', 'actor_id' => 'opaque-id', 'field' => 'content',
      'status' => 'delivered', 'provider' => 'openai', 'model' => 'returned-model',
      'started_at' => '2026-10-21 12:00:00', 'cost_source' => 'unavailable',
    ], $changes));
  }
  public function test_page_filters_and_aggregates_include_all_panel_actors_but_never_other_panels(): void
  {
    $this->login();
    $first = $this->row('demo', ['actor_id' => 'another-actor', 'cost_usd' => '0.100000000001', 'cost_source' => 'provider']);
    $second = $this->row('demo', ['provider' => 'gemini', 'model' => 'gemini-model', 'status' => 'failed']);
    $private = $this->row('private-panel', ['model' => 'PRIVATE-model']);
    $records = \PdroLucas\FilamentAiWriter\Models\UsageAttempt::query();
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class)
      ->assertCanSeeTableRecords($records->clone()->whereIn('id', [$first, $second])->get())
      ->assertCanNotSeeTableRecords($records->clone()->whereKey($private)->get())
      ->assertDontSee('PRIVATE-model')
      ->assertSee('0.100000000001')
      ->filterTable('provider', 'openai')
      ->assertCountTableRecords(1)
      ->assertCanSeeTableRecords($records->clone()->whereKey($first)->get())
      ->assertCanNotSeeTableRecords($records->clone()->whereKey($second)->get());
    $this->assertSame(1, AiWriterPlugin::get()->statistics()->summary($page->instance()->getFilteredTableQuery())['attempts']);
    $page->filterTable('provider', 'PRIVATE-provider')->assertCountTableRecords(0);
    $page->filterTable('provider', null)
      ->filterTable('actor', UsageStatistics::actorKey('HostActor', 'another-actor'))
      ->assertCountTableRecords(1)
      ->assertCanSeeTableRecords($records->clone()->whereKey($first)->get());
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('readSurfaces')]
  public function test_revoked_statistics_permission_blocks_every_data_surface(string $surface): void
  {
    $this->login();
    $service = AiWriterPlugin::get()->statistics();
    $query = $service->query();
    AiWriterPlugin::get()->authorizeViewStatisticsUsing(fn () => false);
    try {
      match ($surface) {
        'query' => $service->query()->get(),
        'summary' => $service->summary($query),
        'options' => $service->options('actor'),
        'label' => $service->optionLabel('model', 'private-model'),
      };
      $this->fail('Revoked statistics access must be rejected.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
      $this->assertSame(403, $exception->getStatusCode());
    }
  }

  public static function readSurfaces(): array
  {
    return array_map(fn (string $surface): array => [$surface], ['query', 'summary', 'options', 'label']);
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('livewireChanges')]
  public function test_mounted_statistics_page_rechecks_permission_guard_and_panel_on_livewire_requests(string $change): void
  {
    $this->login();
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class);
    if ($change === 'guard') {
      auth('workbench')->logout();
      $this->actingAs(User::firstOrFail(), 'web');
    } elseif ($change === 'panel') {
      Filament::setCurrentPanel(\Filament\Panel::make()->id('another')->authGuard('workbench')->plugin(AiWriterPlugin::make()->authorizeViewStatisticsUsing(fn () => true)));
    } else {
      AiWriterPlugin::get()->authorizeViewStatisticsUsing(fn () => false);
    }
    $page->call('refresh')->assertForbidden();
  }

  public static function livewireChanges(): array
  {
    return [['permission'], ['guard'], ['panel']];
  }

  public function test_statistics_default_denial_and_missing_plugin_are_not_granted_by_login(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    foreach ([AiWriterPlugin::make(), null] as $plugin) {
      $panel = \Filament\Panel::make()->id('restricted')->authGuard('workbench');
      if ($plugin !== null) {
        $panel->plugin($plugin);
      }
      Filament::setCurrentPanel($panel);
      $this->assertFalse(UsageStatistics::canAccess());
      \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class)->assertForbidden();
    }
  }

  public function test_period_boundaries_use_local_calendar_days_and_actor_type_is_part_of_identity(): void
  {
    $this->login();
    config(['app.timezone' => 'America/Sao_Paulo']);
    $before = $this->row('demo', ['started_at' => '2026-10-21 02:59:59']);
    $included = $this->row('demo', ['started_at' => '2026-10-21 03:00:00']);
    $this->row('demo', ['started_at' => '2026-10-22 02:59:59']);
    $after = $this->row('demo', ['started_at' => '2026-10-22 03:00:00']);
    $service = AiWriterPlugin::get()->statistics();
    $query = $service->filterPeriod($service->query(), ['from' => '2026-10-21', 'until' => '2026-10-21']);
    $this->assertSame(2, $query->count());
    $this->assertNotContains($before, $query->pluck('id')->all());
    $this->assertNotContains($after, $query->pluck('id')->all());
    $this->assertContains($included, $query->pluck('id')->all());
    $this->row('demo', ['actor_type' => 'AnotherActor']);
    $key = UsageStatistics::actorKey('HostActor', 'opaque-id');
    $this->assertSame(4, $service->filterActor($service->query(), $key)->count());
    $this->assertSame(0, $service->filterActor($service->query(), 'invalid')->count());
    $this->expectException(\Illuminate\Validation\ValidationException::class);
    $service->filterPeriod($service->query(), ['from' => '2026-02-31']);
  }

  public function test_crafted_livewire_page_sizes_cannot_disable_or_expand_bounded_pagination(): void
  {
    $this->login();
    for ($i = 0; $i < 51; $i++) {
      $this->row('demo');
    }
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class);
    foreach (['all', 1000000, 0, -1, '50'] as $value) {
      $page->set('tableRecordsPerPage', $value);
      $records = $page->instance()->getTableRecords();
      $this->assertLessThanOrEqual(50, $records->count());
      $this->assertSame(51, $records->total());
      $this->assertContains($records->perPage(), [10, 25, 50]);
    }
  }

  public function test_chunked_aggregates_preserve_exact_totals_and_option_search_is_bounded(): void
  {
    $this->login();
    for ($i = 0; $i < 501; $i++) {
      $this->row('demo', [
        'actor_id' => 'actor-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
        'cost_usd' => '0.000000000001', 'cost_source' => 'provider',
        'input_tokens' => $i < 2 ? PHP_INT_MAX : null,
      ]);
    }
    $service = AiWriterPlugin::get()->statistics();
    $summary = $service->summary($service->query());
    $this->assertSame(501, $summary['attempts']);
    $this->assertSame('0.000000000501', $summary['provider_cost']);
    $this->assertSame(['total' => '18446744073709551614', 'known' => 2, 'unknown' => 499], $summary['input_tokens']);
    $this->assertCount(50, $service->options('actor'));
    $this->assertCount(1, $service->options('actor', 'actor-0500'));
    $this->assertNull($service->optionLabel('actor', UsageStatistics::actorKey('OtherActor', 'actor-0500')));
  }

}
