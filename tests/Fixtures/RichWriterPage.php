<?php

namespace PdroLucas\FilamentAiWriter\Tests\Fixtures;

use Filament\Forms\Components\RichEditor;
use Filament\Schemas\Schema;
use PdroLucas\FilamentAiWriter\Actions\AiWriterAction;
use Workbench\App\Pages\WriterDemo;

class RichWriterPage extends WriterDemo
{
  public function form(Schema $schema): Schema
  {
    return $schema
      ->components([
        RichEditor::make('body')->hintAction(
          AiWriterAction::make()
            ->targetField('body')
            ->prompt('Write the article.')
            ->silent(),
        ),
      ])
      ->statePath('data');
  }
}
