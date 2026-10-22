<x-filament-panels::page>
  @include('filament-ai-writer::controls.styles')
  <div class="ai-controls" data-ai-controls="admin">
    <x-filament::tabs :contained="true" class="ai-controls-nav" label="AI administration">
      @foreach (['settings' => 'Settings', 'users' => 'Users', 'activity' => 'Activity'] as $key => $label)
        @continue($key === 'users' && !$hasUsersTab)
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
  </div>
  <x-filament-actions::modals />
</x-filament-panels::page>
