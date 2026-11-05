<?php

namespace PdroLucas\FilamentAiWriter\Tests\Fixtures;

use Filament\Pages\Page;
use PdroLucas\FilamentAiWriter\Actions\ManageAiUserAction;
use PdroLucas\FilamentAiWriter\Controls\ActorIdentity;

/** Host surface deliberately independent of the plugin administration page. */
class HostAiUserPage extends Page
{
  protected string $view = 'ai-controls-tests::host-ai-user';
  public string $actorId = 'first';

  public function manageAiUserAction(): ManageAiUserAction
  {
    return ManageAiUserAction::make()->actorUsing(function (): ActorIdentity {
      if (config('testing.host_actor_error') === 'runtime') { throw new \RuntimeException('Host directory offline'); }
      if (config('testing.host_actor_error') === 'authorization') { throw new \Illuminate\Auth\Access\AuthorizationException(); }
      if (config('testing.host_actor_error') === 'http') { abort(403); }
      if (config('testing.host_actor_error') === 'http-server') { abort(500); }
      return new ActorIdentity('HostPerson', $this->actorId, 'Client label is not authority');
    });
  }
}
