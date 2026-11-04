<?php

namespace PdroLucas\FilamentAiWriter\Livewire;

use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use PdroLucas\FilamentAiWriter\Support\HostDates;
use PdroLucas\FilamentAiWriter\Support\Icons;

class OwnCreditsIndicator extends Component
{
  #[Locked]
  public ?string $panelId = null;

  #[Locked]
  public ?array $contextSnapshot = null;

  public function mount(): void
  {
    $this->panelId = Filament::getCurrentPanel()?->getId();
    try {
      $panel = Filament::getCurrentPanel();
      if ($panel?->hasPlugin('ai-writer') && ($plugin = AiWriterPlugin::get())->isControlsEnabled() && $plugin->hasCreditsIndicator() && $plugin->canUseAi()) {
        $this->contextSnapshot = $plugin->controlContext()->snapshot();
      }
    } catch (Throwable) {
      // A missing initial context stays unavailable until the component is remounted.
    }
  }

  #[On('ai-writer-credits-changed')]
  public function refreshCredits(): void {}

  public function render(): View
  {
    $hidden = ['visible' => false, 'label' => '', 'tooltip' => '', 'url' => null, 'details' => [], 'icon' => Icons::resolve(Icons::INDICATOR), 'color' => 'gray'];
    $state = $hidden;
    try {
      $panel = Filament::getCurrentPanel();
      if ($panel === null || $panel->getId() !== $this->panelId || !$panel->hasPlugin('ai-writer')) {
        return view('filament-ai-writer::credits-indicator', $hidden);
      }
      $plugin = AiWriterPlugin::get();
      if (!$plugin->isControlsEnabled() || !$plugin->hasCreditsIndicator() || !$plugin->canUseAi()) {
        return view('filament-ai-writer::credits-indicator', $hidden);
      }
      $state = ['visible' => true, 'label' => __('filament-ai-writer::credits.indicator.unavailable'), 'tooltip' => __('filament-ai-writer::credits.indicator.unavailable_tooltip'), 'url' => null, 'details' => [],
        'icon' => $plugin->getCreditsIndicatorIcon(), 'color' => $plugin->getCreditsIndicatorColor()];
      if ($this->contextSnapshot === null) {
        return view('filament-ai-writer::credits-indicator', $state);
      }
      $context = $plugin->controlContext();
      if ($context->snapshot() !== $this->contextSnapshot) {
        return view('filament-ai-writer::credits-indicator', $hidden);
      }
      if (!$plugin->credits()->quotaEnabled($context)) {
        return view('filament-ai-writer::credits-indicator', $hidden);
      }
      // Reconcile this actor's expired operations before showing available credits.
      $plugin->abuse()->ownStatus($context);
      $balance = $plugin->credits()->ownBalance($context);
      if (!$balance['enabled']) {
        $state['label'] = __('filament-ai-writer::credits.indicator.unlimited');
        $state['tooltip'] = __('filament-ai-writer::credits.indicator.off');
      } elseif ($balance['available'] !== null) {
        $state['label'] = $plugin->getCreditsIndicatorLabel($balance) ?? __('filament-ai-writer::credits.indicator.label', ['count' => number_format($balance['available'])]);
        $state['tooltip'] = __('filament-ai-writer::credits.indicator.tooltip');
        // Own balance only: the popover reads the same target-free snapshot as the label, never another actor's.
        $state['details'] = [
          __('filament-ai-writer::credits.popover.available') => __('filament-ai-writer::credits.popover.credits', ['count' => number_format($balance['available'])]),
          __('filament-ai-writer::credits.popover.used') => __('filament-ai-writer::credits.popover.used_value', ['used' => number_format($balance['consumed']), 'limit' => number_format($balance['limit'])]),
          __('filament-ai-writer::credits.popover.renewal') => $balance['renews_at'] === null ? __('filament-ai-writer::credits.popover.manual') : HostDates::dateTime($balance['renews_at']),
        ];
      } else {
        return view('filament-ai-writer::credits-indicator', $state);
      }
      $page = $plugin->getCreditsPage();
      $state['url'] = $page === null ? null : $page::getUrl(panel: $panel->getId());
    } catch (AuthorizationException|HttpException) {
      $state = $hidden;
    } catch (Throwable) {
      if ($state['visible']) {
        $state = ['visible' => true, 'label' => __('filament-ai-writer::credits.indicator.unavailable'), 'tooltip' => __('filament-ai-writer::credits.indicator.unavailable_tooltip'), 'url' => null, 'details' => [],
          'icon' => $state['icon'], 'color' => $state['color']];
      }
    }
    return view('filament-ai-writer::credits-indicator', $state);
  }
}
