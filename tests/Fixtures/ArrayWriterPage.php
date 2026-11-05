<?php

namespace PdroLucas\FilamentAiWriter\Tests\Fixtures;

use Filament\Forms\Components\TagsInput;
use Filament\Schemas\Schema;
use PdroLucas\FilamentAiWriter\Actions\AiWriterAction;
use Workbench\App\Pages\WriterDemo;

class ArrayWriterPage extends WriterDemo
{
  public function form(Schema $schema): Schema
  {
    return $schema
      ->components([
        TagsInput::make('tags')->hintAction(
          AiWriterAction::make()
            ->targetField('tags')
            ->prompt('Suggest tags as a JSON array.')
            ->expectArray()
            ->normalizeArrayCase()
            ->silent((bool) config('testing.silent')),
        ),
      ])
      ->statePath('data');
  }
}
