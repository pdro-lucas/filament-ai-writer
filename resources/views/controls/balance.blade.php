{{ \Filament\Schemas\Schema::make($this)->components(\PdroLucas\FilamentAiWriter\Schemas\AiCreditStatus::components($balance, $abuse, ['enabled' => $enabled, 'blocked' => $blocked])) }}
