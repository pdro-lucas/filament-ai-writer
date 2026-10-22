<div data-ai-controls="credits-indicator">
  @if ($visible)
    <x-filament::button :tag="$url === null ? 'button' : 'a'" :href="$url" size="sm" color="gray" icon="heroicon-m-sparkles" :tooltip="$tooltip" :disabled="$url === null" :aria-label="$label . '. ' . $tooltip">
      {{ $label }}
    </x-filament::button>
  @endif
</div>
