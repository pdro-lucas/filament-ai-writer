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
    $charts = $service->charts(\PdroLucas\FilamentAiWriter\Models\UsageAttempt::query());
    $this->assertSame([['label' => '2026-10-21', 'delivered' => 2, 'failed' => 1, 'active' => 1]], $charts['time']);
    $this->assertSame([
      ['label' => 'openai', 'delivered' => 2, 'failed' => 1, 'active' => 0],
      ['label' => 'gemini', 'delivered' => 0, 'failed' => 0, 'active' => 1],
    ], $charts['providers']);
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

  protected function tearDown(): void
  {
    \Carbon\CarbonImmutable::setTestNow();
    parent::tearDown();
  }

  private function login(): void
  {
    \Carbon\CarbonImmutable::setTestNow('2026-10-21 12:00:00 UTC');
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    Filament::setCurrentPanel(Filament::getPanel('demo'));
    AiWriterPlugin::get()->authorizeViewStatistics(fn () => true);
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
      ->assertSee('0.100000000001')->assertSee('Partial')
      ->filterTable('status', 'delivered')
      ->assertCountTableRecords(1)
      ->assertCanSeeTableRecords($records->clone()->whereKey($first)->get())
      ->assertCanNotSeeTableRecords($records->clone()->whereKey($second)->get());
    $this->assertSame(1, AiWriterPlugin::get()->statistics()->summary($page->instance()->getFilteredTableQuery())['attempts']);
    $dashboard = AiWriterPlugin::get()->statistics()->dashboard($page->viewData('usageFilters'));
    $this->assertSame(1, $dashboard['attempts']);
    $this->assertSame('0.100000000001', $dashboard['provider_cost']);
    $this->assertSame([['label' => 'openai', 'delivered' => 1, 'failed' => 0, 'active' => 0]], $dashboard['charts']['providers']);
    // Another panel's actor is not an option, so the header filter refuses it instead of applying it.
    $page->callAction('filter', ['actor' => UsageStatistics::actorKey('HostActor', 'PRIVATE-actor')])->assertHasActionErrors(['actor'])->assertCountTableRecords(1);
    $page->unmountAction()->filterTable('status', null)
      ->callAction('filter', ['actor' => UsageStatistics::actorKey('HostActor', 'another-actor')])
      ->assertCountTableRecords(1)
      ->assertCanSeeTableRecords($records->clone()->whereKey($first)->get());
  }

  public function test_shared_period_user_and_result_filters_include_both_in_progress_states(): void
  {
    $this->login();
    config(['app.timezone' => 'America/Sao_Paulo']);
    $this->row('demo', ['started_at' => '2026-10-15 02:59:59']);
    $this->row('demo', ['started_at' => '2026-10-15 03:00:00', 'status' => 'pending']);
    $this->row('demo', ['status' => 'received']);
    $this->row('demo', ['actor_type' => 'DifferentActor', 'status' => 'pending']);
    $this->row('demo', ['status' => 'failed']);
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class)->assertCountTableRecords(4);
    $this->assertSame(['from' => '2026-10-15', 'until' => '2026-10-21'], $page->instance()->periodFilter());
    $page->filterTable('status', 'active')
      ->callAction('filter', ['actor' => UsageStatistics::actorKey('HostActor', 'opaque-id')])->assertCountTableRecords(2);
    $service = AiWriterPlugin::get()->statistics();
    $summary = $service->dashboard($page->viewData('usageFilters'));
    $this->assertSame(2, $summary['attempts']);
    $this->assertSame(2, array_sum(array_column($summary['charts']['time'], 'active')));
    $page->call('setPeriod', 1)->assertCountTableRecords(1);
    $this->assertSame(1, $service->dashboard($page->viewData('usageFilters'))['attempts']);
    $page->call('setPeriod', 30)->assertCountTableRecords(2);
    // The header reflects the new state in the same response as the action, not on the next request.
    // (The first match is the menu button; the menu items repeat the preset names after it.)
    $periodLabel = function ($page): string {
      preg_match('/Today|Last \d+ days|[A-Z][a-z]{2} \d+, \d{4} – [A-Z][a-z]{2} \d+, \d{4}/', $page->html(), $match);

      return $match[0];
    };
    $this->assertSame('Today', $periodLabel($page->callAction('period1')));
    $this->assertSame('Last 30 days', $periodLabel($page->unmountAction()->callAction('period30')->assertCountTableRecords(2)));
    // Match the JavaScript picker's internal state, not just preset date-only input.
    $page->set('filters', ['from' => '2026-10-15 00:00:00', 'until' => '2026-10-15 00:00:00'])->assertCountTableRecords(1);
    $this->assertSame(1, $service->dashboard($page->viewData('usageFilters'))['attempts']);
    $page->callAction('periodCustom', ['from' => '2026-10-21', 'until' => '2026-10-15'])->assertHasActionErrors(['until']);
    $page->unmountAction()->callAction('periodCustom', ['from' => '2026-10-15', 'until' => '2026-10-15'])->assertHasNoActionErrors()->assertCountTableRecords(1);
    $this->assertSame('Oct 15, 2026 – Oct 15, 2026', $periodLabel($page));
    $page->call('resetUsageFilters')->assertCountTableRecords(4);
    $page->set('tableFilters.provider.value', 'PRIVATE-provider')
      ->set('tableFilters.model.value', 'PRIVATE-model')->assertCountTableRecords(4)
      ->assertViewHas('usageFilters', fn (array $filters): bool => AiWriterPlugin::get()->statistics()->dashboard($filters)['attempts'] === 4);
    $page->assertViewHas('usageFilters', fn (array $filters): bool => !isset($filters['provider'], $filters['model']));
    $this->assertSame(['from' => '2026-10-15', 'until' => '2026-10-21'], $page->instance()->periodFilter());
  }

  public function test_previous_period_has_the_same_length_and_changes_handle_missing_or_zero_bases(): void
  {
    $service = app(UsageStatistics::class);
    $this->assertSame(['from' => '2026-10-08', 'until' => '2026-10-14'], $service->previousPeriod(['from' => '2026-10-15', 'until' => '2026-10-21']));
    $this->assertSame(['from' => '2026-10-20', 'until' => '2026-10-20'], $service->previousPeriod(['from' => '2026-10-21', 'until' => '2026-10-21']));
    $this->assertSame(['from' => '2026-02-26', 'until' => '2026-02-28'], $service->previousPeriod(['from' => '2026-03-01', 'until' => '2026-03-03']));
    foreach ([[], ['from' => '2026-10-15'], ['until' => '2026-10-21'], ['from' => '2026-10-21', 'until' => '2026-10-15'], ['from' => '2026-02-31', 'until' => '2026-03-03'], ['from' => 'x', 'until' => 'y']] as $open) {
      $this->assertNull($service->previousPeriod($open));
    }
    $this->assertEqualsWithDelta(36.5, UsageStatistics::change(1365, 1000), 0.001);
    $this->assertEqualsWithDelta(-50.0, UsageStatistics::change(5, 10), 0.001);
    $this->assertSame(0.0, UsageStatistics::change(0, 0));
    $this->assertNull(UsageStatistics::change(5, 0));
    $this->assertNull(UsageStatistics::change(5, null));
    $this->assertNull(UsageStatistics::change(null, 5));
  }

  public function test_overview_compares_against_the_previous_period_and_says_so_when_there_is_none(): void
  {
    $this->login();
    $this->row('demo', ['started_at' => '2026-10-20 12:00:00']);
    $this->row('demo', ['started_at' => '2026-10-21 12:00:00']);
    $this->row('demo', ['started_at' => '2026-10-21 13:00:00']);
    $this->row('demo', ['started_at' => '2026-10-10 12:00:00']);
    $this->row('private-panel', ['started_at' => '2026-10-10 12:00:00']);
    $week = ['period' => ['from' => '2026-10-15', 'until' => '2026-10-21']];
    // 3 requests against 1 in the seven days before (other panels never count): +200%.
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Widgets\UsageOverviewStats::class, ['panelId' => 'demo', 'sharedFilters' => $week])
      ->assertSee('200.0% vs. previous period');
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Widgets\UsageOverviewStats::class, ['panelId' => 'demo', 'sharedFilters' => ['period' => ['until' => '2026-10-21']]])
      ->assertSee('No data from the previous period')->assertDontSee('vs. previous period');
  }

  public function test_rankings_explore_and_neighbours_stay_inside_the_panel_and_current_filters(): void
  {
    $this->login();
    $first = $this->row('demo', ['actor_id' => 'ana', 'field' => 'title', 'started_at' => '2026-10-21 10:00:00']);
    $second = $this->row('demo', ['actor_id' => 'ana', 'field' => 'body', 'started_at' => '2026-10-21 11:00:00']);
    $third = $this->row('demo', ['actor_id' => 'bia', 'field' => 'body', 'started_at' => '2026-10-21 12:00:00']);
    $old = $this->row('demo', ['actor_id' => 'bia', 'field' => 'old-field', 'started_at' => '2026-09-01 12:00:00']);
    $private = $this->row('private-panel', ['actor_id' => 'PRIVATE-actor', 'field' => 'PRIVATE-field', 'started_at' => '2026-10-21 12:00:00']);
    $service = app(UsageStatistics::class);
    $week = ['period' => ['from' => '2026-10-15', 'until' => '2026-10-21']];

    $this->assertSame([2, 1], array_column($service->top($week, 'actor'), 'requests'));
    $this->assertSame(['body' => 2, 'title' => 1], array_column($service->top($week, 'field'), 'requests', 'field'));
    $this->assertStringNotContainsString('PRIVATE', json_encode([$service->top([], 'actor'), $service->top([], 'field')]));

    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class)
      ->assertCountTableRecords(3)
      ->call('explore', 'actor', UsageStatistics::actorKey('HostActor', 'ana'))->assertCountTableRecords(2)
      ->call('explore', 'field', 'title')->assertCountTableRecords(1)
      ->call('explore', 'field', 'PRIVATE-field')->assertCountTableRecords(0);
    // The header field filter is panel-scoped like the user one, and a visible clear action undoes both but keeps the period.
    $page->call('explore', 'field', 'title')->assertActionVisible('clearFilters')->assertSee('Field: title');
    $epochWhileFiltered = $page->get('widgetEpoch');
    $page->callAction('filter', ['field' => 'PRIVATE-field'])->assertHasActionErrors(['field'])->assertCountTableRecords(1);
    $page->unmountAction()->callAction('clearFilters')->assertActionHidden('clearFilters')->assertCountTableRecords(3);
    // The embedded widgets follow the cleared state too: a widget key seen before must not bring back its stale filters.
    $page->assertDontSee('Field: title');
    // Regression: the cleared filters equal the initial ones, but the widgets still get a key never used before
    // (a repeated key made Livewire keep the stale, still-filtered children in the browser).
    $this->assertGreaterThan($epochWhileFiltered, $page->get('widgetEpoch'));
    $this->assertSame(['from' => '2026-10-15', 'until' => '2026-10-21'], $page->instance()->periodFilter());
    // A tampered, non-text user filter selects nothing instead of crashing.
    $page->set('filters', ['actor' => ['x']])->assertOk()->assertCountTableRecords(0);
    $page->call('explore', 'provider', 'openai')->assertStatus(422);

    // Neighbours come from the filtered, panel-scoped table: the old and private rows are never offered.
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class);
    $page->callAction([\Filament\Actions\Testing\TestAction::make('details')->table($second), 'previous'])->assertSet('mountedActions.0.context.recordKey', (string) $third);
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class)->assertActionDisabled([\Filament\Actions\Testing\TestAction::make('details')->table($third), 'previous']);
    $this->assertGreaterThan($third, $old);
    $this->assertGreaterThan($old, $private);
  }

  public function test_period_picker_state_with_a_time_of_day_renders_and_filters_by_date(): void
  {
    $this->login();
    $this->row('demo', ['started_at' => '2026-10-14 23:59:59']);
    $this->row('demo', ['started_at' => '2026-10-15 00:00:00']);
    $this->row('demo', ['started_at' => '2026-10-15 23:59:59']);
    $this->row('demo', ['started_at' => '2026-10-16 00:00:00']);
    // Filament 5.6 keeps the current time on the date-only pickers' default state (#25).
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class)
      ->set('filters', ['from' => '2026-10-15 09:09:37', 'until' => '2026-10-15 09:09:37'])
      ->assertOk()->assertSee('AI Writer usage')
      ->assertCountTableRecords(2)
      ->assertViewHas('usageFilters', fn (array $filters): bool => $filters['period'] === ['from' => '2026-10-15', 'until' => '2026-10-15'])
      ->assertViewHas('usageFilters', fn (array $filters): bool => AiWriterPlugin::get()->statistics()->dashboard($filters)['attempts'] === 2);
  }

  public function test_page_period_boundary_rejects_malformed_picker_state(): void
  {
    $this->login();
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class)->instance();
    foreach (['2026-02-31', '2026-02-31 00:00:00', '2026-02-31 09:09:37', '2026-10-15 24:00:00', 'tomorrow', 'garbage', '2026-10-15T00:00:00Z', ['2026-10-15']] as $invalid) {
      $page->filters = ['from' => $invalid, 'until' => '2026-10-21'];
      try {
        $page->getFilteredTableQuery()->count();
        $this->fail('Malformed date state must reach strict period validation.');
      } catch (\Illuminate\Validation\ValidationException $exception) {
        $this->assertArrayHasKey('period', $exception->errors());
      }
    }
  }

  public function test_statistics_follow_the_host_locale_date_format_timezone_and_currency(): void
  {
    $this->login();
    $id = $this->row('demo', ['started_at' => '2026-10-21 12:00:00', 'cost_usd' => '0.100000000001', 'cost_source' => 'provider']);
    // The host owns locale, date format, timezone (also per user) and currency; the plugin fixes none of them.
    $this->app->setLocale('pt_BR');
    \Filament\Support\Facades\FilamentTimezone::set('America/Sao_Paulo');
    \Filament\Tables\Table::configureUsing(fn (\Filament\Tables\Table $table) => $table->defaultDateTimeDisplayFormat('d/m/Y H:i'));
    \Filament\Schemas\Schema::configureUsing(fn (\Filament\Schemas\Schema $schema) => $schema->defaultDateTimeDisplayFormat('d/m/Y H:i')->defaultDateDisplayFormat('d/m/Y'));
    config(['filament-ai-writer.currency' => ['code' => 'BRL', 'symbol' => 'R$ '], 'app.timezone' => 'UTC']);
    // 01:00 UTC is still the previous evening for this user (22:00 on the 20th): periods and buckets use the user's day.
    $this->row('demo', ['started_at' => '2026-10-21 01:00:00']);

    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class)
      ->assertSee('21/10/2026 09:00')->assertSee('20/10/2026 22:00')->assertSee('Custo · BRL')->assertDontSee('USD');
    $page->call('setPeriod', 1)->assertCountTableRecords(1);
    $this->assertSame(['from' => '2026-10-21', 'until' => '2026-10-21'], $page->instance()->periodFilter());
    $this->assertSame(1, AiWriterPlugin::get()->statistics()->dashboard($page->viewData('usageFilters'))['attempts']);
    $page->call('setPeriod', 7);
    $buckets = AiWriterPlugin::get()->statistics()->dashboard($page->viewData('usageFilters'))['charts']['time'];
    $this->assertSame(['2026-10-20', '2026-10-21'], array_column($buckets, 'label'));
    // The request drawer writes its date as text, with the same host format and timezone.
    $find = function (array $components) use (&$find): ?\Filament\Infolists\Components\TextEntry {
      foreach ($components as $component) {
        if ($component instanceof \Filament\Infolists\Components\TextEntry && $component->getName() === 'when') {
          return $component;
        }
        if (($found = $find($component->getDefaultChildComponents())) !== null) {
          return $found;
        }
      }

      return null;
    };
    $started = $find(\PdroLucas\FilamentAiWriter\Schemas\UsageAttemptDetails::components(\PdroLucas\FilamentAiWriter\Models\UsageAttempt::query()->findOrFail($id)));
    $this->assertSame('21/10/2026 09:00 · America/Sao_Paulo', $started->getState());
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Widgets\UsageOverviewStats::class)
      ->assertSee('Visão geral do uso')->assertSee('R$ 0.100000000001')->assertSee('Solicitações')->assertSee('Custos · BRL')
      ->assertDontSee('Usage overview')->assertDontSee('Requests');
  }

  public function test_metric_widgets_register_on_any_dashboard_and_follow_the_statistics_permission(): void
  {
    $this->login();
    $this->row('demo', ['cost_usd' => '0.100000000001', 'cost_source' => 'provider', 'started_at' => \Carbon\CarbonImmutable::now('UTC')->toDateTimeString()]);
    $this->row('private-panel', ['started_at' => \Carbon\CarbonImmutable::now('UTC')->toDateTimeString()]);
    $this->assertTrue(\PdroLucas\FilamentAiWriter\Widgets\UsageOverviewStats::canView());
    // No panel or filters passed, as on a host dashboard: the current panel and the last seven days apply.
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Widgets\UsageOverviewStats::class)
      ->assertSee('Requests')->assertSee('$0.100000000001', false)->assertSee('1 success');
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Widgets\UsageOverviewStats::class, ['panelId' => 'private-panel'])->assertForbidden();
    AiWriterPlugin::get()->authorizeViewStatistics(fn () => false);
    $this->assertFalse(\PdroLucas\FilamentAiWriter\Widgets\UsageOverviewStats::canView());
    $this->assertFalse(\PdroLucas\FilamentAiWriter\Widgets\UsageCountChart::canView());
    \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Widgets\UsageOverviewStats::class)->assertForbidden();
  }

  public function test_views_stay_native_and_icons_resolve_through_filament_aliases(): void
  {
    foreach (\Illuminate\Support\Facades\File::allFiles(__DIR__ . '/../resources/views') as $view) {
      $this->assertDoesNotMatchRegularExpression('/<style|\.fi-|font-size|#[0-9a-f]{3,6}\b/i', $view->getContents(), $view->getRelativePathname());
    }
    $this->assertSame('heroicon-o-sparkles', \PdroLucas\FilamentAiWriter\Support\Icons::resolve(\PdroLucas\FilamentAiWriter\Support\Icons::ACTION));
    \Filament\Support\Facades\FilamentIcon::register([\PdroLucas\FilamentAiWriter\Support\Icons::ACTION => 'host-icon']);
    $this->assertSame('host-icon', \PdroLucas\FilamentAiWriter\Actions\AiWriterAction::make()->getIcon());
  }

  public function test_chart_options_keep_no_motion_compatible_with_filament_initialization(): void
  {
    $this->login();
    $this->row('demo', ['started_at' => \Carbon\CarbonImmutable::now('UTC')->toDateTimeString()]);
    $chart = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Widgets\UsageCountChart::class, ['panelId' => 'demo']);
    // The screen-reader table carries the same buckets as the bars.
    $chart->assertSee('Request counts by date · 1-day buckets · UTC')->assertSeeHtml('<th scope="row">' . \Carbon\CarbonImmutable::now(config('app.timezone'))->format('M j, Y') . '</th><td>1</td>');
    preg_match("/options:\\s*JSON\\.parse\\('([^']+)'\\)/", html_entity_decode($chart->html(), ENT_QUOTES), $matches);
    $this->assertNotEmpty($matches, 'The widget must send options to the Filament chart component.');
    $options = json_decode(json_decode('"' . $matches[1] . '"', true, 512, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    // Filament initializes options.animation.duration before creating the Chart.js instance.
    // A boolean false is valid in Chart.js alone, but throws in that initialization path.
    $this->assertIsArray($options['animation']);
    $this->assertSame(0, $options['animation']['duration']);
  }

  public function test_request_drawer_uses_panel_scoped_ids_and_preserves_historic_model_and_exact_cost(): void
  {
    $this->login();
    $id = $this->row('demo', ['actor_id' => 'someone-else', 'model' => 'historic-actual-model', 'cost_usd' => '0.000000000001', 'cost_source' => 'estimated']);
    $private = $this->row('other-panel', ['model' => 'PRIVATE-model']);
    config(['filament-ai-writer.model' => 'new-configured-model']);
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class)
      ->mountTableAction('details', $id)->assertActionMounted(\Filament\Actions\Testing\TestAction::make('details')->table($id));
    // Livewire 4 returns the native drawer as an action-modals partial; v3 returns full HTML.
    $detail = $page->html() . json_encode($page->effects['partials'] ?? []);
    foreach (['historic-actual-model', '0.000000000001', 'Estimated', 'someone-else'] as $value) {
      $this->assertStringContainsString($value, $detail);
    }
    $this->assertStringNotContainsString('PRIVATE-model', $detail);
    $page->unmountTableAction()->mountTableAction('details', $private)
      ->assertActionNotMounted()->assertDontSee('PRIVATE-model');
    $page->mountTableAction('details', 'HostActor:someone-else')->assertActionNotMounted();
    $page->mountTableAction('details', $id)->assertActionMounted(\Filament\Actions\Testing\TestAction::make('details')->table($id));
    AiWriterPlugin::get()->authorizeViewStatistics(fn () => false);
    $page->call('$refresh')->assertForbidden();
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('readSurfaces')]
  public function test_revoked_statistics_permission_blocks_every_data_surface(string $surface): void
  {
    $this->login();
    $service = AiWriterPlugin::get()->statistics();
    $query = $service->query();
    $service->dashboard([]); // Cached data must not bypass a subsequent permission check.
    AiWriterPlugin::get()->authorizeViewStatistics(fn () => false);
    try {
      match ($surface) {
        'query' => $service->query()->get(),
        'summary' => $service->summary($query),
        'charts' => $service->charts($query),
        'dashboard' => $service->dashboard([]),
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
    return array_map(fn (string $surface): array => [$surface], ['query', 'summary', 'charts', 'dashboard', 'options', 'label']);
  }

  #[\PHPUnit\Framework\Attributes\DataProvider('livewireChanges')]
  public function test_mounted_statistics_page_rechecks_permission_guard_and_panel_on_livewire_requests(string $change): void
  {
    $this->login();
    $id = $this->row('demo');
    $page = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::class)->mountTableAction('details', $id);
    $chart = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Widgets\UsageCountChart::class, ['panelId' => 'demo']);
    if ($change === 'guard') {
      auth('workbench')->logout();
      $this->actingAs(User::firstOrFail(), 'web');
    } elseif ($change === 'panel-access') {
      $actor = \Mockery::mock(User::class)->makePartial();
      $actor->setRawAttributes(User::firstOrFail()->getAttributes());
      $actor->shouldReceive('canAccessPanel')->andReturn(false);
      $this->actingAs($actor, 'workbench');
    } elseif ($change === 'panel') {
      Filament::setCurrentPanel(\Filament\Panel::make()->id('another')->authGuard('workbench')->plugin(AiWriterPlugin::make()->usageTracking()->authorizeViewStatistics(fn () => true)));
    } else {
      AiWriterPlugin::get()->authorizeViewStatistics(fn () => false);
    }
    $page->call('refresh')->assertForbidden();
    $chart->call('updateChartData')->assertForbidden();
  }

  public static function livewireChanges(): array
  {
    return [['permission'], ['guard'], ['panel'], ['panel-access']];
  }

  public function test_statistics_default_denial_and_missing_plugin_are_not_granted_by_login(): void
  {
    $this->seed(DatabaseSeeder::class);
    $this->actingAs(User::firstOrFail(), 'workbench');
    foreach ([AiWriterPlugin::make()->usageTracking(), null] as $plugin) {
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
    $this->assertSame([['label' => '2026-10-21', 'delivered' => 2, 'failed' => 0, 'active' => 0]], $service->charts($query)['time']);
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

  public function test_chart_buckets_and_provider_remainder_are_bounded_without_losing_filtered_attempts(): void
  {
    $this->login();
    $this->row('demo', ['started_at' => '2020-01-01 00:00:00', 'provider' => null, 'status' => 'received']);
    for ($i = 0; $i < 12; $i++) {
      $this->row('demo', ['provider' => 'provider-' . $i, 'status' => 'failed']);
    }
    $this->row('private-panel', ['provider' => 'PRIVATE-provider']);
    $service = AiWriterPlugin::get()->statistics();
    $charts = $service->charts($service->query());
    $this->assertLessThanOrEqual(60, count($charts['time']));
    $this->assertCount(12, $charts['providers']);
    foreach (['time', 'providers'] as $dimension) {
      $this->assertSame(12, array_sum(array_column($charts[$dimension], 'failed')));
      $this->assertSame(1, array_sum(array_column($charts[$dimension], 'active')));
      $this->assertSame(0, array_sum(array_column($charts[$dimension], 'delivered')));
    }
    $this->assertSame('2020-01-01', substr($charts['time'][0]['label'], 0, 10));
    $this->assertStringEndsWith('2026-10-21', $charts['time'][array_key_last($charts['time'])]['label']);
    $this->assertSame(2, collect($charts['providers'])->firstWhere('label', 'Other providers (outside top 10)')['failed']);
    $this->assertSame(1, collect($charts['providers'])->firstWhere('label', 'Unknown provider')['active']);
    $query = $service->filteredQuery(['provider' => ['value' => 'provider-0'], 'status' => ['value' => 'failed']]);
    $this->assertSame([['label' => '2026-10-21', 'delivered' => 0, 'failed' => 1, 'active' => 0]], $service->charts($query)['time']);
    $this->assertSame([], $service->charts($service->filteredQuery(['provider' => ['value' => 'PRIVATE-provider']]))['time']);
  }

  public function test_chart_prepass_handles_a_new_match_inserted_after_an_empty_range_read(): void
  {
    $this->login();
    $inserted = false;
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$inserted): void {
      if (!$inserted && str_contains(strtolower($query->sql), 'min(started_at)')) {
        $inserted = true;
        $this->row('demo', ['started_at' => '2026-10-21 12:00:00', 'provider' => 'openai', 'status' => 'delivered']);
      }
    });

    $charts = AiWriterPlugin::get()->statistics()->charts(AiWriterPlugin::get()->statistics()->query());

    $this->assertTrue($inserted, 'The matching row must be inserted after MIN/MAX and before the count scan.');
    $this->assertSame([['label' => '2026-10-21', 'delivered' => 1, 'failed' => 0, 'active' => 0]], $charts['time']);
    $this->assertSame([['label' => 'openai', 'delivered' => 1, 'failed' => 0, 'active' => 0]], $charts['providers']);
  }

  public function test_chart_prepass_initializes_buckets_for_rows_added_after_a_nonempty_range_read(): void
  {
    $this->login();
    $this->row('demo', ['started_at' => '2026-10-20 12:00:00', 'provider' => 'openai', 'status' => 'delivered']);
    $inserted = false;
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$inserted): void {
      if (!$inserted && str_contains(strtolower($query->sql), 'min(started_at)')) {
        $inserted = true;
        $this->row('demo', ['started_at' => '2026-10-21 12:00:00', 'provider' => 'gemini', 'status' => 'failed']);
      }
    });

    $charts = AiWriterPlugin::get()->statistics()->charts(AiWriterPlugin::get()->statistics()->query());

    $this->assertTrue($inserted);
    $this->assertSame([
      ['label' => '2026-10-20', 'delivered' => 1, 'failed' => 0, 'active' => 0],
      ['label' => '2026-10-21', 'delivered' => 0, 'failed' => 1, 'active' => 0],
    ], $charts['time']);
    $this->assertSame(1, array_sum(array_column($charts['providers'], 'failed')));
  }

  public function test_chart_rebins_a_late_range_extension_without_losing_counts_or_exceeding_sixty_buckets(): void
  {
    $this->login();
    $this->row('demo', ['started_at' => '2026-10-20 12:00:00', 'provider' => 'openai', 'status' => 'delivered']);
    $this->row('demo', ['started_at' => '2026-10-21 12:00:00', 'provider' => 'gemini', 'status' => 'failed']);
    $inserted = false;
    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $query) use (&$inserted): void {
      if (!$inserted && str_contains(strtolower($query->sql), 'min(started_at)')) {
        $inserted = true;
        $this->row('demo', ['started_at' => '2035-01-01 12:00:00', 'provider' => 'anthropic', 'status' => 'received']);
      }
    });

    $charts = AiWriterPlugin::get()->statistics()->charts(AiWriterPlugin::get()->statistics()->query());

    $this->assertTrue($inserted);
    $this->assertLessThanOrEqual(60, count($charts['time']));
    $this->assertSame(1, array_sum(array_column($charts['time'], 'delivered')));
    $this->assertSame(1, array_sum(array_column($charts['time'], 'failed')));
    $this->assertSame(1, array_sum(array_column($charts['time'], 'active')));
    $this->assertSame('2026-10-20', substr($charts['time'][0]['label'], 0, 10));
    $this->assertSame('2035-01-01', substr($charts['time'][array_key_last($charts['time'])]['label'], -10));
    $this->assertSame(3, array_sum(array_map(fn (array $row): int => $row['delivered'] + $row['failed'] + $row['active'], $charts['providers'])));
  }

  public function test_chart_context_and_filters_cannot_be_replaced_by_livewire_clients(): void
  {
    $this->login();
    foreach (['panelId' => 'another-panel', 'sharedFilters' => ['provider' => ['value' => 'PRIVATE']]] as $property => $value) {
      $chart = \Livewire\Livewire::test(\PdroLucas\FilamentAiWriter\Widgets\UsageCountChart::class, ['panelId' => 'demo']);
      try {
        $chart->set($property, $value);
        $this->fail('Chart context must remain server-controlled.');
      } catch (\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException) {
        $this->addToAssertionCount(1);
      }
    }
  }

}
