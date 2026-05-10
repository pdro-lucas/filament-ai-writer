<?php
namespace PdroLucas\FilamentAiWriter\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Str;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

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

  public static function make(?string $name = null): static
  {
    return parent::make($name ?? "ai_writer")
      ->label("")
      ->icon("heroicon-o-sparkles")
      ->color("gray")
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

  public function setUp(): void
  {
    parent::setUp();

    $this->modalHeading(fn() => $this->silent ? null : "Generate with AI")
      ->modalDescription(fn() => $this->silent ? null : "Describe what you want to write.")
      ->modalSubmitActionLabel(fn() => $this->silent ? null : "Generate")
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
        if ($this->silent) {
          $this->runSilent($get, $set);
        } else {
          $provider = app(AiProvider::class);
          $result = $provider->generate($this->aiPrompt, $data["ai_input"]);

          if ($this->expectArray) {
            $set($this->targetField, $this->parseArrayResult($result, $this->normalizeArrayCase));
            $this->sendSuccessNotification();
            return;
          }

          $set($this->targetField, trim($result));
          $this->sendSuccessNotification();
        }
      });
  }

  protected function runSilent(Get $get, Set $set): void
  {
    $missingFields = array_filter($this->contextFields, fn(string $field) => blank($get($field)));

    if (!empty($missingFields)) {
      Notification::make()
        ->warning()
        ->title("Missing context")
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
      $this->sendSuccessNotification();
      return;
    }

    if (!empty($this->allowedValues)) {
      $valuesHint =
        "\n\nYou MUST return only values from this list, as a JSON array:\n" . json_encode($this->allowedValues);

      $result = $provider->generate($this->aiPrompt, "Context:\n{$context}{$valuesHint}");
      $parsedValues = $this->parseArrayResult($result);
      $set($this->targetField, $this->filterAllowedValues($parsedValues));
      $this->sendSuccessNotification();
      return;
    }

    $result = $provider->generate($this->aiPrompt, "Context:\n{$context}");

    if ($this->expectArray) {
      $set($this->targetField, $this->parseArrayResult($result, $this->normalizeArrayCase));
      $this->sendSuccessNotification();
      return;
    }

    $set($this->targetField, trim($result));
    $this->sendSuccessNotification();
  }

  protected function parseArrayResult(string $result, bool $normalizeCase = false): array
  {
    $normalized = preg_replace("/```(?:json)?/i", "", $result);
    $normalized = trim((string) $normalized);

    $decoded = json_decode($normalized, true);

    if (is_array($decoded)) {
      return $this->normalizeArrayValues($decoded, $normalizeCase);
    }

    $parts = preg_split("/[\n,;|]+/", $normalized) ?: [];

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

  protected function sendSuccessNotification(): void
  {
    Notification::make()->success()->title("Generated successfully")->send();
  }
}
