<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

final readonly class SettingsPageDefinition
{
    public string $pageTitle;

    public string $menuTitle;

    public function __construct(
        public string $slug,
        ?string $pageTitle = null,
        ?string $menuTitle = null,
        public string $capability = 'manage_options',
        public ?string $parent = null,
        public ?string $icon = null,
        public ?int $position = null,
    ) {
        $this->pageTitle = $pageTitle ?? ucwords(str_replace(['-', '_'], ' ', $slug));
        $this->menuTitle = $menuTitle ?? $this->pageTitle;
    }
}
