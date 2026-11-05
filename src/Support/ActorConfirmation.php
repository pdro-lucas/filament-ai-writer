<?php

namespace PdroLucas\FilamentAiWriter\Support;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use PdroLucas\FilamentAiWriter\AiWriterPlugin;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;
use PdroLucas\FilamentAiWriter\Controls\ControlContext;

/** Internal authenticated action boundary; no client plaintext can establish context. */
final class ActorConfirmation
{
  public static function seal(string $purpose, string $component, ControlContext $context, ActorIdentity $actor): string
  {
    AiWriterPlugin::get()->controlsService()->authorize($context);
    return Crypt::encryptString(json_encode([
      'version' => 1, 'purpose' => $purpose, 'component' => $component,
      'context' => $context->snapshot(), 'actor' => $actor->token(),
    ], JSON_THROW_ON_ERROR));
  }

  /** @return array{0: ControlContext, 1: ActorIdentity} */
  public static function open(mixed $envelope, string $purpose, string $component): array
  {
    $plugin = AiWriterPlugin::get();
    $context = $plugin->controlContext();
    $plugin->controlsService()->authorize($context);
    abort_unless(is_string($envelope) && strlen($envelope) <= 10000, 403);
    try {
      $payload = json_decode(Crypt::decryptString($envelope), true, 16, JSON_THROW_ON_ERROR);
    } catch (DecryptException|\JsonException) {
      abort(403);
    }
    abort_unless(is_array($payload) && ($payload['version'] ?? null) === 1
      && ($payload['purpose'] ?? null) === $purpose && ($payload['component'] ?? null) === $component
      && ($payload['context'] ?? null) === $context->snapshot() && is_string($payload['actor'] ?? null), 403);
    return [$context, self::resolve($context, fn (): ActorIdentity => ActorIdentity::fromToken($payload['actor']))];
  }
  /** Host integration failures are unavailable states, never a replacement for authorization. */
  public static function resolve(ControlContext $context, Closure $identity): ActorIdentity
  {
    $plugin = AiWriterPlugin::get();
    $plugin->controlsService()->authorize($context);
    try {
      $actor = $identity();
      if (!$actor instanceof ActorIdentity) {
        throw ValidationException::withMessages(['actor' => __('filament-ai-writer::controls.validation.actor_identity')]);
      }
      $resolved = $plugin->resolveActor($actor->token(), $context);
    } catch (Throwable $exception) {
      if ($exception instanceof ValidationException || $exception instanceof AuthorizationException
        || ($exception instanceof HttpException && in_array($exception->getStatusCode(), [401, 403], true))) {
        throw $exception;
      }
      throw ValidationException::withMessages(['actor' => __('filament-ai-writer::controls.validation.directory_unavailable')]);
    }
    if ($resolved === null) {
      throw ValidationException::withMessages(['actor' => __('filament-ai-writer::controls.validation.actor_gone')]);
    }
    return $resolved;
  }
}
