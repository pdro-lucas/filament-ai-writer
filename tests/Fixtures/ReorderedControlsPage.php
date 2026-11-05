<?php

namespace PdroLucas\FilamentAiWriter\Tests\Fixtures;

use PdroLucas\FilamentAiWriter\Pages\AiControlsPage;

/** A host page that lists Activity first and drops Users. */
class ReorderedControlsPage extends AiControlsPage
{
  protected function sections(): array
  {
    return ['activity' => 'Activity', 'settings' => 'Settings'];
  }
}
