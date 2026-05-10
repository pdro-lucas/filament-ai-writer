<?php
namespace PdroLucas\FilamentAiWriter\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Colors\Color;
use Illuminate\Support\Str;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;
use PdroLucas\FilamentAiWriter\Events\AiTextGenerated;

class AiWriterAction extends Action
{
  protected string $targetField = "";
  protected string $aiPrompt = "";
  protected array $contextFields = [];
  protected bool $silent = false;
  protected bool $expectArray = false;
  protected bool $normalizeArrayCase = false;
  protected array $allowedValues = [];
  protected array $valueMap = [];

  /** @var callable|null */
  protected $beforeGenerateCallback = null;

  /** @var callable|null */
  protected static $globalBeforeGenerateCallback = null;

  public static function make(?string $name = null): static
  {
    return parent::make($name ?? "ai_writer")
      ->label("")
      ->icon("heroicon-o-sparkles")
      ->color(Color::Fuchsia)
      ->tooltip("Generate with AI");
  }

  public function targetField(string $field): static
  {
    $this->targetField = $field;
    return $this;
  }

  public function prompt(string $prompt): static
  {
    $this->aiPrompt = $prompt;
    return $this;
  }

  public function contextFields(array $fields): static
  {
    $this->contextFields = $fields;
    return $this;
  }

  public function silent(bool $condition = true): static
  {
    $this->silent = $condition;
    return $this;
  }

  public function expectArray(bool $condition = true): static
  {
    $this->expectArray = $condition;
    return $this;
  }

  public function normalizeArrayCase(bool $condition = true): static
  {
    $this->normalizeArrayCase = $condition;
    return $this;
  }

  public function allowedValues(array $values): static
  {
    $this->allowedValues = $values;
    return $this;
  }

  public function valueMap(array $map): static
  {
    $this->valueMap = $map;
    return $this;
  }

  /**
   * Register a callback to run before generation for this specific action instrance.
   * Return false to cancel generation.
   */
  public function beforeGenerate(callable $callback): static
  {
    $this->beforeGenerateCallback = $callback;
    return $this;
  }

  /**
   * Register a global callback to run before every generation across all action instances.
   * Return false to cancel generation.
   * Typically called in AppServiceProvider::boot().
   */
  public static function globalBeforeGenerate(callable $callback): void
  {
    static::$globalBeforeGenerateCallback = $callback;
  }

  public function setUp(): void
  {
    parent::setUp();

    $this->modalHeading(fn() => $this->silent ? null : "Generate with AI")
      ->modalDescription(fn() => $this->silent ? null : "Describe what you want to write.")
      ->modalSubmitAction(fn($action) => $action->color("primary")->label($this->silent ? "" : "Generate"))
      ->successNotification(null)
      ->form(function (): array {
        if ($this->silent) {
          return [];
        }

        return [
          Textarea::make("ai_input")
            ->label("What do you want to write?")
            ->placeholder("Briefly describe...")
            ->required()
            ->rows(4)
            ->autofocus(),
        ];
      })
      ->action(function (array $data, Get $get, Set $set): void {
        if (!$this->runBeforeGenerateHooks()) {
          return;
        }

        if ($this->silent) {
          $this->runSilent($get, $set);
        } else {
          $provider = app(AiProvider::class);
          $result = $provider->generate($this->aiPrompt, $data["ai_input"]);

          if ($this->expectArray) {
            $parsed = $this->parseArrayResult($result, $this->normalizeArrayCase);

            $set($this->targetField, $parsed);
            $this->dispatchEvent($this->targetField, $result);
            $this->notifyGenerationSuccess();
            return;
          }

          $set($this->targetField, trim($result));
          $this->dispatchEvent($this->targetField, $result);
          $this->notifyGenerationSuccess();
        }
      });
  }

  /**
   * Runs the global hook first, then the instance hook.
   * Returns false if any hook cancels the generation.
   */
  protected function runBeforeGenerateHooks(): bool
  {
    if (static::$globalBeforeGenerateCallback !== null) {
      if ((static::$globalBeforeGenerateCallback)() === false) {
        return false;
      }
    }

    if ($this->beforeGenerateCallback !== null) {
      if (($this->beforeGenerateCallback)() === false) {
        return false;
      }
    }

    return true;
  }

