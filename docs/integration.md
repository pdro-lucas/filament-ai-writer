# Integrating AI Writer into your panel

This guide complements the [README](../README.md). All examples use the existing Filament 4/5 action and schema APIs. Adapt host model fields and permissions to your application; the plugin does not infer them.

- [Panel setup and a scoped actor directory](#panel-setup-and-a-scoped-actor-directory)
- [Filament Shield, roles and business rules](#filament-shield-roles-and-business-rules)
- [Choose what to disable](#choose-what-to-disable)
- [Manage AI in your own surfaces](#manage-ai-in-your-own-surfaces)
- [Place the writing action](#place-the-writing-action)
- [Show personal credit status](#show-personal-credit-status)
- [Customize presentation](#customize-presentation)
- [Credits, deployment and recovery](#credits-deployment-and-recovery)
- [Abuse protection reference](#abuse-protection-reference)
- [Persistence and audit](#persistence-and-audit)

## Panel setup and a scoped actor directory

Install the package, publish migrations and migrate as described in the [README](../README.md#installation). Tables are required even with quotas and protections off. For an existing installation, read the [cutover requirements](#credits-deployment-and-recovery) first.

Register a separate plugin instance in each `PanelProvider::panel()`. The following is a complete plugin configuration to add to your existing panel chain. It illustrates a host with `App\Models\User`, `tenant_id`, `name` and `email`, and host-defined Gate abilities. These are example host choices, not package requirements. This host uses a tenant assigned to the authenticated account; an application with switchable tenants must instead derive and authorize its active tenant on the server.

```php
use App\Models\User;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;

// Inside your existing PanelProvider::panel(Panel $panel): Panel method:
return $panel->plugin(
    AiWriterPlugin::make()
        ->authorizeUseAiUsing(fn (Authenticatable $actor, Panel $panel): bool =>
            $actor instanceof User && $actor->tenant_id !== null
            && Gate::forUser($actor)->allows('use-ai-writer'))
        ->authorizeViewStatisticsUsing(fn (Authenticatable $actor, Panel $panel): bool =>
            $actor instanceof User && $actor->tenant_id !== null
            && Gate::forUser($actor)->allows('view-ai-writer-statistics'))
        ->authorizeManageUsing(fn (Authenticatable $actor, Panel $panel): bool =>
            $actor instanceof User && $actor->tenant_id !== null
            && Gate::forUser($actor)->allows('manage-ai-writer'))
        ->resolveScopeUsing(function (Authenticatable $actor, Panel $panel): string {
            abort_unless($actor instanceof User && $actor->tenant_id !== null, 403);
            return 'tenant:' . $actor->tenant_id;
        })
        ->actorDirectoryUsing(
            search: function (string $search, string $scope, Panel $panel): array {
                $admin = $panel->auth()->user();
                abort_unless($admin instanceof User && $admin->tenant_id !== null, 403);
                abort_unless($scope === 'tenant:' . $admin->tenant_id, 403);

                return User::query()
                    ->where('tenant_id', $admin->tenant_id)
                    ->where(function ($query) use ($search): void {
                        $query->where('name', 'like', '%' . $search . '%')
                            ->orWhere('email', 'like', '%' . $search . '%');
                    })
                    ->limit(50)->get()
                    ->map(fn (User $user): ActorIdentity => new ActorIdentity(
                        User::class, $user->getAuthIdentifier(),
                        $user->name . ' · ' . $user->email,
                    ))->all();
            },
            resolve: function (string $type, string $id, string $scope, Panel $panel): ?ActorIdentity {
                $admin = $panel->auth()->user();
                abort_unless($admin instanceof User && $admin->tenant_id !== null, 403);
                abort_unless($scope === 'tenant:' . $admin->tenant_id, 403);
                if ($type !== User::class) {
                    return null;
                }

                $user = User::query()->where('tenant_id', $admin->tenant_id)->find($id);
                return $user === null ? null : new ActorIdentity(
                    User::class, $user->getAuthIdentifier(),
                    $user->name . ' · ' . $user->email,
                );
            },
        ),
);
```

Keep any additional host visibility restrictions in **both** callbacks. Search alone cannot authorize a selected ID. Resolve must return the exact requested type/ID or `null` for an actor the administrator cannot manage. This directory supports users before their first generation; it is not built from usage history. Search accepts at most 100 characters and retains at most 50 results, so also bound the host query.

`ActorIdentity` stores an explicit type plus opaque authentication identifier. Integer `1` and string `'1'` normalize to the same identity; `'01'` is different, and another type with ID `'1'` is different. The plugin never instantiates a class supplied by a browser. Labels should be human names/emails supplied by the host in **both** callbacks. They must be valid UTF-8 of at most 255 bytes; compose a shorter label if your host fields can exceed that. Empty labels fall back to the opaque ID. Keep labels escaped and HTML labels disabled.

Without custom callbacks, authenticated panel actors may use AI; management and statistics default to denied. Guests are denied. The three permissions are independent: managing AI does not grant generation or statistics, and blocking an administrator's generation does not remove management permission. Authorization uses the current panel's guard.

The default control scope is `panel:<panel id>`. A custom stable scope deliberately shares availability, blocks, quota periods, protections and audit among panels returning that string. Scope and actor values must be nonempty UTF-8 strings of at most 255 bytes without control characters. A resolver does not grant access; host authorization must protect the resolved scope. Usage statistics remain **panel-scoped**, showing all actors in that panel; a tenant control scope does not make the statistics page tenant-filtered. Grant statistics only to viewers allowed to see the whole panel's metadata.

The built-in pages freeze context at mount and reject changed panel/guard/scope/actor or revoked permission on later requests. Reopen after switching accounts or tenants. `canUseAi()` is a permission check; it does not itself read availability, balance or abuse state. Generation checks those at admission.

## Filament Shield, roles and business rules

The authorization callbacks can delegate to Laravel's Gate, including permissions managed by **Filament Shield / Spatie Laravel Permission**. AI Writer does not require or install either package, register Shield permissions automatically, or assume role names. Configure Shield in the host application first, including its user model, guard and any teams/tenancy integration.

For Shield 4.x, merge custom permissions into `config/filament-shield.php` and enable their tab in the Role Resource. The following names are examples; retain your other settings:

```php
'custom_permissions' => [
    'Use:AiWriter' => 'Use AI Writer',
    'View:AiWriterUsage' => 'View AI usage',
    'Manage:AiWriter' => 'Manage AI access and credits',
],

'shield_resource' => [
    // Keep your other Shield resource settings.
    'tabs' => [
        // Keep your other tab settings.
        'custom_permissions' => true,
    ],
],
```

Create/synchronize the permissions using your host's Shield setup or seeder, then assign them to roles in its Role Resource. Use the **actual stored permission names** in the callbacks: Shield can format keys according to its configured case/separator. See Shield's [custom permissions documentation](https://filamentphp.com/plugins/bezhansalleh-shield#custom-permissions) for the configuration supported by your installed version.

Connect those permissions to the plugin in your PanelProvider:

```php
use Illuminate\Support\Facades\Gate;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

AiWriterPlugin::make()
    ->authorizeUseAiUsing(fn ($user, $panel): bool =>
        Gate::forUser($user)->allows('Use:AiWriter'))
    ->authorizeViewStatisticsUsing(fn ($user, $panel): bool =>
        Gate::forUser($user)->allows('View:AiWriterUsage'))
    ->authorizeManageUsing(fn ($user, $panel): bool =>
        Gate::forUser($user)->allows('Manage:AiWriter'));
```

For example, a Writer role can receive only `Use:AiWriter`, an Analyst only `View:AiWriterUsage`, and an AI Administrator only `Manage:AiWriter`. Give a role multiple permissions when it needs multiple capabilities. Assign users to roles through the host's existing user-management flow. Removing a permission changes subsequent authorization checks; hiding a navigation entry alone does not revoke access.

The permission guard must match the panel's authenticated actor. With Spatie teams or tenant-aware permissions, establish the host's authorized team context before checking Gate. AI Writer's `resolveScopeUsing()` defines its control/credit scope; it does not configure Shield teams automatically. Statistics authorization covers all metadata in the current panel, so grant that permission accordingly.

Gate-based checks also honor a host's Super Admin rule implemented with `Gate::before()`. Direct `hasPermissionTo()` checks bypass that Gate override, as explained in Spatie's [Super Admin documentation](https://github.com/spatie/laravel-permission/blob/main/docs/basic-usage/super-admin.md). A permission grant still does not bypass AI Writer's availability, user blocks, quotas or abuse protections.

Roles are one way to assign permissions. A callback may also combine the permission with host business rules, such as user type or plan. In this example, `StaffUser` is an illustrative host model:

```php
use App\Models\StaffUser;
use Illuminate\Support\Facades\Gate;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

AiWriterPlugin::make()
    ->authorizeUseAiUsing(fn ($user): bool =>
        $user instanceof StaffUser
        && Gate::forUser($user)->allows('Use:AiWriter'));
```

A focused isolated validation used Shield **4.3.1**, Spatie Laravel Permission **8.3.2**, Filament **5.10.3**, Laravel **12.70.1** and PHP **8.5.0**, with SQLite in memory and fake provider HTTP. It exercised custom permission keys, separate role capabilities, successful generation, permission revocation and Shield's Gate-based Super Admin while retaining AI availability enforcement (1 test / 21 assertions). Shield was installed only in the temporary validation project, not added to this package. This does not claim a Shield Role Resource browser test, teams/tenancy validation or a Shield-plus-Filament-4 runtime matrix.

The host owns those rules; the plugin rechecks the configured callbacks on the server. Its built-in administrative UI controls availability per scope and blocks per user, not role policies or role-specific credit limits. Already admitted generations retain their delivery rights when a later policy change denies new requests.

## Choose what to disable

| Desired outcome | Supported control | Effect |
| --- | --- | --- |
| Use your existing User Resource | `usersTab(false)` | Hides the built-in Users task and rejects forged requests to it; Settings/Activity and scoped management APIs remain |
| Remove the topbar indicator | `creditsInTopbar(false)` | Hides that instance's indicator; the personal page and enforcement remain |
| Hide controls/personal navigation | Concrete page subclasses below | Removes navigation entries; routes and inherited authorization remain |
| Deny AI use | `authorizeUseAiUsing(fn (): bool => false)` | Denies new writer requests and own-credit surfaces; registration, storage and ledger remain required |
| Deny statistics | `authorizeViewStatisticsUsing(fn (): bool => false)` | Denies the statistics route and later reads, independently of use/manage |
| Deny administration | `authorizeManageUsing(fn (): bool => false)` | Denies controls and management actions/reads/writes, independently of use/statistics |
| Pause new generations for a scope | `controls()->setEnabled($context, false, $reason)` | Audited admission control; already admitted calls can finish |
| Block one actor | `controls()->setBlocked($context, $actor, true, $reason)` | Audited scoped block on new admissions; requires the actor directory |
| Turn quotas off | `credits()->configure($context, false, $limit, $renewal, $reason)` | Keeps ledger/history; new admissions are free of credit reservation, subject to all other checks |
| Turn protections off | `abuse()->configure()` with individual flags `false` | Each protection is independent of availability, permissions and quotas |
| Keep all usage history | Leave `retention.days` unset/`null` and do not prune | Retention is opt-in; no scheduler is installed |

The presentation setters are per instance and should be configured before registration. There are no plugin setters to unregister individual pages or run generation without its ledger/tables. Hiding navigation, Users or the topbar does not disable enforcement. Revoked use permission prevents new requests; it does not cancel an operation admitted earlier.

For navigation-only changes, create these two concrete classes in their own files:

```php
namespace App\Filament\Pages;

use PdroLucas\FilamentAiWriter\Pages\AiControlsPage;

class TeamAiControls extends AiControlsPage
{
    protected static bool $shouldRegisterNavigation = false;
}
```

```php
namespace App\Filament\Pages;

use PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage;

class TeamAiCredits extends MyAiCreditsPage
{
    protected static bool $shouldRegisterNavigation = false;
}
```

Select them on the plugin, without also registering the same classes through page discovery:

```php
use App\Filament\Pages\TeamAiControls;
use App\Filament\Pages\TeamAiCredits;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

AiWriterPlugin::make()
    ->controlsPage(TeamAiControls::class)
    ->creditsPage(TeamAiCredits::class)
    ->usersTab(false)
    ->creditsInTopbar(false);
```

These pages still have routes and their inherited access checks. The plugin exposes class setters for controls and personal credits, not a statistics-page setter. To deny statistics, use its authorization callback; to change a panel's navigation composition, use the host's native Filament navigation configuration.

For a panel where no AI feature should be accessible, set all three authorization callbacks to `false`; optionally also turn off the topbar. To keep generation but remove administration and reporting, leave use permission configured and deny only management/statistics. Permission changes do not erase history or bypass migration requirements.

## Manage AI in your own surfaces

`ManageAiUserAction` is the administrative action. It opens native status/credit sections and separate confirmations for block/unblock, individual limit/default, extras and reset. It requires **management** permission. `AiWriterAction` generates text and requires **use** permission.

Keep the directory from the setup example even with `usersTab(false)`. You may use `search: fn (): array => []` when no built-in search is needed, but both callbacks must be configured and resolve must still enforce host scope/visibility.

### Existing User Resource table

Add this action alongside existing actions in your Resource's `table()` method. This example uses the same illustrative `App\Models\User` fields as the directory; retain the Resource's own tenant scoping and record authorization.

```php
use App\Models\User;
use Filament\Tables\Table;
use PdroLucas\FilamentAiWriter\Actions\ManageAiUserAction;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;

public static function table(Table $table): Table
{
    return $table->recordActions([
        ManageAiUserAction::make()->actorUsing(fn (User $record): ActorIdentity =>
            new ActorIdentity(User::class, $record->getAuthIdentifier(),
                $record->name . ' · ' . $record->email)),
    ]);
}
```

### Resource record header

Inside your existing Edit/View record page, append to its header actions:

```php
use App\Models\User;
use PdroLucas\FilamentAiWriter\Actions\ManageAiUserAction;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;

protected function getHeaderActions(): array
{
    return [
        ManageAiUserAction::make()->actorUsing(function (): ActorIdentity {
            $user = $this->getRecord();
            abort_unless($user instanceof User, 403);
            return new ActorIdentity(User::class, $user->getAuthIdentifier(),
                $user->name . ' · ' . $user->email);
        }),
    ];
}
```

### Ordinary Filament page

This minimal page manages the signed-in user as an example of a server-derived target. Replace the target lookup with your host's authorized record selection to manage another user. Register the page using your usual panel page discovery or `pages()` configuration.

```php
namespace App\Filament\Pages;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use PdroLucas\FilamentAiWriter\Actions\ManageAiUserAction;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;

class ManageMemberAi extends Page
{
    protected string $view = 'filament.pages.manage-member-ai';

    public function manageAiUserAction(): ManageAiUserAction
    {
        return ManageAiUserAction::make()->actorUsing(function (): ActorIdentity {
            $user = Filament::auth()->user();
            abort_unless($user instanceof User, 403);
            return new ActorIdentity(User::class, $user->getAuthIdentifier(),
                $user->name . ' · ' . $user->email);
        });
    }
}
```

Create `resources/views/filament/pages/manage-member-ai.blade.php`:

```blade
<x-filament-panels::page>
    {{ $this->manageAiUserAction }}
</x-filament-panels::page>
```

The native Page already supplies action/schema support and the page wrapper renders action modals. No plugin-specific trait or properties are needed. A plain Livewire component must supply Filament's standard `HasActions`/`InteractsWithActions`, `HasSchemas`/`InteractsWithSchemas` and `<x-filament-actions::modals />` setup, and must run within the authenticated current plugin panel; it does not create an outside-panel integration.

`actorUsing()` maps a server record to an identity; it does not establish authority by itself. The action resolves that identity through the scoped directory at mount and again on reads/mutations. Its encrypted confirmation binds purpose, component, panel, guard, scope, administrator and target; child confirmations have their own purpose. Browser arguments cannot redirect an open confirmation. Keep inherited guards when subclassing; do not copy the action's mutation logic into host click handlers.

## Place the writing action

Writer actions need native schema context so their injected `Get`/`Set` can read context fields and deliver into the target. Keep relative field names in the same schema state container. Use unique action names when more than one appears. The [README](../README.md#usage) contains interactive, silent, select and array examples.

For a text input suffix, use the field's native affix API:

```php
use Filament\Forms\Components\TextInput;
use PdroLucas\FilamentAiWriter\Actions\AiWriterAction;

TextInput::make('headline')
    ->suffixAction(
        AiWriterAction::make('writeHeadline')
            ->targetField('headline')
            ->prompt('Write a concise headline for the request.'),
    );
```

For a form section header, attach the action to a native **schema Section**, keeping the field inside that section:

```php
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Section;
use PdroLucas\FilamentAiWriter\Actions\AiWriterAction;

Section::make('Draft')
    ->schema([
        Textarea::make('topic'),
        Textarea::make('content')->rows(8),
    ])
    ->headerActions([
        AiWriterAction::make('writeDraft')
            ->targetField('content')
            ->contextFields(['topic'])
            ->prompt('Write a short draft using the topic.')
            ->silent(),
    ]);
```

Place these components in your existing Resource form or native Page's `form(Schema $schema)` components. A schema Section header supplies schema context; a standalone Resource page header is not interchangeable with it for this field-writing action. Manage AI record headers above do not need form `Get`/`Set`.

Interactive controls can be hidden individually with `withToneControl(false)`, `withLengthControl(false)` and `withEmojiControl(false)`. `silent()` skips the prompt modal and uses `prompt()` plus `contextFields()`. These options do not relax use permission, action authorization or admission checks. Hooks run only after plugin/action authorization; a host `authorize(true)` cannot bypass it.

## Show personal credit status

The built-in My AI credits page and topbar indicator use **target-free** APIs. They need `canUseAi()`, not management/statistics or a user directory. A host can embed the same native status schema in a personal page by extending `MyAiCreditsPage`, which retains mount/hydrate context checks:

```php
namespace App\Filament\Pages;

use PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage;

class TeamAiCredits extends MyAiCreditsPage
{
    protected static ?string $title = 'My writing allowance';
    protected string $view = 'filament.pages.team-ai-credits';
}
```

In `resources/views/filament/pages/team-ai-credits.blade.php`:

```blade
<x-filament-panels::page>
    {{ \Filament\Schemas\Schema::make($this)->components(
        \PdroLucas\FilamentAiWriter\Schemas\AiCreditStatus::components(
            $balance, $abuse, ['enabled' => $enabled, 'blocked' => $blocked],
        ),
    ) }}
</x-filament-panels::page>
```

Register this class with `creditsPage(TeamAiCredits::class)` on the plugin. The earlier navigation example and this example are alternative definitions of the same illustrative host class.

For your own server-side surface, these are the reads used to obtain authorized snapshots:

```php
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Schemas\AiCreditStatus;

$plugin = AiWriterPlugin::get();
$context = $plugin->controlContext();
$abuse = $plugin->abuse()->ownStatus($context); // Recovery before reading balance.
$balance = $plugin->credits()->ownBalance($context);
$availability = $plugin->controls()->ownStatus($context);
$components = AiCreditStatus::components($balance, $abuse, $availability);
```

Resolve context on the server and revalidate it on every request; persistent host components should fence the mounted panel/guard/scope/actor as the built-in page does. These own APIs accept no target identity and validate the fresh snapshot. Never accept an actor/scope from the browser to build a context. `AiCreditStatus` is presentation only: it accepts the snapshots, does not authorize data and must not be fed arbitrary client balance/status arrays. For administrative target reads, use the reusable action or the authorized `balance()`/`status()` APIs with a scoped directory.

The topbar Sparkles button links to the instance's configured credit page. It shows numeric **available** credits when quotas are enabled, **Unlimited** with a **Credits are off** tooltip when quotas are off, and **Credits unavailable** on storage failure. Retained off-quota balances are not advertised as spendable. A number or Unlimited is not a promise of admission: availability, blocks and protections still apply. Guests, denied users and changed contexts cannot see stale values.

The indicator refreshes on the native `ai-writer-credits-changed` Livewire event after delivered writer actions and successful administrative UI changes. For a host Livewire action that successfully changes credits through the public APIs, refresh the current browser's indicator with:

```php
// Inside the host Livewire component, after its successful server-side mutation:
$this->dispatch('ai-writer-credits-changed');
```

There is no polling or custom JavaScript. Direct service calls and other browser sessions do not broadcast changes to this browser; a normal refresh reads them. `creditsInTopbar(false)` only removes the indicator.

## Customize presentation

Filament owns colors, borders, radii, inputs, tables and modals. Use your native Filament theme. The plugin wrapper is transparent and its CSS sets grid gap, minimum width and left-aligned navigation. In your host theme:

```css
:root { --ai-writer-controls-gap: 1rem; }
/* Optional host styling, scoped to the controls page. */
.ai-controls[data-ai-controls="admin"] .fi-section { box-shadow: none; }
```

Page hooks include `.ai-controls`, `.ai-controls-nav` and `data-ai-controls` values `admin`, `settings`, `users`, `activity`, `own-credits` and `credits-indicator`. Native table/status aliases include `.ai-controls-directory`, `.ai-controls-list`, `.ai-controls-ledger`, `.ai-controls-meta` and `.ai-controls-status` with `data-state="available|paused"`. Early draft palette variables do not map to native components.

Publish the namespaced views if you need reference copies:

```bash
php artisan vendor:publish --tag=filament-ai-writer-views
```

Laravel overrides live under `resources/views/vendor/filament-ai-writer/`. Keep only the copies you need so upstream view fixes can reach other views.

| View path relative to that directory | Scope |
| --- | --- |
| `controls/balance.blade.php` | Built-in personal page only; receives `$balance`, `$abuse`, `$enabled`, `$blocked` and renders the native schema |
| `controls/settings.blade.php`, `controls/users.blade.php`, `controls/styles.blade.php` | Controls partials and layout styles |
| `ai-controls.blade.php`, `my-ai-credits.blade.php` | Full built-in page views |
| `credits-indicator.blade.php`, `credits-indicator-hook.blade.php` | Indicator and panel hook |

`ManageAiUserAction` calls `AiCreditStatus` directly, so overriding `controls/balance.blade.php` does not change that modal. For action presentation extensions, subclass `ManageAiUserAction` and retain its inherited guards. For page extensions, `controlsPage()` accepts a concrete `AiControlsPage` subclass and `creditsPage()` a concrete `MyAiCreditsPage` subclass; unrelated/abstract classes are rejected and setters affect only that plugin instance. The topbar uses the configured credit page's URL. Preserve inherited access, mount/hydrate/context checks, Blade escaping and authorized public service calls when overriding behavior.

## Credits, deployment and recovery

Before applying migration `000003`, stop generation admissions and scheduled usage pruning, drain all old `pending`/`received` operations, then stop old generation workers. Publish/run the new migration and deploy the new writers before resuming admissions and pruning. Mixed-version rolling deployment is unsupported. The migration refuses unresolved legacy attempts and copies all remaining operation identities into durable tombstones without inventing historical scope/guard. Operation IDs already removed before upgrading cannot be reconstructed.

Credit state is stored in plugin-owned periods, actor settings, balances and operation records. Usage retention never restores credits or makes an operation ID reusable. These durable records are intentionally not pruned. Quotas default to off; there is no default business limit. Explicitly configure a valid integer limit and `manual`, `daily`, `weekly` or `monthly` renewal before enabling them:

```php
use PdroLucas\FilamentAiWriter\AiWriterPlugin;

$plugin = AiWriterPlugin::get();
$context = $plugin->controlContext(); // Resolve on the server, never from request input.
$credits = $plugin->credits();
$credits->configure($context, true, 100, 'monthly', 'Initial allocation');
// $selectedActor is an ActorIdentity re-resolved by the scoped directory.
$credits->setLimit($context, $selectedActor, 150, 'Individual allocation'); // null removes override
$credits->grant($context, $selectedActor, 20, 'Extra credits for this period');
$credits->reset($context, $selectedActor, 'Reset this user quota');
$credits->renew($context, 'Start a new period for the scope');
$adminBalance = $credits->balance($context, $selectedActor);
$myBalance = $credits->ownBalance($context); // No target actor; canUseAi, no admin/statistics permission.
```

Administration rechecks the current context, management permission and explicit actor directory. Every adjustment requires a reason and commits together with its actual before/after audit entry. `policy($context)` reads quota settings. `ownBalance` validates the fresh actor/panel/guard/scope snapshot and cannot read another user's balance. Balance arrays contain `enabled`, effective `limit`, `individual_limit`, `extras`, `consumed`, `reserved`, `available`, `period_id`, `balance_id` and UTC `renews_at` (null for manual). Quotas off is indicated by `enabled=false`; an unconfigured limit/available is null.

Limits and individual extra grants accept strict PHP integers, 0–2,147,483,647 (grants must be positive); a period's cumulative extras have the same technical bound. Counters use bigint and preserve historical consumption/reservations even when they exceed a reduced limit. Available is clamped at zero. Individual limits do not change the scope calendar. Extras and reset are different audited actions; reset starts a new balance without extras. Unused credits do not roll over. Day cuts are local midnight, weeks Monday midnight and months day 1 in the application timezone. A calendar edit preserves the current finite end; the new calendar takes effect when that period closes. Manual→calendar schedules the next cut of the new calendar while preserving the current balance until then.

Each generation freezes whether it reserves a credit. Enabling quotas during a free generation never adds retroactive charges; off/on in the same period preserves already counted consumption. Renewal/reset keeps in-flight reservations in their original balance; a valid late completion debits that balance, leaving the new period unchanged. Failures release once; delivery and debit commit together, with field restoration on rollback. Credits are not a financial cost ceiling.

The ledger's optional `connection` config must resolve to the **same connection instance** as the default controls/audit connection. A separate named connection is rejected even if it points at the same database, because separate transactions cannot guarantee atomic delivery. UsageRecorder also checks its actual query connection. Public `begin` and `delivered` reject outer transactions. `begin($generationContext)` is a ledger-only interface with durable deduplication and no credit admission; provider integrations must call `generation()->run()` to apply controls and quotas. Delivery uses the frozen operation scope/balance, without resolving the current tenant again.

Managed generation deadlines freeze the validated generation `timeout` plus `recovery.margin_seconds` (default **30 seconds**, configurable nonnegative integer) at admission. Positive timeout and margin accept integer configuration values or decimal integer strings up to 2,147,483,647 seconds; invalid configuration refuses admission. Changing settings later cannot extend an admitted operation. This execution deadline also fences quota-off generations. Admission, response receipt and delivery enforce it, even without a scheduler. A delivery callback that crosses the deadline is restored and not charged.

Run `php artisan ai-writer:recover-operations` to release expired reservations left by interrupted workers; the host may schedule it explicitly. Recovery and delivery compete for the same scope lock. Recovery marks a permanent terminal tombstone and releases the original reservation once; late workers cannot deliver or debit again. Expiry does not prove zero remote cost. The first known late response may update allowlisted metadata in an existing expired usage row without changing its terminal status; it never recreates pruned history. Direct ledger-only operations have no managed execution deadline. Outcome uncertainty is preserved separately from known HTTP status, IDs and measurements: a late HTTP 503 can retain outcome_unknown=true while preserving its measured usage.

## Abuse protection reference

Publish/run migration `000004` before deploying these writers, with admissions, recovery workers and pruning paused during the source/schema cutover. It adds protection policy fields and indexes/backfills actor keys in durable operations; it does not run automatically. Existing scoped operations remain eligible history. Legacy records without a known scope cannot be assigned retroactive scope. Mixed old/new writers are unsupported.

Frequency, concurrency and repeated-failure protection are independent, **off by default**, and apply with quotas off. The administrator supplies every enabled protection's thresholds; there are no default business limits. Configuration accepts partial updates, strict PHP booleans for flags and positive PHP integers up to 2,147,483,647 for counts/seconds. Invalid configuration fails closed; unknown fields and missing thresholds are rejected. Example values below are illustrative choices for the host's administrator:

```php
$abuse = $plugin->abuse();
$abuse->configure($context, [
    'rate_enabled' => true, 'rate_limit' => 10, 'rate_window_seconds' => 60,
    'concurrency_enabled' => true, 'concurrency_limit' => 2,
    'failure_enabled' => true, 'failure_limit' => 3,
    'failure_window_seconds' => 300, 'failure_pause_seconds' => 60,
], 'Initial protection policy');
$policy = $abuse->policy($context); // Management permission and fresh server context.
$mine = $abuse->ownStatus($context); // No target; canUseAi, no management/statistics permission.
$selected = $abuse->status($context, $selectedActor); // Management + fresh actor directory lookup.
$abuse->configure($context, ['rate_enabled' => false], 'Temporarily disable frequency enforcement');
```

Configuration changes and their real before/after `abuse_policy` audit event commit atomically, require a reason and revalidate management/context under the shared scope lock. The native Settings forms configure these protections; Activity renders the audit events and the personal credit page shows current status.

Frequency counts **admitted** managed calls with `started_at > now - rate_window_seconds`, including failures and recoveries. Its timestamp is recorded at admission under the lock. Rejected calls do not create operations, reserve credits or reach the provider. Repeated operation UUIDs remain rejected after usage pruning. Concurrency counts `pending`/`received` operations with a valid frozen lease. Inline recovery releases expired original reservations and fences late workers before evaluating a new admission. Recovery and protection evaluation use one decision instant captured after the scope lock, avoiding a gap where a lease is neither live nor a recorded failure. HTTP runs outside the transaction.

Failure events are terminal non-deliveries after managed admission (`failed` or `recovered`): provider/response errors, failed local delivery and expired execution. Each operation contributes at most once. Failed operations use `completed_at`; recovered operations use their frozen `deadline_at`, so delayed maintenance cannot create a fresh pause for an old expiry. Pre-admission refusals are excluded. Successful delivery **does not erase other recent failures**.

Failure protection blocks only when the number of failures with event time `> now - failure_window_seconds` reaches the threshold **and** `latest_failure + failure_pause_seconds` is still in the future. That pause end is finite: status reads and refused retries never extend it. At its end a new call is allowed even if the failure threshold is still present; a new failed call can start another pause. Falling below the threshold as the rolling window moves also releases the pause, potentially sooner. For example, failures at seconds 0 and 2 with threshold 2, window 100 and pause 10 block until second 12; a success between those failures does not change that result.

Enabling, disabling or editing protection preserves history, including calls/failures admitted while protection or quotas were off. Enabling can immediately enforce recent known events. Edits recalculate against the current window/threshold/pause: increasing the pause can block again for a recent failure; an event outside the current window never triggers a pause. Credit resets, renewal and usage retention do not remove this history. Durable operations/tombstones are intentionally not cleaned up in this stage. Availability/block/config changes affect new admissions; admitted calls retain their delivery rights and execution deadlines.

Status returns `policy`, `rate` (`count`, `retry_at`), `concurrency` (`count`, `lease_ends_at`), `failures` (`count`, `paused_until`, `retry_at`) and `refusal` (`rate`, `concurrency`, `failure_pause` or null). All timestamps are UTC; `failures.retry_at` accounts for both the finite pause and rolling window release. `concurrency.lease_ends_at` is the earliest lease deadline, not a promised completion time or a guaranteed admission time when more than one protection applies. Counts with an unconfigured window are zero. Status concerns abuse only; availability and credits have their own APIs. The own-status API reveals only the freshly resolved caller's scope/type/id, including opaque identities; it accepts no target actor. Distinct safe `GenerationFailure` messages describe each protection refusal.

## Persistence and audit

Scope keys and actor tuple keys are SHA-256 hashes used for compact, case-sensitive unique indexes; actor hashes encode the JSON tuple `[type, normalized id]` to avoid delimiter ambiguity. Original scope, type and ID remain stored. Changes create the scope row through a unique insert, lock that row, and read the current `before` state inside the transaction. Updating availability or a block and appending its audit event share the same database transaction. An audit failure rolls back the change, including first-row creation. Repeating an already applied state is a no-op and produces no duplicate audit event.

Audit records include the administrator type/ID, initiating panel/guard, scope, optional target actor, before/after state, UTC timestamp and reason. Prompts, generated text, provider keys and request payloads are never copied into the audit. Reasons should explain the administrative decision without including private content or secrets. History is scoped, authorized and paginated at 10, 25 or 50 records. The current automated suite exercises SQLite; row-lock/concurrency behavior on other database engines requires integration verification in the host's supported environment.
