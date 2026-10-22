<x-filament-panels::page>
    <p>This demo uses a local HTTP fake. No request is sent to an AI provider.</p>

    <x-filament::section heading="Usage scenarios">
        <p>Generate normally for successful text with unavailable cost. Include <code>[demo:estimated]</code> for a host tariff estimate, <code>[demo:failure]</code> for a safe provider failure, or <code>[demo:truncated]</code> for incomplete output. Put the marker in the topic for silent generation or in the modal request for interactive generation. Failures preserve the original field.</p>
        @if (\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::canAccess())
            <x-filament::link :href="\PdroLucas\FilamentAiWriter\Pages\UsageStatisticsPage::getUrl()">View this panel's usage history</x-filament::link>
        @endif
    </x-filament::section>

    {{ $this->form }}
</x-filament-panels::page>
