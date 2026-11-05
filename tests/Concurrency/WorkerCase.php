<?php

namespace PdroLucas\FilamentAiWriter\Tests\Concurrency;

use PdroLucas\FilamentAiWriter\Tests\TestCase;

/** Boots the same Testbench application as the suite inside a separate OS process (see worker.php). */
final class WorkerCase extends TestCase
{
  public function boot(): void
  {
    $this->setUp();
  }
}
