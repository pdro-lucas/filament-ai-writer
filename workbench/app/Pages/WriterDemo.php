<?php

namespace Workbench\App\Pages;

use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use PdroLucas\FilamentAiWriter\Actions\AiWriterAction;

class WriterDemo extends Page
{
  protected static ?string $title = 'Try AI Writer';

  protected string $view = 'workbench::writer-demo';

  public ?array $data = [];

  public function mount(): void
  {
    $this->form->fill(['topic' => 'A short introduction to Filament plugins']);
  }

  public function form(Schema $schema): Schema
  {
    return $schema
      ->components([
        Textarea::make('topic')->label('Topic')->required(),
        Textarea::make('content')
          ->label('Generated text')
          ->rows(6)
          ->hintAction(
            AiWriterAction::make()
              ->label('Generate demo text')
              ->targetField('content')
              ->contextFields(['topic'])
              ->prompt('Write a short introduction about the supplied topic.')
              ->silent(),
          ),
        Textarea::make('interactive_content')
          ->label('Interactive text')
          ->rows(6)
          ->hintAction(
            AiWriterAction::make('ai_interactive')
              ->label('Open writing prompt')
              ->targetField('interactive_content')
              ->prompt('Write a short text based on the request.'),
          ),
      ])
      ->statePath('data');
  }
}
