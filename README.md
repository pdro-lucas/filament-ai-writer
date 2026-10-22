# filament-ai-writer

https://github.com/user-attachments/assets/05b8f24e-e01e-4acc-836a-32acda143b42


A Filament plugin for AI-assisted writing, with per-panel registration, host-defined permissions and a shared generation service. Its form action integrates OpenAI, Anthropic, Gemini and OpenRouter with scalar and array fields. Supported endpoints do not imply support for every model or optional API feature.

> **Status: Beta** — This package is in active development. APIs may change and issues can occur.

## Documentation

Start with [installation](#installation), [panel registration](#panel-plugin) and the [writing examples](#usage). See the [integration guide](docs/integration.md) for administration, scoped directories, custom action placement, credits and presentation. Reporting and maintenance are covered in [statistics](#current-panel-statistics) and [retention](#opt-in-usage-retention).

## Requirements

- PHP 8.2+
- Laravel 11.28+ or 12.x
- Filament 4.x (Livewire 3) or 5.x (Livewire 4)

## Installation

```bash
composer require pdro-lucas/filament-ai-writer
```

The Laravel service provider is auto-discovered. Register the plugin explicitly in each panel and install its usage and controls tables before generating:

```bash
php artisan vendor:publish --tag=filament-ai-writer-migrations
php artisan migrate
```

The package does not automatically run migrations. To customize the usage table name, publish the configuration and set `tables.usage` **before** running the migration; keep that name stable afterward. No changes to the host's user table are required.

## Configuration

Add your API key to `.env` depending on which provider you want to use:

```env
# Anthropic (requires an explicit AI_WRITER_MODEL)
ANTHROPIC_API_KEY=sk-ant-...

# OpenAI (default)
OPENAI_API_KEY=sk-...

# Google Gemini
GEMINI_API_KEY=...

# OpenRouter
OPENROUTER_API_KEY=sk-or-...
```

To switch providers or customize the model, add the following variables:

```env
AI_WRITER_PROVIDER=openai   # anthropic | openai | gemini | openrouter
AI_WRITER_MODEL=gpt-4o
AI_WRITER_MAX_TOKENS=2048
AI_WRITER_CONNECT_TIMEOUT=10
AI_WRITER_TIMEOUT=60
```

If you need to customize the full configuration, publish the config file:

```bash
php artisan vendor:publish --tag=filament-ai-writer
```

This creates `config/filament-ai-writer.php` in your project where you can set per-provider models, API keys, and token limits. Publishing configuration is optional when using the default table name and no host tariffs; publishing/running the migration is required.

## Panel plugin

Register a separate instance in each panel's `PanelProvider`:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

$panel->plugin(
    AiWriterPlugin::make()
        ->authorizeUseAiUsing(fn (Authenticatable $user, $panel): bool =>
            Gate::forUser($user)->allows('use-ai-writer'))
        ->authorizeViewStatisticsUsing(fn (Authenticatable $user, $panel): bool =>
            Gate::forUser($user)->allows('view-ai-writer-statistics'))
        ->authorizeManageUsing(fn (Authenticatable $user, $panel): bool =>
            Gate::forUser($user)->allows('manage-ai-writer')),
);
```

Callbacks receive the authenticated actor from that panel's guard and the panel itself. No User class, roles, columns or key type are required. Guests are denied. Authenticated actors may use AI by default; statistics and administration are denied unless their independent callbacks allow them. `AiWriterPlugin::get()` resolves the plugin on the current panel.

The action enforces registration, the panel's use permission and any host action-level authorization on the server before running generation hooks or contacting the provider. Interactive and silent actions use the same checks. Permissions are checked again on later requests, including submission of a modal opened before permission was revoked. A host `->authorize(true)` cannot bypass plugin authorization; host action authorization can further restrict access.

These callbacks can use permissions managed by **Filament Shield / Spatie Laravel Permission**, or host rules based on roles, user types and plans. See the [Shield and business-rule examples](docs/integration.md#filament-shield-roles-and-business-rules) for custom permissions, role assignment, Gate callbacks, guards and Super Admin behavior. Neither permission package is a dependency of AI Writer.

## Controls, credits and integration

The plugin ships three native panel pages:

| Surface | Required permission | Purpose |
| --- | --- | --- |
| **AI Writer controls** (`ai-writer-controls`) | `authorizeManageUsing()` | Settings for availability, quotas and abuse protections; Users with a reusable Manage AI modal; paginated Activity audit |
| **My AI credits** (`my-ai-credits`) | `authorizeUseAiUsing()` | The authenticated actor's availability, balance and protection status |
| **AI Writer usage** (`ai-writer-usage`) | `authorizeViewStatisticsUsing()` | Read-only metadata statistics for the current panel |

AI starts enabled, with quotas and all abuse protections off. No business limits are supplied. Management changes require a reason of at most 500 characters and write an audit entry atomically. Disabling AI or blocking an actor prevents **new admissions**; already admitted generations retain their delivery rights and execution deadlines. Quotas off does not override availability, blocks or permissions, and enabling quotas never retroactively charges a free generation.

Configure an explicit, scoped actor directory to manage users before their first generation. The plugin never infers a host User model, tenant column, display name or email. Return `ActorIdentity` with a human label in both search and resolve callbacks. The resolver remains required when embedding Manage AI in your own Resource with the built-in Users tab off.

Choose what appears on each panel instance:

```php
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

AiWriterPlugin::make()
    ->usersTab(false)          // Keep Settings/Activity; manage users in your Resource.
    ->creditsInTopbar(false);  // Keep the personal credit page; hide its topbar indicator.
```

These options do not revoke permissions. Authorization callbacks enforce access on the server, including later Livewire requests. AI availability, quotas, abuse protections, navigation visibility and retention are separate controls.

The [integration guide](docs/integration.md) provides:

- [Complete panel setup and a scoped actor directory](docs/integration.md#panel-setup-and-a-scoped-actor-directory).
- [Disable options and navigation-only customization](docs/integration.md#choose-what-to-disable).
- [Manage AI in Resource tables, record headers and custom pages](docs/integration.md#manage-ai-in-your-own-surfaces).
- [Writer actions in field suffixes and schema section headers](docs/integration.md#place-the-writing-action).
- [Own-credit APIs, native schema embedding and topbar refresh](docs/integration.md#show-personal-credit-status).
- [Themes, view overrides and page subclasses](docs/integration.md#customize-presentation).
- [Quota, abuse, deployment and recovery contracts](docs/integration.md#credits-deployment-and-recovery).

### Upgrading an existing installation

Register the plugin in every panel that uses writer actions. Service-provider discovery does not register a panel plugin. Remove temporary `authorize()` or `beforeGenerate()` callbacks that only duplicated plugin checks; retain application-specific restrictions. Actions outside a current Filament panel are unsupported.

Publish **new** migrations and run them before generating. The package never migrates automatically and requires no host user-table changes. Missing controls storage or a failing scope resolver refuses generation rather than bypassing stored policy.

For existing installations, migrations `000003` (credits) and `000004` (abuse) require a coordinated source/schema cutover. Pause admissions, recovery workers and pruning; drain old pending/received operations and stop old writers before migrating and deploying the new writers. Mixed-version rolling deployment is unsupported. Follow the [cutover details](docs/integration.md#credits-deployment-and-recovery) before resuming traffic.

## Usage

Import the action and attach it to a Filament field using `hintAction`, or `suffixAction` on a field that supports affixes. For other placements, see the [native form examples](docs/integration.md#place-the-writing-action).

```php
use PdroLucas\FilamentAiWriter\Actions\AiWriterAction;
```

The action works in two modes: **interactive** and **silent**. Interactive mode opens a modal where the user describes what they want. Silent mode reads other fields from the current form as context and generates immediately, with no modal.

### Interactive mode

The user clicks the sparkles button, a modal opens with a free-text area, and the AI generates content based on the user input and the configured prompt. The result is injected into the target field.

```php
use Filament\Forms\Components\MarkdownEditor;

MarkdownEditor::make('content')
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

```php
use Filament\Forms\Components\Textarea;

Textarea::make('excerpt')
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

### Silent mode

The user clicks the button and the AI generates immediately, reading the specified context fields from the current form. No modal is shown. A success notification is displayed when generation completes.

If any of the context fields are empty when the button is clicked, the action shows a warning notification listing the missing fields and does not generate.

```php
use Filament\Forms\Components\TextInput;

TextInput::make('slug')
    ->hintAction(
        AiWriterAction::make('ai_slug')
            ->targetField('slug')
            ->contextFields(['title'])
            ->prompt('
                Generate a URL-friendly slug based on the provided title.
                Return ONLY the slug, lowercase, hyphenated, no special characters.
            ')
            ->silent()
    ),
```

### Silent mode with a select field

Use `->valueMap()` when the target field is a select or radio. Pass the same `id => name` map used in `->options()`. The AI receives the available options as context and returns the key of the chosen option.

```php
use Filament\Forms\Components\Select;

$categories = Category::all()->pluck('name', 'id');

Select::make('category_id')
    ->label('Category')
    ->options($categories)
    ->hintAction(
        AiWriterAction::make('ai_category')
            ->targetField('category_id')
            ->contextFields(['title', 'body'])
            ->valueMap($categories->toArray())
            ->prompt('
                Based on the title and body, choose the most appropriate category.
                Return ONLY the numeric ID of the chosen category, nothing else.
            ')
            ->silent()
    ),
```

### Silent mode with tags or multi-select

Use `->allowedValues()` when the target field is a tags input or multi-select. The AI receives the list of allowed values and must return a JSON array containing only values from that list. Values not present in the list are filtered out before injection.

```php
use Filament\Forms\Components\TagsInput;

TagsInput::make('tags')
    ->hintAction(
        AiWriterAction::make('ai_tags')
            ->targetField('tags')
            ->contextFields(['title', 'body'])
            ->allowedValues(['laravel', 'filament', 'php', 'api', 'frontend', 'backend'])
            ->normalizeArrayCase()
            ->prompt('
                Suggest relevant tags based on the title and body.
                Return ONLY a raw JSON array of strings from the allowed list, no markdown, no explanation.
                Example: ["laravel","filament","php"]
            ')
            ->silent()
    ),
```

```php
// Dynamically from the database
$tags = Tag::all()->pluck('name')->toArray();

TagsInput::make('tags')
    ->hintAction(
        AiWriterAction::make('ai_tags')
            ->targetField('tags')
            ->contextFields(['title', 'body'])
            ->allowedValues($tags)
            ->normalizeArrayCase()
            ->prompt('
                Suggest relevant tags based on the title and body.
                Return ONLY a raw JSON array of strings from the allowed list, no markdown, no explanation.
                Example: ["laravel","filament","php"]
            ')
            ->silent()
    ),
```

When no `->allowedValues()` is provided but the target field still expects an array, use `->expectArray()`:

```php
TagsInput::make('tags')
    ->hintAction(
        AiWriterAction::make('ai_tags')
            ->targetField('tags')
            ->contextFields(['title', 'body'])
            ->expectArray()
            ->normalizeArrayCase()
            ->prompt('
                Suggest up to five relevant tags based on the title and body.
                Return ONLY a raw JSON array of strings, no markdown, no explanation.
                Example: ["laravel","filament","php"]
            ')
            ->silent()
    ),
```

## API reference

### `AiWriterAction::make(?string $name = null): static`

Creates a new action instance. Defaults to `'ai_writer'`. If you attach more than one action to the same form, give each a unique name.

### `->targetField(string $field): static`

The name of the form field where the generated text will be injected.

### `->prompt(string $prompt): static`

The system prompt sent to the AI provider. Use this to define tone, format, length constraints, and domain-specific instructions. The more specific the prompt, the better the output.

### `->contextFields(array $fields): static`

An array of field names to read from the current form and pass to the AI as context. Used in silent mode.

### `->silent(bool $condition = true): static`

Enables silent mode. The action generates immediately on click with no modal, using `contextFields` as input.

### `->valueMap(array $map): static`

An `id => name` map for select or radio fields. The AI receives the options as context and returns a single key. Use this instead of `->allowedValues()` when the field expects a scalar value.

### `->allowedValues(array $values): static`

A flat array of allowed values for tags inputs or multi-selects. The AI must return a JSON array containing only values from this list. Values not present in the list are filtered out before injection.

### `->expectArray(bool $condition = true): static`

Tells the action to parse the AI response as an array even when no `->allowedValues()` is defined. Useful for free-form tags where any value is acceptable.

### `->normalizeArrayCase(bool $condition = true): static`

Converts all values in the generated array to lowercase before injection. Useful for tags to ensure consistent casing regardless of what the AI returns.

### `->beforeGenerate(callable $callback): static`

Register a callback that runs before AI generation on this action instance. Return `false` to cancel generation. Useful for credit checks, permissions, or rate limiting.

```php
use Filament\Forms\Components\TextInput;

TextInput::make('meta_description')
    ->hintAction(
        AiWriterAction::make()
            ->targetField('meta_description')
            ->beforeGenerate(function (): bool {
                if (auth()->user()->credits <= 0) {
                    return false;
                }
                return true;
            })
            ->prompt('Write a meta description.')
    ),
```

### `AiWriterAction::globalBeforeGenerate(callable $callback): void`

Register a global callback that runs before every AI generation across all action instances. Typically called in `AppServiceProvider::boot()`. The instance-level `->beforeGenerate()` runs after this global hook.

Use the plugin permission callbacks for authorization; no manual authorization hook is required. Hooks can still veto generation for application-specific business rules before admission.

### `AiTextGenerated` event

Dispatched after every successful AI generation. The payload contains:

| Key        | Description                                      |
|------------|--------------------------------------------------|
| `user`     | The authenticated user (or null)                 |
| `field`    | The target field name                            |
| `provider` | The AI provider name (e.g. `openai`)             |
| `model`    | The model used for generation                    |
| `result`   | The raw text returned by the provider            |

Listen to this event to debit credits, log usage, or send notifications:

```php
// app/Providers/EventServiceProvider.php
use PdroLucas\FilamentAiWriter\Events\AiTextGenerated;

protected $listen = [
    AiTextGenerated::class => [DebitUserCredits::class],
];
```

```php
// app/Listeners/DebitUserCredits.php
class DebitUserCredits
{
    public function handle(AiTextGenerated $event): void
    {
        $user = $event->payload['user'];

        if ($user === null) {
            return;
        }

        $user->decrement('ai_credits');
    }
}
```

## Supported providers

| Provider  | Default model              | Environment variable |
|-----------|----------------------------|----------------------|
| Anthropic | none (set `AI_WRITER_MODEL`) | `ANTHROPIC_API_KEY`  |
| OpenAI    | `gpt-4o`                   | `OPENAI_API_KEY`     |
| Gemini    | `gemini-3.1-flash-lite`    | `GEMINI_API_KEY`     |
| OpenRouter | none (set `AI_WRITER_MODEL`) | `OPENROUTER_API_KEY` |

### OpenRouter

OpenRouter gives access to many models through one OpenAI-compatible API. It has no default model, so set one explicitly:

```env
AI_WRITER_PROVIDER=openrouter
OPENROUTER_API_KEY=sk-or-...
AI_WRITER_MODEL=openai/gpt-4o-mini
```

The provider sends your `app.url` and `app.name` as the `HTTP-Referer` and `X-Title` headers for OpenRouter attribution. All four providers use `AI_WRITER_CONNECT_TIMEOUT` and `AI_WRITER_TIMEOUT`.

### Provider results and beta interface upgrade

Custom providers now implement `AiProvider::generate(string $systemPrompt, string $userInput): GenerateResult`, replacing the old string return. Rebind the same `AiProvider` contract in the host container; custom provider identifiers remain supported.

```php
use PdroLucas\FilamentAiWriter\Data\GenerateResult;

return new GenerateResult(
    provider: 'my-provider',
    requestedModel: 'requested-model',
    model: 'returned-model',
    text: 'Generated text',
);
```

The immutable result holds visible text in memory, requested and returned models separately, inclusive input/output token totals, optional cache/read/write/reasoning details, response/request IDs, finish reason and HTTP duration in milliseconds. Unknown or inconsistent measurements remain `null`; cache and reasoning are already included in their totals and are not added again. A returned model that is absent stays unknown.

`outcome` is `text`, `empty`, `refused` or `incomplete`. Only usable `text` is delivered. Empty, refused, malformed and truncated responses preserve the original field; invalid selections and empty parsed arrays also preserve it. No success event is emitted for those outcomes. Internal thought blocks are excluded. Available usage is recorded even when the result cannot be delivered.

Providers throw `GenerationFailure` for configuration, transport, protocol or upstream failures. It carries a local safe failure code, status, safe IDs, duration, outcome-unknown flag and an optional metadata-only result, with no chained raw exception. Custom providers must follow this contract and must not attach prompts, credentials, raw errors or response bodies. `AiTextGenerated` remains a delivery event with text in memory; it is not a durable usage recorder. Its model is now the returned model, possibly `null`, rather than a guessed configuration value.

The plugin exposes `generation()->run($systemPrompt, $userInput, $deliver, $restore, $field, $operationId = null, $actionName = null)` for server-side integrations. It checks current-panel admission and owns execution/persistence. `$deliver` receives `GenerateResult`; `$restore` must restore the captured original scalar/array value if delivery or final storage fails. `AiWriterAction` supplies both callbacks and field conversion automatically. Keep delivery callbacks limited to reversible local field mutation; external side effects cannot be rolled back by this service. Call generation outside any database transaction on the usage connection: admission must be committed before HTTP, and an existing transaction is rejected before contacting the provider.

**Configuration and retry changes:** Anthropic and OpenRouter require an explicit nonblank model. OpenAI and Gemini use their documented package defaults when `AI_WRITER_MODEL` is absent or blank. Invalid nonblank models/provider names, keys or nonpositive timeout/token settings fail before HTTP. Anthropic uses `x-api-key` and Gemini uses `x-goog-api-key`, never a key in the URL. Each built-in adapter makes at most one POST and does not follow redirects or retry automatically, including timeouts, quota/authentication failures and 5xx responses. The former OpenRouter retry settings were removed. A new user submission is a new operation and may incur another provider charge; an ambiguous timeout is not proof of zero usage or cost.

OpenRouter's `usage.cost` is normalized directly from its JSON numeric lexeme to a canonical USD string with twelve fractional digits and half-up rounding. It is not converted through a binary float or replaced by upstream/BYOK cost details. A reported zero is known zero; missing/invalid cost is unavailable. Costs represent OpenRouter account charges, not necessarily the complete external BYOK bill. For other responses, cost remains unavailable unless the host configures a complete matching tariff as described below. No price, balance or tokenizer requests are added.

Endpoint references: [OpenAI Chat](https://developers.openai.com/api/reference/resources/chat/subresources/completions/methods/create), [Anthropic Messages](https://platform.claude.com/docs/en/api/messages/create), [Gemini generateContent](https://ai.google.dev/api/generate-content), [OpenRouter usage](https://openrouter.ai/docs/cookbook/administration/usage-accounting).

## Usage ledger and host tariffs

Each admitted operation inserts a committed `pending` attempt before contacting a provider. The current panel, its guard's actor class and opaque string identifier, target field, action name and UTC start time are captured on the server. The first response freezes allowlisted metadata as `received`; successful local delivery finishes as `delivered`. Rejection, timeout, invalid output or delivery failure finishes as `failed` when storage is available. Provider HTTP duration and local processing overhead are recorded separately in milliseconds. No historical aggregate or remote balance/price/tokenizer lookup runs during generation.

The ledger stores only identity/context and normalized measurements: no prompts, generated text, internal thoughts, credentials, raw response/error bodies or chained exceptions. A failure may have known tokens or cost even though no text was delivered. Missing measurements remain `null`; a known zero stays zero. Unknown-outcome timeouts are never treated as free. If storage fails before admission, HTTP is blocked. If storage fails after HTTP, the form is preserved/restored and no delivery success is emitted; an unresolved `pending` or `received` row may remain. It does not prove zero usage, and the plugin does not automatically retry or infer remote billing after a process crash.

The unique operation UUID plus attempt number prevents repeated local processing from making a second HTTP call for that operation. Finalization is idempotent and bound to the captured identity/context. A host integration may retain and resupply its **server-owned** operation UUID; a duplicate is safely rejected, not replayed, because generated text is never stored. Ordinary new action submissions create new operation UUIDs and may incur another charge. This does not deduplicate independent clicks or claim remote idempotency. Only attempt 1 is currently used.

Costs and tariff snapshots use canonical USD **TEXT** with twelve fractional digits, avoiding SQLite numeric-affinity rounding. To estimate a cost when none was reported, configure explicit string rates per million inclusive input/output tokens, keyed by the exact returned provider/model:

```php
// config/filament-ai-writer.php — example host tariff, not a current price quote
'pricing' => [
    'openai' => [
        'my-returned-model' => [
            'input_usd_per_million' => '2',
            'output_usd_per_million' => '10',
        ],
    ],
],
```

Both token totals, both valid string rates and the returned model must be known. Literal model identifiers containing `/` or `.` are supported. Requested aliases do not substitute for returned models. Provider-reported cost, including zero, takes precedence. Estimation multiplies inclusive totals once, rounds half-up only after summing both components, and records `estimated` plus the applied rate snapshot. Cache/reasoning details are not billed again. Missing, numeric/float, negative, overly precise or unmatched rates leave cost unavailable. Rates represent a host estimate of inclusive usage, not a promise to reproduce cache tier, reasoning tier or external BYOK billing. Historical estimates do not change when the configuration changes.

No cleanup or deletion runs automatically. Use the plugin statistics service for authorized reporting; reading `UsageAttempt` directly remains a host server-side responsibility, not an authorized statistics API.

## Current-panel statistics

The plugin registers **AI Writer usage** at `/your-panel/ai-writer-usage`. Login alone does not grant access: configure `authorizeViewStatisticsUsing()` with the host's permission/policy callback. It is independent of AI use and administration permission, and uses the current panel's guard. Permission is checked for page access, every subsequent Livewire request, and each statistics query/aggregate/filter-option read. A mounted page rejects a changed panel context or revoked permission.

The panel is fixed context, never a selectable cross-panel filter. An authorized viewer can inspect **all actors' metadata within the current panel**, not just their own calls. No cross-panel expansion/global view is exposed. Actor filters identify both actor class and opaque identifier, without joining a host User table or assuming names/roles/ID types.

History is read-only and paginated (10/25/50 rows; values are also bounded server-side). One screen shows Requests, Tokens consumed, Costs and Average duration above one requests histogram and a compact request table. The shared selection defaults to the last seven local calendar days, with Today/7 days/30 days/custom dates and actor/result filters. Success means delivered, failure means failed, and in progress includes pending and received. Period boundaries use the application's timezone and convert to UTC; the end date includes the entire local calendar day. Actor options are panel-scoped and bounded. Provider/model switching and comparisons are not part of the screen.

Rows open a right detail drawer with historical actual model/provider, exact recorded consumption/cost source, safe IDs and sanitized outcomes. Every drawer lookup rechecks current permission, guard and panel scope. Metrics, histogram and table share the same selection. Unknown measurements remain unavailable, known zero stays measured, estimated costs are labeled and incomplete known-cost subtotals show partial coverage; exact canonical values remain available in details. Token and duration summaries use available measurements and identify coverage.

Aggregation reads chunks of 500 records and sums decimal/integer strings in PHP, without SQL monetary conversion, float rounding or per-attempt subtotal overflow. History uses panel/date/actor/provider/model indexes; report runtime still grows with matching retained history. No statistics aggregate runs during AI generation, and no provider price/balance/tokenizer call is added. A custom server-side integration may use `AiWriterPlugin::get()->statistics()->query()` and `summary($filteredQuery)`; summary freshly checks permission and constrains its outer query to the current panel even if given a broader query. These builders are server-side objects, not client-controlled panel selectors.

Filament references: [custom pages](https://filamentphp.com/docs/5.x/navigation/custom-pages), [tables](https://filamentphp.com/docs/5.x/tables/overview).

## Opt-in usage retention

Nothing is deleted or scheduled by default. `retention.days` defaults to `null`; invoking cleanup without configuration or an explicit `--days` safely reports disabled. To opt in, set `AI_WRITER_RETENTION_DAYS=90` (or `retention.days` in the published config), inspect the dry run, then execute explicitly:

```bash
php artisan ai-writer:prune-usage --days=90 --dry-run
php artisan ai-writer:prune-usage --days=90
```

Days must be a positive integer. `retention.batch_size` defaults to 500 and must be 1–1000. No package scheduler is registered. A host may schedule this command deliberately after reviewing its own retention needs and CLI/operator access. The maintenance command spans the configured usage table across panels; it does not grant cross-panel statistics access to a panel viewer.

Only terminal `delivered`/`failed` attempts with a completion timestamp are eligible. Both operation start and completion must be **strictly before** the effective cutoff: the earlier of now minus the configured days and the start of the current app-local calendar month, converted to UTC. This protects unresolved `pending`/`received` rows and any row started or completed during the current app-calendar month, even with a very short retention setting. Credit balances, reservations and permanent operation identities are stored separately and are never deleted by this command. Unresolved rows are never presumed free or completed by cleanup.

Deletion selects bounded ID batches, freezes an upper ID for the run, and rechecks eligibility in each atomic DELETE. New/backdated inserts wait for a later explicit run. There is no whole-history transaction. Dry-run counts are observations at that read, not a concurrent snapshot guarantee. Successful output reports actual deleted rows and processed batches; on an error, the command returns failure and reports the already deleted count without raw database details. Earlier completed batches remain deleted. Repeating the command does not double-count prior deletion.

Deleted rows disappear from subsequent history queries, known subtotals and measurement coverage. This metadata cleanup cannot recover stored text (none is kept), prove remote billing, retry inference or alter provider accounts. Keep external backups/audit records according to the host's policy before opting in.

## Package structure

```
src/
  Actions/
    AiWriterAction.php                  # The Filament Action
  Contracts/
    AiProvider.php                      # Interface for AI providers
  Events/
    AiTextGenerated.php                 # Event dispatched after generation
  Providers/
    AnthropicProvider.php
    OpenAiProvider.php
    GeminiProvider.php
    OpenRouterProvider.php
  FilamentAiWriterServiceProvider.php
config/
  filament-ai-writer.php
```

## Extending with a custom provider

Implement the `AiProvider` contract and rebind it in your `AppServiceProvider`:

```php
use PdroLucas\FilamentAiWriter\Contracts\AiProvider;

public function register(): void
{
    $this->app->bind(AiProvider::class, fn () => new MyCustomProvider());
}
```

## Local development

The isolated Orchestra Testbench/Workbench demo uses Laravel 12, PHPUnit 11 and its own SQLite file. No host Laravel app, API key, Node build or paid API call is needed.

```bash
composer install
composer workbench:build
composer serve
```

Open `http://127.0.0.1:8000/demo` and sign in with `demo@example.test` / `password`. Enter a topic and click **Generate demo text** next to **Generated text** for silent generation. For interactive generation, click **Open writing prompt** next to **Interactive text**, enter a request of at least 10 characters and click **Generate**. Both modes fill their respective fields with a fixed local response. The demo always overrides the provider and key with fake values and blocks unexpected Laravel HTTP requests. The demo explicitly grants its authenticated viewer statistics permission. Follow **View this panel's usage history** or open `/demo/ai-writer-usage` after generation. Normal generation records tokens with unavailable cost. Include `[demo:estimated]` in the topic (silent) or modal request (interactive) to use an example host tariff; `[demo:failure]` returns a safe HTTP503 failure and `[demo:truncated]` returns incomplete output. Both failure scenarios preserve the original field and appear as failed attempts; no paid API is contacted. The demo tariff is an example, not a live price quote. Its public demo app key and credentials are for local use only; `serve` binds to loopback.

`workbench:build` migrates `workbench/database/database.sqlite`, seeds the demo user and publishes Filament assets. It is safe to repeat and does not reset the demo database. The package ServiceProvider never loads the demo providers in a consuming application.

```bash
composer test
```

Tests bootstrap with `WithWorkbench`, use a separate SQLite `:memory:` connection, and block unexpected HTTP calls. Tests must install their own `Http::fake()` responses; the demo response is not installed during unit tests. `tests/TestCase.php` is the shared base for subsequent coverage.

To check the other supported major without changing the manifest:

```bash
composer update 'filament/*' livewire/livewire --with='filament/filament:^4.0' --with='livewire/livewire:^3.0' -W
composer workbench:build
composer test
# Restore the default latest compatible major:
composer update 'filament/*' livewire/livewire -W
composer workbench:build
```

Stop the local server before switching dependency versions. Composer's lock file remains ignored according to the existing library convention.

## Contributing

Contributions are welcome! Feel free to open an issue or submit a pull request.

Before contributing, please ensure your changes follow the existing code style and include any necessary updates to documentation.

1. Fork the repository
2. Create a feature branch (`git checkout -b feat/my-feature`)
3. Commit your changes using [conventional commits](https://www.conventionalcommits.org/)
4. Push to your fork and open a pull request

## License

This project is open-sourced under the [MIT license](LICENSE.md).
