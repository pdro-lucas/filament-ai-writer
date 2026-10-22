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
      if ($panel?->hasPlugin('ai-writer') && ($plugin = AiWriterPlugin::get())->hasCreditsInTopbar() && $plugin->canUseAi()) {
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
    $hidden = ['visible' => false, 'label' => '', 'tooltip' => '', 'url' => null];
    $state = $hidden;
    try {
      $panel = Filament::getCurrentPanel();
      if ($panel === null || $panel->getId() !== $this->panelId || !$panel->hasPlugin('ai-writer')) {
        return view('filament-ai-writer::credits-indicator', $hidden);
      }
      $plugin = AiWriterPlugin::get();
      if (!$plugin->hasCreditsInTopbar() || !$plugin->canUseAi()) {
        return view('filament-ai-writer::credits-indicator', $hidden);
      }
      $state = ['visible' => true, 'label' => 'Credits unavailable', 'tooltip' => 'Credits unavailable. Try again later.', 'url' => null];
      if ($this->contextSnapshot === null) {
        return view('filament-ai-writer::credits-indicator', $state);
      }
      $context = $plugin->controlContext();
      if ($context->snapshot() !== $this->contextSnapshot) {
        return view('filament-ai-writer::credits-indicator', $hidden);
      }
      // Reconcile this actor's expired operations before showing available credits.
      $plugin->abuse()->ownStatus($context);
      $balance = $plugin->credits()->ownBalance($context);
      if (!$balance['enabled']) {
        $state['label'] = 'Unlimited';
        $state['tooltip'] = 'Credits are off';
      } elseif ($balance['available'] !== null) {
        $state['label'] = number_format($balance['available']) . ' credits';
        $state['tooltip'] = 'Your available AI credits';
      } else {
        return view('filament-ai-writer::credits-indicator', $state);
      }
      $page = $plugin->getCreditsPage();
      $state['url'] = $page::getUrl(panel: $panel->getId());
    } catch (AuthorizationException|HttpException) {
      $state = $hidden;
    } catch (Throwable) {
      if ($state['visible']) {
        $state = ['visible' => true, 'label' => 'Credits unavailable', 'tooltip' => 'Credits unavailable. Try again later.', 'url' => null];
      }
    }
    return view('filament-ai-writer::credits-indicator', $state);
  }
}
