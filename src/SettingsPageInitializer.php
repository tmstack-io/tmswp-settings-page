<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Kernel\ComponentInitializerInterface;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;

/**
 * `#[AsSettingsPage]`のクラスを、Kernelがadmin_menu(priority 10)で1件ずつ初期化する。
 */
final class SettingsPageInitializer implements ComponentInitializerInterface
{
    /**
     * 設定ページを、Kernelに登録したSettingsPagesでメニューに登録する。
     *
     * @throws LogicException KernelにSettingsPagesを登録していない場合(設定の誤り。Kernelはdebugに関係なく投げる)、
     *                        SettingsPageを継承していない場合、slugが登録済みの場合
     */
    public function initialize(DiscoveredComponent $component, Kernel $kernel): void
    {
        SettingsPages::forKernel($kernel)->addPage($component->class);
    }
}
