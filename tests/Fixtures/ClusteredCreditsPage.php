<?php

namespace PdroLucas\FilamentAiWriter\Tests\Fixtures;

use PdroLucas\FilamentAiWriter\Pages\MyAiCreditsPage;

class ClusteredCreditsPage extends MyAiCreditsPage
{
  protected static ?string $cluster = Clusters\NavigationCluster::class;
}
