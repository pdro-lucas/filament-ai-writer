<?php

namespace PdroLucas\FilamentAiWriter\Data;

use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use PdroLucas\FilamentAiWriter\Exceptions\GenerationFailure;
use PdroLucas\FilamentAiWriter\Support\ProviderResponse;

final class GenerationContext
{
  public readonly CarbonImmutable $startedAt;
  private readonly int $startedNanos;
  public readonly ?string $configuredProvider;
  public readonly ?string $requestedModel;

  public function __construct(
    public readonly string $operationId,
    public readonly string $panelId,
    public readonly string $actorType,
    public readonly string $actorId,
    public readonly string $field,
    public readonly ?string $actionName = null,
  ) {
    if (!Str::isUuid($operationId)) {
      throw new GenerationFailure('configuration');
    }
    foreach ([$panelId, $actorType, $actorId, $field, $actionName] as $value) {
      if ($value !== null && ($value === '' || strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value))) {
        throw new GenerationFailure('configuration');
      }
    }
    $provider = config('filament-ai-writer.provider');
    $this->configuredProvider = is_string($provider) && preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $provider) ? $provider : null;
    $this->requestedModel = $this->configuredProvider === null ? null : ProviderResponse::label(config('filament-ai-writer.' . $this->configuredProvider . '.model'));
    $this->startedAt = CarbonImmutable::now('UTC');
    $this->startedNanos = hrtime(true);
  }

  public static function capture(string $field, ?string $operationId, ?string $actionName): self
  {
    $panel = Filament::getCurrentPanel();
    $actor = $panel?->auth()->user();
    $id = $actor?->getAuthIdentifier();
    if ($panel === null || $actor === null || (!is_string($id) && !is_int($id))) {
      throw new GenerationFailure('configuration');
    }
    return new self($operationId ?? (string) Str::uuid(), $panel->getId(), $actor::class, (string) $id, $field, $actionName);
  }

  public function localDuration(int $providerDuration): int
  {
    return max(0, (int) round((hrtime(true) - $this->startedNanos) / 1_000_000) - $providerDuration);
  }
}
