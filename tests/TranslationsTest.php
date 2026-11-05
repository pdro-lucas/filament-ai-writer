<?php

namespace PdroLucas\FilamentAiWriter\Tests;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;

class TranslationsTest extends TestCase
{
  public function test_every_english_key_has_a_portuguese_translation_and_none_is_left_in_english(): void
  {
    $dir = __DIR__ . '/../resources/lang';
    $this->assertSame(array_map('basename', glob("$dir/en/*.php")), array_map('basename', glob("$dir/pt_BR/*.php")));
    foreach (glob("$dir/en/*.php") as $file) {
      $english = Arr::dot(require $file);
      $portuguese = Arr::dot(require "$dir/pt_BR/" . basename($file));
      $this->assertSame(array_keys($english), array_keys($portuguese), basename($file));
      foreach ($english as $key => $text) {
        // Placeholders must survive translation, or a message loses its data.
        preg_match_all('/:[a-z_]+/', $text, $expected);
        preg_match_all('/:[a-z_]+/', $portuguese[$key], $actual);
        $this->assertEqualsCanonicalizing($expected[0], $actual[0], basename($file) . ' ' . $key);
      }
    }
    // Every message code a failure can carry is translated, so no technical or actionable text is missing.
    foreach (array_keys(GenerationFailure::MESSAGES) as $code) {
      $this->assertArrayHasKey("messages.$code", Arr::dot(require "$dir/pt_BR/failures.php"));
    }
  }

  public function test_a_translation_published_by_the_host_wins_over_the_package(): void
  {
    $this->app->setLocale('pt_BR');
    $this->assertSame('Seus créditos de IA acabaram. Fale com o administrador.', __('filament-ai-writer::failures.messages.credits'));

    $path = $this->app->langPath('vendor/filament-ai-writer/pt_BR');
    File::ensureDirectoryExists($path);
    File::put("$path/failures.php", "<?php return ['messages' => ['credits' => 'Acabou o saldo da sua equipe.']];");
    try {
      $this->app['translator']->setLoaded([]);
      $this->assertSame('Acabou o saldo da sua equipe.', (new GenerationFailure('credits'))->detail());
      // Keys the host did not publish still come from the package.
      $this->assertSame('Seu acesso à IA está bloqueado. Fale com o administrador.', (new GenerationFailure('blocked'))->detail());
    } finally {
      File::deleteDirectory($this->app->langPath('vendor/filament-ai-writer'));
    }
  }
}
