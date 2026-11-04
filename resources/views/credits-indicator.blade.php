<div data-ai-controls="credits-indicator">
  @if ($visible && $details === [])
    {{-- Credits unavailable or off: no popover, nothing to show beyond the state. --}}
    <x-filament::button size="sm" :color="$color" :icon="$icon" :tooltip="$tooltip" disabled :aria-label="$label . '. ' . $tooltip">
      {{ $label }}
    </x-filament::button>
  @elseif ($visible)
    <x-filament::dropdown placement="bottom-end" :teleport="true" :shift="true" width="xs">
      <x-slot name="trigger">
        <x-filament::button size="sm" :color="$color" :icon="$icon" :aria-label="$label . '. ' . $tooltip" aria-haspopup="true">
          {{ $label }}
        </x-filament::button>
      </x-slot>

      <x-filament::dropdown.header :icon="$icon">{{ __('filament-ai-writer::credits.popover.heading') }}</x-filament::dropdown.header>

      <x-filament::dropdown.list>
        @foreach ($details as $name => $value)
          <x-filament::dropdown.list.item tag="div">
            <span>{{ $name }}</span>: <strong>{{ $value }}</strong>
          </x-filament::dropdown.list.item>
        @endforeach
      </x-filament::dropdown.list>

      @if ($url !== null)
        <x-filament::dropdown.list>
          <x-filament::dropdown.list.item tag="a" :href="$url" :icon="\PdroLucas\FilamentAiWriter\Support\Icons::resolve(\PdroLucas\FilamentAiWriter\Support\Icons::INDICATOR_LINK)">{{ __('filament-ai-writer::credits.popover.view') }}</x-filament::dropdown.list.item>
        </x-filament::dropdown.list>
      @endif
    </x-filament::dropdown>
  @endif
</div>
