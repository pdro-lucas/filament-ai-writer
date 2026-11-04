<?php
namespace PdroLucas\FilamentAiWriter\Actions;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Colors\Color;
use Illuminate\Support\Str;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Data\GenerateResult;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Generation\Generation;
use PdroLucas\FilamentAiWriter\Support\FailureNotice;
use PdroLucas\FilamentAiWriter\Events\AiTextGenerated;
use PdroLucas\FilamentAiWriter\Support\Icons;

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

  // Modal controls
  protected bool $showToneControl = false;
  protected bool $showLengthControl = false;
  protected bool $showEmojiControl = false;

  /** @var callable|null */
  protected $beforeGenerateCallback = null;

  /**
   * Generation commits its own admission, delivery and settlement, and refuses to run inside an open
   * transaction. A panel's databaseTransactions() would wrap this action in one, so it never opts in.
   */
  public function hasDatabaseTransactions(): bool
  {
    return false;
  }

  /** @var callable|null */
  protected static $globalBeforeGenerateCallback = null;

  public static function make(?string $name = null): static
  {
    return parent::make($name ?? "ai_writer")
      ->label("")
      ->icon(Icons::resolve(Icons::ACTION))
      ->color(Color::Fuchsia)
      ->tooltip(__('filament-ai-writer::writer.label'));
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

  public function withToneControl(bool $condition = true): static
  {
    $this->showToneControl = $condition;
    return $this;
  }

  public function withLengthControl(bool $condition = true): static
  {
    $this->showLengthControl = $condition;
    return $this;
  }

  public function withEmojiControl(bool $condition = true): static
  {
    $this->showEmojiControl = $condition;
    return $this;
  }

  /**
   * Register a callback to run before generation for this specific action instance.
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

    $this->modalHeading(fn() => $this->silent ? null : __('filament-ai-writer::writer.label'))
      ->modalDescription(fn() => $this->silent ? null : __('filament-ai-writer::writer.modal.description'))
      ->modalSubmitAction(fn($action) => $action->color("primary")->label($this->silent ? "" : __('filament-ai-writer::writer.modal.submit')))
      ->successNotification(null)
      ->form(function (): array {
        if ($this->silent) {
          return [];
        }

        $fields = [
          Textarea::make("ai_input")
            ->label(__('filament-ai-writer::writer.input.label'))
            ->placeholder(__('filament-ai-writer::writer.input.placeholder'))
            ->required()
            ->minLength(10)
            ->rows(4)
            ->autofocus()
            ->columnSpanFull(),
        ];

        if ($this->showToneControl) {
          $fields[] = Select::make("ai_tone")
            ->label(__('filament-ai-writer::writer.tone.label'))
            ->options(collect(["professional", "casual", "formal", "friendly", "persuasive"])->mapWithKeys(fn (string $tone) => [$tone => __('filament-ai-writer::writer.tone.' . $tone)])->all())
            ->placeholder(__('filament-ai-writer::writer.tone.default'))
            ->native(false);
        }

        if ($this->showLengthControl) {
          $fields[] = Select::make("ai_length")
            ->label(__('filament-ai-writer::writer.length.label'))
            ->options(collect(["short", "medium", "long"])->mapWithKeys(fn (string $length) => [$length => __('filament-ai-writer::writer.length.' . $length)])->all())
            ->placeholder(__('filament-ai-writer::writer.tone.default'))
            ->native(false);
        }

        if ($this->showEmojiControl) {
          $fields[] = Toggle::make("ai_emojis")->label(__('filament-ai-writer::writer.emojis.label'))->default(false);
        }

        return $fields;
      })
      ->action(function (array $data, Get $get, Set $set): void {
        if (!$this->authorizeGeneration()) {
          return;
        }

        if (!$this->runBeforeGenerateHooks()) {
          return;
        }

        try {
          if ($this->silent) {
            $this->runSilent($get, $set);
          } else {
            $this->generateAndDeliver($this->buildPromptWithControls($this->aiPrompt, $data), $data["ai_input"], $get, $set);
          }
        } catch (GenerationFailure $failure) {
          FailureNotice::send($failure);
        }
      });
  }

  protected function authorizeGeneration(): bool
  {
    // Without a registered plugin the action runs standalone, as in 0.7.1: only its own authorization applies.
    $plugin = AiWriterPlugin::current();

    if (($plugin !== null && !$plugin->canUseAi()) || !$this->isAuthorized()) {
      Notification::make()
        ->warning()
        ->title(__('filament-ai-writer::failures.unauthorized'))
        ->send();

      return false;
    }

    return true;
  }

  protected function buildPromptWithControls(string $basePrompt, array $data)
  {
    $instructions = [];

    if (!empty($data["ai_tone"])) {
      $instructions[] = "Tone: {$data["ai_tone"]}.";
    }

    if (!empty($data["ai_length"])) {
      $lengthMap = [
        "short" => "Keep the response short and concise.",
        "medium" => "Write a medium-length response.",
        "long" => "Write a detailed and comprehensive response.",
      ];

      $instructions[] = $lengthMap[$data["ai_length"]] ?? "";
    }

    if (isset($data["ai_emojis"])) {
      $instructions[] = $data["ai_emojis"] ? "You may use emojis where appropiate." : "Do not use any emojis.";
    }

    if (empty($instructions)) {
      return $basePrompt;
    }

    return $basePrompt . "\n\nAdditional instructions:\n" . implode("\n", $instructions);
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

  protected function dispatchEvent(string $field, GenerateResult $result): void
  {
    // The text is already delivered and charged; a failing host listener must not lose it.
    try {
      AiTextGenerated::dispatch([
        "user" => Filament::auth()->user(),
        "field" => $field,
        "provider" => $result->provider,
        "model" => $result->model,
        "result" => $result->text,
      ]);
    } catch (\Throwable $exception) {
      report($exception);
    }
  }

  protected function generateAndDeliver(string $prompt, string $input, Get $get, Set $set): void
  {
    $original = $get($this->targetField);
    $result = app(Generation::class)->run($prompt, $input, function (GenerateResult $result) use ($set): void {
      $value = trim($result->text);
      if ($this->silent && !empty($this->valueMap)) {
        if (!array_key_exists($value, $this->valueMap)) {
          throw new GenerationFailure('invalid_output', result: $result->withoutText());
        }
      } elseif ($this->silent && !empty($this->allowedValues)) {
        $value = $this->filterAllowedValues($this->parseArrayResult($result->text));
      } elseif ($this->expectArray) {
        $value = $this->parseArrayResult($result->text, $this->normalizeArrayCase);
      }
      if ($value === '' || $value === []) {
        throw new GenerationFailure('invalid_output', result: $result->withoutText());
      }
      $set($this->targetField, $value);
    }, fn () => $set($this->targetField, $original), $this->targetField, actionName: $this->getName());
    $this->getLivewire()?->dispatch('ai-writer-credits-changed');
    $this->dispatchEvent($this->targetField, $result);
    $this->notifyGenerationSuccess();
  }

  protected function runSilent(Get $get, Set $set): void
  {
    $missingFields = array_filter($this->contextFields, fn(string $field) => blank($get($field)));

    if (!empty($missingFields)) {
      Notification::make()
        ->warning()
        ->title(__('filament-ai-writer::writer.missing.title'))
        ->color("warning")
        ->body(__('filament-ai-writer::writer.missing.body', ['fields' => implode(', ', $missingFields)]))
        ->send();
      return;
    }

    $context = collect($this->contextFields)
      ->mapWithKeys(fn(string $field) => [$field => $get($field)])
      ->map(fn($value, $field) => "{$field}: {$value}")
      ->join("\n");

    $input = "Context:\n{$context}";
    if (!empty($this->valueMap)) {
      $input .= "\n\nAvailable options (return ONLY the key, nothing else):\n" .
        collect($this->valueMap)->map(fn($name, $id) => "{$id}: {$name}")->join("\n");
    } elseif (!empty($this->allowedValues)) {
      $input .= "\n\nYou MUST return only values from this list, as a JSON array:\n" . json_encode($this->allowedValues);
    }
    $this->generateAndDeliver($this->aiPrompt, $input, $get, $set);
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
    Notification::make()->success()->title(__('filament-ai-writer::writer.success'))->color("success")->send();
  }
}
