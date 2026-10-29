<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;

/**
 * settings-pageの入口。`KernelConfig::theme()` / `plugin()`の`extensions`に渡し、Kernelごとに登録する。
 *
 * ```php
 * return KernelConfig::theme(__DIR__ . '/app', 'App', extensions: [new SettingsPages()]);
 * ```
 *
 * 登録したKernelのapp/にある`#[AsSettingsPage]`のクラス(属性探索)と、コンストラクタに渡したクラスを、admin_menuで
 * 管理画面のメニューに登録する。1つのインスタンスは1つのKernelにだけ登録できる。
 */
final class SettingsPages implements ExtensionInterface
{
    private ?Kernel $kernel = null;

    /** @var array<string, class-string<SettingsPage>> このインスタンスがメニューに登録したページ(slug => クラス) */
    private array $pagesBySlug = [];

    /**
     * WordPressの関数とページのdefinition()は呼ばない。tmswp-kernel.phpの中で、WordPressを起動しない経路からも
     * 生成されるためである。
     *
     * @param list<class-string> $pages 属性探索で見つからない(app/の外にある)設定ページのクラス。SettingsPageを継承する
     */
    public function __construct(private readonly array $pages = []) {}

    /**
     * 登録したKernelのboot中に1回呼ばれる。訳の読み込み(init 1)と、コンストラクタに渡したページのメニューへの登録
     * (admin_menu 10)を予約する。`#[AsSettingsPage]`のクラスは、KernelがSettingsPageInitializerで登録する。
     *
     * @throws LogicException このインスタンスを登録済みの場合、渡したクラスがSettingsPageを継承していない場合
     */
    public function register(Kernel $kernel): void
    {
        if ($this->kernel instanceof Kernel) {
            throw new LogicException("this SettingsPages instance is already registered to the kernel '{$this->kernel->config()->appNamespace}'; create one SettingsPages per kernel");
        }

        foreach ($this->pages as $pageClass) {
            self::assertSettingsPage($pageClass);
        }

        $this->kernel = $kernel;
        $kernel->defer('init', 1, static function (): void {
            load_textdomain('tmswp', \dirname(__DIR__) . '/languages/tmswp-' . determine_locale() . '.mo');
        });

        if ($this->pages !== []) {
            $kernel->defer('admin_menu', 10, function (): void {
                foreach ($this->pages as $pageClass) {
                    $this->addPage($pageClass);
                }
            });
        }
    }

    /**
     * Kernelに登録したSettingsPagesを返す。
     *
     * @throws LogicException そのKernelのextensionsにSettingsPagesが無い、または未登録の場合
     */
    public static function forKernel(Kernel $kernel): self
    {
        foreach ($kernel->config()->extensions as $extension) {
            if ($extension instanceof self && $extension->kernel === $kernel) {
                return $extension;
            }
        }

        throw new LogicException("SettingsPages is not registered to the kernel '{$kernel->config()->appNamespace}'; pass new SettingsPages() in the extensions of its KernelConfig");
    }

    /**
     * 設定ページを管理画面のメニューに登録する。slugは、起動したすべてのKernelのSettingsPagesで一意でなければならない
     * (WordPressの管理画面のページは、サイトで1つの名前空間を共有するため)。
     *
     * @internal admin_menuで、SettingsPageInitializer(属性探索のページ)とregister()(コンストラクタのページ)から呼ぶ
     *
     * @param string $pageClass 設定ページのクラス名
     *
     * @throws LogicException SettingsPageを継承していない場合、slugが登録済みの場合
     */
    public function addPage(string $pageClass): void
    {
        $pageClass = self::assertSettingsPage($pageClass);
        $slug = $pageClass::definition()->slug;

        foreach (Kernel::all() as $kernel) {
            foreach ($kernel->config()->extensions as $extension) {
                $registeredClass = $extension instanceof self ? ($extension->pagesBySlug[$slug] ?? null) : null;

                if ($registeredClass !== null) {
                    throw new LogicException("duplicate settings page slug '{$slug}': {$registeredClass}, {$pageClass}");
                }
            }
        }

        $this->pagesBySlug[$slug] = $pageClass;
        (new PageRegistrar($pageClass))->registerMenu();
    }

    /**
     * @throws LogicException SettingsPageを継承していない場合
     *
     * @return class-string<SettingsPage>
     */
    private static function assertSettingsPage(string $pageClass): string
    {
        if (!is_subclass_of($pageClass, SettingsPage::class)) {
            throw new LogicException("settings page must extend SettingsPage: {$pageClass}");
        }

        return $pageClass;
    }
}
