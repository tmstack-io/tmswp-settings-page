<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage\Tests\Unit;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Closure;
use PHPUnit\Framework\Attributes\DataProvider;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormRequest;
use TMSWP\Core\Kernel\DiscoveredComponent;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\PageRegistrar;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPageDefinition;
use TMSWP\SettingsPage\SettingsPageInitializer;
use TMSWP\SettingsPage\SettingsPages;
use TMSWP\SettingsPage\Tests\Support\BootsSettingsPagesKernel;
use TMSWP\Testing\UnitTestCase;

/**
 * SettingsPagesのKernelごとの登録と、メニューの登録。
 */
final class SettingsPageTest extends UnitTestCase
{
    use BootsSettingsPagesKernel;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
        SiteSettingsPageFixture::$definitionCalls = 0;
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        parent::tearDown();
    }

    public function testDefinitionDerivesTitlesFromSlug(): void
    {
        $definition = new SettingsPageDefinition(slug: 'site-settings');

        self::assertSame('Site Settings', $definition->pageTitle);
        self::assertSame('Site Settings', $definition->menuTitle);
    }

    /**
     * コンストラクタに渡したページは、bootではdefinition()を呼ばずに、admin_menuでメニューに登録する
     * (definition()の__()がinitより前に動かない)。
     */
    public function testConstructorPagesAreAddedOnAdminMenuWithoutCallingDefinitionAtBoot(): void
    {
        $adminMenu = null;
        Actions\expectAdded('admin_menu')->once()->whenHappen(static function (mixed $callback) use (&$adminMenu): void {
            $adminMenu = $callback;
        });

        $this->bootSettingsPagesKernel('Constructor', extensions: [new SettingsPages([SiteSettingsPageFixture::class])]);

        self::assertSame(0, SiteSettingsPageFixture::$definitionCalls);
        self::assertInstanceOf(Closure::class, $adminMenu);

        Functions\expect('add_submenu_page')->once()->andReturn('settings_page_site-settings');
        Actions\expectAdded('load-settings_page_site-settings')->once();

        $adminMenu();

        self::assertGreaterThan(0, SiteSettingsPageFixture::$definitionCalls);
    }

    /** 訳の読み込みは、bootではなくinit(priority 1)に予約する。 */
    public function testTranslationsAreLoadedOnInit(): void
    {
        $init = null;
        Actions\expectAdded('init')->whenHappen(static function (mixed $callback, int $priority) use (&$init): void {
            if ($priority === 1) {
                $init = $callback;
            }
        });

        $this->bootSettingsPagesKernel('Textdomain');

        self::assertInstanceOf(Closure::class, $init);

        Functions\when('determine_locale')->justReturn('ja');
        Functions\expect('load_textdomain')->once()->with('tmswp', \dirname(__DIR__, 2) . '/languages/tmswp-ja.mo')->andReturn(true);

        $init();
    }

    /** 1つのインスタンスを2つのKernelに渡すと、2つ目のKernelのbootが、debugに関係なく例外になる。 */
    #[DataProvider('debugModes')]
    public function testOneInstanceCannotBeRegisteredToTwoKernels(bool $debug): void
    {
        $extension = new SettingsPages();
        $app = $this->bootSettingsPagesKernel('Shared', extensions: [$extension]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("this SettingsPages instance is already registered to the kernel '{$app['namespace']}'");

        $this->bootSettingsPagesKernel('SharedOther', extensions: [$extension], debug: $debug);
    }

    /** @return iterable<string, array{bool}> */
    public static function debugModes(): iterable
    {
        yield 'debug' => [true];
        yield '本番' => [false];
    }

    /**
     * 同じ種類の拡張(SettingsPages)は、Kernelごとに別のインスタンスなら2つのKernelに登録できる。それぞれのKernelが
     * 自分のSettingsPagesを持ち、コンストラクタに渡したページを自分のadmin_menuでメニューに登録する。
     */
    #[DataProvider('debugModes')]
    public function testSettingsPagesCanBeRegisteredToEachKernel(bool $debug): void
    {
        $adminMenus = [];
        Actions\expectAdded('admin_menu')->twice()->whenHappen(static function (mixed $callback) use (&$adminMenus): void {
            $adminMenus[] = $callback;
        });
        $theme = $this->bootSettingsPagesKernel('EachTheme', extensions: [$themePages = new SettingsPages([SiteSettingsPageFixture::class])], debug: $debug);
        $plugin = $this->bootSettingsPagesKernel('EachPlugin', extensions: [$pluginPages = new SettingsPages([OtherSettingsPageFixture::class])], debug: $debug);

        self::assertSame($themePages, SettingsPages::forKernel($theme['kernel']));
        self::assertSame($pluginPages, SettingsPages::forKernel($plugin['kernel']));
        self::assertCount(2, $adminMenus);

        $slugs = [];
        Functions\expect('add_submenu_page')->twice()->andReturnUsing(static function (string $parent, string $pageTitle, string $menuTitle, string $capability, string $slug) use (&$slugs): string {
            $slugs[] = $slug;

            return 'settings_page_' . $slug;
        });

        foreach ($adminMenus as $adminMenu) {
            self::assertInstanceOf(Closure::class, $adminMenu);
            $adminMenu();
        }

        self::assertSame(['site-settings', 'other-settings'], $slugs);
    }

    public function testConstructorPageThatDoesNotExtendSettingsPageFailsTheBoot(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('settings page must extend SettingsPage: ' . self::class);

        $this->bootSettingsPagesKernel('NotAPage', extensions: [new SettingsPages([self::class])]);
    }

    public function testForKernelRejectsKernelWithoutSettingsPages(): void
    {
        $app = $this->bootSettingsPagesKernel('None', extensions: []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("SettingsPages is not registered to the kernel '{$app['namespace']}'; pass new SettingsPages() in the extensions of its KernelConfig");

        SettingsPages::forKernel($app['kernel']);
    }

    /** `#[AsSettingsPage]`のクラスの初期化は、KernelにSettingsPagesが無ければ例外になる(debugに関係なく)。 */
    public function testInitializerRequiresSettingsPagesOnTheKernel(): void
    {
        $app = $this->bootSettingsPagesKernel('Initializer', extensions: [], debug: false);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SettingsPages is not registered to the kernel');

        (new SettingsPageInitializer())->initialize(new DiscoveredComponent(SiteSettingsPageFixture::class, AsSettingsPage::class, []), $app['kernel']);
    }

    /** slugは、起動したすべてのKernelのSettingsPagesで一意でなければならない。 */
    public function testDuplicateSlugAcrossKernelsIsRejected(): void
    {
        Functions\when('add_submenu_page')->justReturn('settings_page_site-settings');
        $theme = $this->bootSettingsPagesKernel('DuplicateTheme');
        $plugin = $this->bootSettingsPagesKernel('DuplicatePlugin');
        SettingsPages::forKernel($theme['kernel'])->addPage(SiteSettingsPageFixture::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("duplicate settings page slug 'site-settings': " . SiteSettingsPageFixture::class . ', ' . DuplicateSiteSettingsPageFixture::class);

        SettingsPages::forKernel($plugin['kernel'])->addPage(DuplicateSiteSettingsPageFixture::class);
    }

    /** 同じKernelの中のslugの重複も例外になる。 */
    public function testDuplicateSlugWithinOneKernelIsRejected(): void
    {
        Functions\when('add_submenu_page')->justReturn('settings_page_site-settings');
        $app = $this->bootSettingsPagesKernel('DuplicateSame');
        $pages = SettingsPages::forKernel($app['kernel']);
        $pages->addPage(SiteSettingsPageFixture::class);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("duplicate settings page slug 'site-settings': " . SiteSettingsPageFixture::class . ', ' . DuplicateSiteSettingsPageFixture::class);

        $pages->addPage(DuplicateSiteSettingsPageFixture::class);
    }

    /**
     * add_submenu_page()は、capabilityを持たないユーザーではページを登録せずにfalseを返す。登録の失敗ではないため
     * 例外にせず、load-{hook_suffix}も接続しない。
     */
    public function testSubmenuPageWithoutCapabilityIsSkippedWithoutError(): void
    {
        Functions\expect('add_submenu_page')->once()->andReturn(false);
        Actions\expectAdded('load-settings_page_site-settings')->never();

        (new PageRegistrar(SiteSettingsPageFixture::class))->registerMenu();
    }

    /**
     * 確認画面の有効なフォームは、FWの保存ボタン(_step=submit)では保存できないため、handle()の前に設定の誤りとして
     * 例外にする(debugに関係なく)。
     */
    public function testFormWithTheConfirmationScreenIsRejectedBeforeHandle(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\expect('nocache_headers')->never();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("the form 'confirm_settings' of settings page " . ConfirmSettingsPageFixture::class . ' enables the confirmation screen, which settings pages do not support; remove confirm() from its FormDefinition');

        (new PageRegistrar(ConfirmSettingsPageFixture::class))->handleRequest();
    }

    public function testFormApplyDefaultsSurvivesGetHandle(): void
    {
        Functions\when('nocache_headers')->justReturn();
        // 設定ページは管理画面で処理する。管理画面では、Formは項目名を公開のクエリ変数と照らさない
        Functions\when('is_admin')->justReturn(true);
        $form = new Form(FormDefinition::create('site')->text('title', 'Title'));

        $form->applyDefaults(['title' => 'Configured']);
        $form->handle(new FormRequest('GET'));

        self::assertSame('Configured', $form->values()['title']);
    }
}

final class SiteSettingsPageFixture extends SettingsPage
{
    public static int $definitionCalls = 0;

    public static function definition(): SettingsPageDefinition
    {
        ++self::$definitionCalls;

        return new SettingsPageDefinition(slug: 'site-settings', parent: 'options-general.php');
    }

    protected function render(?Form $form): void {}
}

final class OtherSettingsPageFixture extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'other-settings', parent: 'options-general.php');
    }

    protected function render(?Form $form): void {}
}

final class DuplicateSiteSettingsPageFixture extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'site-settings', parent: 'options-general.php');
    }

    protected function render(?Form $form): void {}
}

final class ConfirmSettingsPageFixture extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'confirm-settings', parent: 'options-general.php');
    }

    protected function createForm(): Form
    {
        return new Form(FormDefinition::create('confirm_settings')->text('title', 'Title')->confirm());
    }

    protected function render(?Form $form): void {}
}
