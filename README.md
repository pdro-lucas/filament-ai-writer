# filament-ai-writer

A reusable Filament Action that adds AI-powered text generation to any form field. Supports Anthropic Claude, OpenAI, and Google Gemini out of the box.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12
- Filament 4.x

## Installation

```bash
composer require pdro-lucas/filament-ai-writer
```

The service provider is auto-discovered by Laravel. No manual registration needed.

## Configuration

Add your API key to `.env` depending on which provider you want to use:

```env
# Anthropic (default)
ANTHROPIC_API_KEY=sk-ant-...

# OpenAI
OPENAI_API_KEY=sk-...

# Google Gemini
GEMINI_API_KEY=...
```

To switch providers or customize the model, add the following variables:

```env
AI_WRITER_PROVIDER=anthropic   # anthropic | openai | gemini
AI_WRITER_MODEL=claude-sonnet-4-20250514
AI_WRITER_MAX_TOKENS=2048
```

If you need to customize the full configuration, publish the config file:

```bash
php artisan vendor:publish --tag=filament-ai-writer-config
```

This creates `config/filament-ai-writer.php` in your project, where you can set per-provider models, API keys, and token limits. Publishing is optional — the package works with just the `.env` variables.

## Usage

Import the action and attach it to any Filament form field using `hintAction` or `suffixAction`.

```php
use PdroLucas\FilamentAiWriter\Actions\AiWriterAction;
```

### Markdown editor

```php
use Filament\Forms\Components\MarkdownEditor;

MarkdownEditor::make('content')
    ->label('Content')
    ->hintAction(
        AiWriterAction::make()
            ->targetField('content')
            ->prompt('
                You are a content writing expert.
                Write rich Markdown with headers, lists, and well-structured paragraphs.
                Return ONLY the Markdown content, no explanations.
            ')
    ),
```

### Text input

```php
use Filament\Forms\Components\TextInput;

TextInput::make('meta_description')
    ->label('Meta Description')
    ->suffixAction(
        AiWriterAction::make('ai_meta')
            ->targetField('meta_description')
            ->prompt('
                Write an SEO-friendly meta description under 155 characters.
                Be direct and include a subtle call-to-action.
                Return ONLY the text, no quotes or explanations.
            ')
    ),
```

### Textarea

```php
use Filament\Forms\Components\Textarea;

Textarea::make('excerpt')
    ->label('Excerpt')
    ->hintAction(
        AiWriterAction::make('ai_excerpt')
            ->targetField('excerpt')
            ->prompt('
                Write a concise and engaging summary in 2-3 sentences.
                Tone: professional but accessible.
                Return ONLY the summary.
            ')
    ),
```

### How it works

When the user clicks the sparkles button on a field, a modal opens with a free-text area. The user describes what they want to write, submits, and the AI generates the text based on the configured prompt. The result is automatically injected into the target field.

Each field gets its own `AiWriterAction` instance with its own prompt, so you have full control over how the AI responds for each specific context.

## API

### `AiWriterAction::make(?string $name = null): static`

Creates a new action instance. The `$name` parameter defaults to `'ai_writer'`. If you attach more than one action to the same form, give each a unique name.

### `->targetField(string $field): static`

The name of the form field where the generated text will be injected. Must match the field name passed to `make()` on the field component.

### `->prompt(string $prompt): static`

The system prompt sent to the AI provider. Use this to define tone, format, length constraints, and any domain-specific instructions. The more specific the prompt, the better the output.

## Supported providers

| Provider  | Default model                  | Environment variable  |
|-----------|--------------------------------|-----------------------|
| Anthropic | `claude-sonnet-4-20250514`     | `ANTHROPIC_API_KEY`   |
| OpenAI    | `gpt-4o`                       | `OPENAI_API_KEY`      |
| Gemini    | `gemini-3.1-flash-lite`        | `GEMINI_API_KEY`      |

## Package structure

```
src/
  Actions/
    AiWriterAction.php          # The Filament Action
  Contracts/
    AiProvider.php              # Interface for AI providers
  Providers/
    AnthropicProvider.php
    OpenAiProvider.php
    GeminiProvider.php
  FilamentAiWriterServiceProvider.php
config/
  filament-ai-writer.php
```

## Extending with a custom provider

If you want to use a different AI provider, implement the `AiProvider` contract and rebind it in your `AppServiceProvider`:

```php
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

public function register(): void
{
    $this->app->bind(AiProvider::class, fn () => new MyCustomProvider());
}
```

## Contributing

Contributions are welcome! Feel free to open an issue or submit a pull request.

Before contributing, please ensure your changes follow the existing code style and include any necessary updates to documentation.

## License

This project is open-sourced under the [MIT license](LICENSE.md).