  protected function dispatchEvent(string $field, string $rawResult): void
  {
    $provider = config("filament-ai-writer.provider");
    $model = config("filament-ai-writer.{$provider}.model");

    AiTextGenerated::dispatch([
      "user" => auth()->guard()->user(),
      "field" => $field,
      "provider" => $provider,
      "model" => $model,
      "result" => $rawResult,
    ]);
  }

  protected function runSilent(Get $get, Set $set): void
  {
    $missingFields = array_filter($this->contextFields, fn(string $field) => blank($get($field)));

    if (!empty($missingFields)) {
      Notification::make()
        ->warning()
        ->title("Missing context")
        ->color("warning")
        ->body("Fill in the following fields first: " . implode(", ", $missingFields))
        ->send();
      return;
    }

    $context = collect($this->contextFields)
      ->mapWithKeys(fn(string $field) => [$field => $get($field)])
      ->map(fn($value, $field) => "{$field}: {$value}")
      ->join("\n");

    $provider = app(AiProvider::class);

    if (!empty($this->valueMap)) {
      $mapHint =
        "\n\nAvailable options (return ONLY the key, nothing else):\n" .
        collect($this->valueMap)->map(fn($name, $id) => "{$id}: {$name}")->join("\n");

      $result = trim($provider->generate($this->aiPrompt, "Context:\n{$context}{$mapHint}"));
      $set($this->targetField, $result);
      $this->dispatchEvent($this->targetField, $result);
      $this->notifyGenerationSuccess();
      return;
    }

    if (!empty($this->allowedValues)) {
      $valuesHint =
        "\n\nYou MUST return only values from this list, as a JSON array:\n" . json_encode($this->allowedValues);

      $result = $provider->generate($this->aiPrompt, "Context:\n{$context}{$valuesHint}");
      $parsedValues = $this->parseArrayResult($result);
      $filtered = $this->filterAllowedValues($parsedValues);
      $set($this->targetField, $filtered);
      $this->dispatchEvent($this->targetField, $result);
      $this->notifyGenerationSuccess();
      return;
    }

    $result = $provider->generate($this->aiPrompt, "Context:\n{$context}");

    if ($this->expectArray) {
      $parsed = $this->parseArrayResult($result, $this->normalizeArrayCase);
      $set($this->targetField, $parsed);
      $this->dispatchEvent($this->targetField, $result);
      $this->notifyGenerationSuccess();
      return;
    }

    $set($this->targetField, trim($result));
    $this->dispatchEvent($this->targetField, $result);
    $this->notifyGenerationSuccess();
  }

  protected function parseArrayResult(string $result, bool $normalizeCase = false): array
  {
    $normalized = preg_replace("/```(?:json)?/i", "", $result);
    $normalized = trim((string) $normalized);

    $decoded = json_decode($normalized, true);

    if (is_array($decoded)) {
      return $this->normalizeArrayValues($decoded, $normalizeCase);
    }

    $parts = preg_split('/[\n,;|]+/', $normalized) ?: [];

    if (count($parts) === 1 && str_contains($parts[0], " ")) {
      $parts = preg_split("/\s+/", $parts[0]) ?: [];
    }

    return $this->normalizeArrayValues($parts, $normalizeCase);
  }

  protected function normalizeArrayValues(array $values, bool $normalizeCase = false): array
  {
    $result = [];
    $seen = [];

    foreach ($values as $value) {
      $normalizedValue = trim((string) $value);

      if ($normalizedValue === "") {
        continue;
      }

      if ($normalizeCase) {
        $normalizedValue = Str::lower($normalizedValue);
      }

      $key = Str::lower($normalizedValue);

      if (isset($seen[$key])) {
        continue;
      }

      $seen[$key] = true;
      $result[] = $normalizedValue;
    }

    return $result;
  }

  protected function filterAllowedValues(array $values): array
  {
    $allowedLookup = [];

    foreach ($this->allowedValues as $allowedValue) {
      $normalizedAllowedValue = trim((string) $allowedValue);

      if ($normalizedAllowedValue === "") {
        continue;
      }

      $allowedLookup[Str::lower($normalizedAllowedValue)] = $normalizedAllowedValue;
    }

    $result = [];
    $seen = [];

    foreach ($values as $value) {
      $key = Str::lower(trim((string) $value));

      if ($key === "" || !isset($allowedLookup[$key]) || isset($seen[$key])) {
        continue;
      }

      $seen[$key] = true;
      $result[] = $allowedLookup[$key];
    }

    return $result;
  }

  protected function notifyGenerationSuccess(): void
  {
    Notification::make()->success()->title("Generated successfully")->color("success")->send();
  }
}
