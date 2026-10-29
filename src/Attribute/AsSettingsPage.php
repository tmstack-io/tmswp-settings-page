<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage\Attribute;

use Attribute;
use TMSWP\Core\Attribute\ComponentAttributeInterface;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\KernelContext;
use TMSWP\SettingsPage\SettingsPageInitializer;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AsSettingsPage implements ComponentAttributeInterface
{
    public function hookName(KernelContext $context): string
    {
        return 'admin_menu';
    }

    public function hookPriority(): int
    {
        return 10;
    }

    /** @return class-string<ComponentInitializerInterface> */
    public function initializerClass(): string
    {
        return SettingsPageInitializer::class;
    }
}
