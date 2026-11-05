<?php

// One real OS process per call: boots the app, prints READY, waits for the barrier file, then runs one generation.
// Usage (spawned by MultiProcessAdmissionTest): php worker.php
require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Filament\Facades\Filament;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Tests\Concurrency\WorkerCase;
use Workbench\App\Models\User;

function worker_event(string $name): void
{
  $h = fopen(getenv('AI_WRITER_TEST_EVENTS'), 'a');
  flock($h, LOCK_EX);
  fwrite($h, json_encode(['t' => microtime(true), 'ev' => $name]) . "\n");
  fflush($h);
  flock($h, LOCK_UN);
  fclose($h);
}

(new WorkerCase('boot'))->boot();
Filament::setCurrentPanel(Filament::getPanel('demo'));
$plugin = AiWriterPlugin::get();
$plugin->authorizeManage(fn () => true)->scope(fn () => getenv('AI_WRITER_TEST_SCOPE') ?: 'panel:demo');
auth('workbench')->setUser(User::firstOrFail());
app()->instance(AiProvider::class, new class implements AiProvider {
  public function generate(string $systemPrompt, string $userInput): GenerateResult
  {
    worker_event('enter');
    usleep((int) getenv('AI_WRITER_TEST_PROVIDER_MS') * 1000);
    worker_event('exit');
    return new GenerateResult('probe', 'probe-model', 'probe-model', 'Generated text', inputTokens: 1, outputTokens: 1);
  }
});
\Illuminate\Support\Facades\DB::connection()->getPdo();
fwrite(STDOUT, "READY\n");
fflush(STDOUT);
while (!file_exists(getenv('AI_WRITER_TEST_GO'))) {
  usleep(200);
}
try {
  $plugin->generation()->run('prompt', 'input', fn () => null, fn () => null, 'content');
  echo json_encode(['outcome' => 'ok']), "\n";
} catch (GenerationFailure $failure) {
  echo json_encode(['outcome' => $failure->failureCode]), "\n";
} catch (Throwable $error) {
  echo json_encode(['outcome' => 'exception', 'message' => $error->getMessage()]), "\n";
}
