<?php

declare(strict_types=1);

namespace ScottKeckWarren\Kanine\Config;

interface ConfigLoaderInterface
{
    public function load(?string $explicitPath = null, ?string $tokenOverride = null): Configuration;
}
