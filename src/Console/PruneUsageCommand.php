<?php

namespace PdroLucas\FilamentAiWriter\Console;

use Illuminate\Console\Command;
use PdroLucas\FilamentAiWriter\Usage\UsagePruner;

class PruneUsageCommand extends Command
{
  protected $signature = 'ai-writer:prune-usage {--days= : Override configured retention days} {--dry-run : Count eligible attempts without deleting}';
  protected $description = 'Prune old terminal AI Writer attempts while protecting unresolved attempts and the current local calendar month';

  public function handle(UsagePruner $pruner): int
  {
    $result = $pruner->run(
      $this->option('days') ?? config('filament-ai-writer.retention.days'),
      config('filament-ai-writer.retention.batch_size', 500),
      (bool) $this->option('dry-run'),
    );
    if ($result['status'] === 'disabled') {
      $this->info('Usage cleanup is disabled. Set retention.days or explicitly pass --days. No rows deleted.');
      return self::SUCCESS;
    }
    if ($result['status'] === 'invalid') {
      $this->error('Invalid retention configuration. Days must be a positive integer; batch_size must be an integer from 1 to 1000. No rows deleted.');
      return self::FAILURE;
    }
    if ($result['status'] === 'failed') {
      $this->error("Cleanup stopped after deleting {$result['deleted']} attempt(s). Remaining rows were not confirmed; check database/table configuration.");
      return self::FAILURE;
    }
    $this->line("Started and completed strictly before {$result['cutoff']}; unresolved attempts and the current app-calendar month are protected.");
    if ($result['status'] === 'dry-run') {
      $this->info("{$result['eligible']} eligible attempt(s) at this read. Dry run: no rows deleted.");
    } else {
      $this->info("Deleted {$result['deleted']} eligible attempt(s) in {$result['batches']} batch(es). Deleted records no longer contribute to history or totals.");
    }
    return self::SUCCESS;
  }
}
