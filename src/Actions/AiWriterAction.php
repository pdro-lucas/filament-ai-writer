<?php

namespace PdroLucas\FilamentAiWriter\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Set;
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

class AiWriterAction extends Action
{
  protected string $targetField = "";
  protected string $aiPrompt = "You are a writing assistant. Improve and format the provided text.";

  public static function make(?string $name = null): static
  {
    return parent::make($name ?? "ai_writer")
      ->label("")
      ->icon("heroicon-o-sparkles")
      ->color("gray")
      ->tooltip("Generate with AI")
      ->modalHeading("Generate with AI")
      ->modalDescription("Describe what you want to write and the AI will generate the text.")
      ->modalSubmitActionLabel("Generate")
      ->form([
        Textarea::make("ai_input")
          ->label("What do you want to write?")
          ->placeholder("Briefly describe...")
          ->required()
          ->rows(4)
          ->autofocus(),
      ]);
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

  public function setUp(): void
  {
    parent::setUp();

    $this->action(function (array $data, Set $set): void {
      /** @var AiProvider $provider */
      $provider = app(AiProvider::class);
      $result = $provider->generate($this->aiPrompt, $data["ai_input"]);
      $set($this->targetField, $result);
    });
  }
}
