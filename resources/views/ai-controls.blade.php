<x-filament-panels::page>
  <x-filament::tabs :contained="true" label="AI administration" data-ai-controls="admin">
    @foreach ($sections as $key => $label)
      <x-filament::tabs.item :active="$section === $key" wire:click="showSection('{{ $key }}')">{{ $label }}</x-filament::tabs.item>
    @endforeach
  </x-filament::tabs>
  <div data-ai-controls="{{ $section }}" wire:key="ai-task-{{ $section }}">
    @if ($section === 'settings')
      @include('filament-ai-writer::controls.settings')
    @elseif ($section === 'users')
      @include('filament-ai-writer::controls.users')
    @elseif ($section === 'activity')
      {{ $this->table }}
    @endif
  </div>
  <x-filament-actions::modals />
</x-filament-panels::page>
