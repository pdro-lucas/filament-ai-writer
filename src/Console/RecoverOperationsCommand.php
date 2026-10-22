<?php

namespace PdroLucas\FilamentAiWriter\Console;

use Illuminate\Console\Command;
use PdroLucas\FilamentAiWriter\Credits\Credits;
use Throwable;

class RecoverOperationsCommand extends Command
{
  protected $signature = 'ai-writer:recover-operations';
  protected $description = 'Release expired AI operation reservations and permanently fence late delivery';

  public function handle(Credits $credits): int
  {
    try {
      $count = $credits->recoverExpiredOperations();
      $this->info("Recovered {$count} expired operation(s). Late results cannot be delivered; remote costs may still exist.");
      return self::SUCCESS;
    } catch (Throwable) {
      $this->error('Recovery incomplete. Check plugin storage; safe releases already committed remain effective.');
      return self::FAILURE;
    }
  }
}
