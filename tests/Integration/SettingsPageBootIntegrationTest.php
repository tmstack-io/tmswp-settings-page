<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage\Tests\Integration;

use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPages;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\UsesTempDirectories;

/**
 * SettingsPagesのコンストラクタに渡したページ(属性探索の対象でないページ)のdefinition()が呼ばれる時点を、実物の
 * WordPressのboot・init・admin_menuで確かめる。
 *
 * ページのdefinition()は、呼ばれるたびに、そのときのcurrent_filter()(フックの外ならnone)を
 * DEFINITION_CALLED_ACTIONのアクションで知らせる。
 */
final class SettingsPageBootIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const DEFINITION_CALLED_ACTION = 'tmswp_sp_boot_test/definition_called';

    private const MENU_GLOBALS = ['menu', 'submenu', '_parent_pages', '_registered_pages', 'admin_page_hooks', '_wp_submenu_nopriv', '_wp_menu_nopriv'];

    /** @var array<string, mixed> テストの前の管理画面のメニューのグローバル */
    private array $globalsBackup = [];

    /** @var list<string> definition()が呼ばれたときのcurrent_filter()(呼ばれた順) */
    private array $definitionCalls = [];

    /** @var list<string> _doing_it_wrong()の対象の関数名 */
    private array $doingItWrong = [];

    public function set_up(): void
    {
        parent::set_up();
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        foreach (self::MENU_GLOBALS as $name) {
            $this->globalsBackup[$name] = $GLOBALS[$name] ?? null;
            $GLOBALS[$name] = [];
        }
    }

    public function tear_down(): void
    {
        foreach (self::MENU_GLOBALS as $name) {
            $GLOBALS[$name] = $this->globalsBackup[$name];
        }

        $this->unregisterAppAutoloaders();
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * docs/08-extensions.mdの「コンストラクタに渡したページのdefinition()が、bootでは呼ばれずadmin_menu(initの後)で
     * 呼ばれる」。
     *
     * プラグインのKernelをinitより前(本体ファイルの読み込み時)にbootし、initを発火し、管理者でadmin_menuを発火する。
     * definition()はbootとinitでは呼ばれず、admin_menuで呼ばれてメニューに登録される。訳の早すぎる読み込みの
     * 通知(_load_textdomain_just_in_timeの_doing_it_wrong)も出ない。
     */
    public function testConstructorPageDefinitionIsReadOnAdminMenuNotAtBootOrInit(): void
    {
        $root = $this->createTempDirectory('integration-settings-page-boot');
        $namespace = 'TmswpIntegrationSettingsPageBoot' . uniqid();
        $slug = 'tmswp-sp-boot-' . substr(md5($namespace), 0, 8);
        $action = self::DEFINITION_CALLED_ACTION;
        // 属性を付けないページ。app/に置くが、属性探索では見つからず、コンストラクタで渡す
        $this->writeFile($root . '/app/Admin/ConstructorPage.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$namespace}\\Admin;

use TMSWP\\Core\\Form\\Form;
use TMSWP\\SettingsPage\\SettingsPage;
use TMSWP\\SettingsPage\\SettingsPageDefinition;

final class ConstructorPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        do_action('{$action}', current_filter() === false ? 'none' : current_filter());

        return new SettingsPageDefinition(slug: '{$slug}', pageTitle: 'Constructor page');
    }

    protected function render(?Form \$form): void
    {
    }
}
PHP);
        $this->writeFile($root . '/plugin.php', "<?php\n");
        require_once $root . '/app/Admin/ConstructorPage.php';
        $pageClass = self::fixtureClass($namespace . '\\Admin\\ConstructorPage', SettingsPage::class);
        $config = KernelConfig::plugin($root . '/plugin.php', $root . '/app', $namespace, true, [new SettingsPages([$pageClass])]);
        $this->registerAppAutoloader($config);

        add_action(self::DEFINITION_CALLED_ACTION, function (string $filter): void {
            $this->definitionCalls[] = $filter;
        }, 10, 1);
        add_action('doing_it_wrong_run', function (string $function): void {
            $this->doingItWrong[] = $function;
        }, 10, 1);

        $before = self::registeredCallbacks();
        $initCallbacks = $this->bootBeforeInit($config);

        self::assertSame([], $this->definitionCalls());
        // admin_menuはinitの後に発火するため、bootではadd_actionで予約している
        self::assertContains('admin_menu', array_column(array_diff_key(self::registeredCallbacks(), $before), 'hook'));

        self::fireInit($initCallbacks);

        self::assertSame([], $this->definitionCalls());
        $registeredBeforeAdminMenu = $GLOBALS['_registered_pages'];
        self::assertIsArray($registeredBeforeAdminMenu);
        self::assertArrayNotHasKey('toplevel_page_' . $slug, $registeredBeforeAdminMenu);

        $userId = self::factory()->user->create(['role' => 'administrator']);
        self::assertIsInt($userId);
        wp_set_current_user($userId);
        do_action('admin_menu');

        self::assertNotSame([], $this->definitionCalls());
        self::assertSame(['admin_menu'], array_values(array_unique($this->definitionCalls())));
        $registeredPages = $GLOBALS['_registered_pages'];
        self::assertIsArray($registeredPages);
        self::assertArrayHasKey('toplevel_page_' . $slug, $registeredPages);
        self::assertSame([], $this->doingItWrong);
    }

    /**
     * definition()が呼ばれたときのcurrent_filter()の記録。フックの発火で増えるため、PHPStanがプロパティの型を
     * 直前の確認の結果に絞り込まないよう、メソッドで読む。
     *
     * @return list<string>
     */
    private function definitionCalls(): array
    {
        return $this->definitionCalls;
    }
}
