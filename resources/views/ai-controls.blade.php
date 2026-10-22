<x-filament-panels::page>
  <x-filament::section heading="Availability">
    <div class="space-y-4">
      <p class="text-sm text-gray-700 dark:text-gray-200">{{ $enabled ? 'AI is enabled' : 'AI is disabled' }}.</p>
      <div class="flex flex-wrap gap-3">
        {{ $this->enableAction }}
        {{ $this->disableAction }}
      </div>
    </div>
  </x-filament::section>

  <x-filament::section heading="Individual access">
    <div class="space-y-4">
      @if ($hasDirectory)
        <p class="text-sm text-gray-700 dark:text-gray-200">Block or restore AI access for a user. Every change requires a reason.</p>
        <div class="flex flex-wrap gap-3">
          {{ $this->blockActorAction }}
          {{ $this->unblockActorAction }}
        </div>
      @else
        <p class="text-sm text-gray-700 dark:text-gray-200">User search is not configured. Contact your application administrator to manage individual access.</p>
      @endif
    </div>
  </x-filament::section>

  <x-filament::section heading="Audit history" description="Availability and individual access changes.">
    {{ $this->table }}
  </x-filament::section>
</x-filament-panels::page>
