<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage\Tests\Integration;

use RuntimeException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\SettingsPage\PageRegistrar;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPages;
use TMSWP\Testing\BootsKernelBeforeInit;
use TMSWP\Testing\IntegrationTestCase;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UsesTempDirectories;
use WPDieException;

/**
 * 一時ディレクトリのプラグインのKernel(KernelConfigのextensionsにSettingsPagesを登録し、app/に`#[AsSettingsPage]`の
 * ページとオプショングループを置いたもの)で、管理画面のメニューの登録・権限・フォームの外枠と通知・オプションの
 * 既定値と保存を、実際のWordPressで確かめる。
 *
 * ページ:
 * - 設定の下のページ(slug tmswp-sp-general、manage_options)。site_name(必須)・max_items(int)・enabled(bool)・
 *   channels(array)の項目を、同じ名前のオプションと結ぶ
 * - トップレベルのフォームの無いページ(slug tmswp-sp-tools、manage_options)
 * - トップレベルのフォームのあるページ(slug tmswp-sp-top、manage_options)。add_menu_page()は権限で登録を止めないため、
 *   capabilityを持たないユーザーのPOSTがhandleRequest()に届く
 *
 * フォームのあるページのonSubmit()は、`tmswp_sp_test_submitted`のアクションを発火する(onSubmit()が呼ばれたかを
 * did_action()で数えるため)。
 */
final class SettingsPageIntegrationTest extends IntegrationTestCase
{
    use BootsKernelBeforeInit;
    use UsesTempDirectories;

    private const SLUG = 'tmswp-sp-general';

    private const SUBMENU_HOOK = 'settings_page_tmswp-sp-general';

    private const TOOLS_HOOK = 'toplevel_page_tmswp-sp-tools';

    private const TOP_HOOK = 'toplevel_page_tmswp-sp-top';

    private const SUBMITTED_ACTION = 'tmswp_sp_test_submitted';

    private const PREFIX = 'tmswp_sp_';

    private const MENU_GLOBALS = ['menu', 'submenu', '_parent_pages', '_registered_pages', 'admin_page_hooks', '_wp_submenu_nopriv', '_wp_menu_nopriv'];

    private const OPTION_GROUP = <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace}\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;

#[AsOptionGroup(prefix: 'tmswp_sp_')]
enum SiteOption: string
{
    #[OptionSchema(MetaValueType::String, default: 'Example')]
    case SiteName = 'site_name';

    #[OptionSchema(MetaValueType::Integer, default: 10)]
    case MaxItems = 'max_items';

    #[OptionSchema(MetaValueType::Boolean, default: false)]
    case Enabled = 'enabled';

    #[OptionSchema(MetaValueType::Array, default: [])]
    case Channels = 'channels';
}
PHP;

    private const GENERAL_PAGE = <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace}\SettingsPage;

use {namespace}\Option\SiteOption;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\OptionFormBridge;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPageDefinition;

#[AsSettingsPage]
final class GeneralSettingsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'tmswp-sp-general', pageTitle: 'General settings', parent: 'options-general.php');
    }

    protected function createForm(): ?Form
    {
        $form = new Form(
            FormDefinition::create('tmswp_sp_general')
                ->text('site_name', 'Site name', [new Required()])
                ->text('max_items', 'Max items')
                ->checkbox('enabled', 'Enabled')
                ->checkboxes('channels', 'Channels', ['mail' => 'Mail', 'sms' => 'SMS']),
        );
        OptionFormBridge::applyDefaults($form, SiteOption::class);

        return $form;
    }

    protected function onSubmit(FormResult $result): void
    {
        do_action('tmswp_sp_test_submitted');
        OptionFormBridge::persist($result, SiteOption::class);
    }

    protected function render(?Form $form): void
    {
        if ($form === null) {
            return;
        }

        echo $form->field('site_name')->input();
        echo $form->field('site_name')->errors();
        echo $form->field('max_items')->input();
        echo $form->field('enabled')->checkbox();
        echo $form->field('channels')->checkboxes();
    }
}
PHP;

    private const TOOLS_PAGE = <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace}\SettingsPage;

use TMSWP\Core\Form\Form;
use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPageDefinition;

#[AsSettingsPage]
final class ToolsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'tmswp-sp-tools', pageTitle: 'Tools');
    }

    protected function render(?Form $form): void
    {
        echo '<p class="tmswp-sp-tools">tools</p>';
    }
}
PHP;

    private const TOP_FORM_PAGE = <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace}\SettingsPage;

use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormResult;
use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPageDefinition;

#[AsSettingsPage]
final class TopFormPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'tmswp-sp-top', pageTitle: 'Top settings');
    }

    protected function createForm(): ?Form
    {
        return new Form(FormDefinition::create('tmswp_sp_top')->text('note', 'Note'));
    }

    protected function onSubmit(FormResult $result): void
    {
        do_action('tmswp_sp_test_submitted');
    }

    protected function render(?Form $form): void
    {
        if ($form !== null) {
            echo $form->field('note')->input();
        }
    }
}
PHP;

    /** @var array<string, mixed> テストの前のスーパーグローバルと管理画面のメニューのグローバル */
    private array $globalsBackup = [];

    private string $namespace = '';

    public function set_up(): void
    {
        parent::set_up();
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        $this->globalsBackup = ['_GET' => $_GET, '_POST' => $_POST, '_SERVER' => $_SERVER];

        foreach (self::MENU_GLOBALS as $name) {
            $this->globalsBackup[$name] = $GLOBALS[$name] ?? null;
            $GLOBALS[$name] = [];
        }

        // wp-admin/menu.phpが設定のメニューに付ける名前。設定の下のページのhook_suffix(settings_page_{slug})がこれで決まる
        $GLOBALS['admin_page_hooks'] = ['options-general.php' => 'settings'];
    }

    public function tear_down(): void
    {
        $_GET = $this->globalsBackup['_GET'];
        $_POST = $this->globalsBackup['_POST'];
        $_SERVER = $this->globalsBackup['_SERVER'];

        foreach (self::MENU_GLOBALS as $name) {
            $GLOBALS[$name] = $this->globalsBackup[$name];
        }

        $this->unregisterAppAutoloaders();
        $this->cleanupTempDirectories();
        parent::tear_down();
    }

    /**
     * capabilityを持たないユーザー(編集者)で管理画面のメニューを作っても例外にならない(A6-01)。設定の下のページは
     * add_submenu_page()が登録しないため、load-{hook_suffix}も接続しない。
     */
    public function testAdminMenuForUserWithoutCapabilityDoesNotFail(): void
    {
        $this->bootApp();
        $this->loginAs('editor');

        do_action('admin_menu');

        $parentPages = $GLOBALS['_parent_pages'];
        self::assertIsArray($parentPages);
        self::assertArrayNotHasKey(self::SLUG, $parentPages);
        self::assertFalse(has_action('load-' . self::SUBMENU_HOOK));
        self::assertNotFalse(has_action('load-' . self::TOOLS_HOOK));
    }

    /** トップレベルのページ(add_menu_page()は権限で登録を止めない)をcapabilityの無いユーザーが開くと、403で拒否する。 */
    public function testUserWithoutCapabilityIsRejectedWith403(): void
    {
        $this->bootApp();
        $this->loginAs('editor');
        do_action('admin_menu');

        try {
            do_action('load-' . self::TOOLS_HOOK);
            self::fail('権限の無いユーザーが拒否されませんでした');
        } catch (WPDieException $exception) {
            self::assertSame(403, $exception->getCode());
            self::assertSame('Sorry, you are not allowed to access this page.', $exception->getMessage());
        }
    }

    /**
     * 管理者の初期表示: FWがwrap・見出しとフォームの外枠(開始タグと必須のhidden・`_step=submit`の保存ボタン・終了タグ)を
     * 出し、その中にrender()の項目が入る。int・boolのオプションのスキーマの既定値が、項目の既定値になる。
     */
    public function testFrameworkRendersTheFormFrameAroundTheFieldsWithIntAndBoolDefaults(): void
    {
        $this->bootApp();
        $this->loginAsAdministrator();

        $html = $this->requestPage('GET');

        self::assertMatchesRegularExpression(
            '#^<div class="wrap"><h1>General settings</h1><form method="post" action="">.*name="_nonce".*name="_submit_token".*name="site_name".*</p></form></div>$#s',
            $html,
        );
        self::assertStringContainsString('<p class="submit"><button type="submit" name="' . Form::FIELD_STEP . '" value="submit" class="button button-primary">Save Changes</button></p></form>', $html);
        self::assertStringContainsString('name="site_name" value="Example"', $html);
        self::assertStringContainsString('name="max_items" value="10"', $html);
        self::assertDoesNotMatchRegularExpression('#name="enabled"[^>]*checked#', $html);
        self::assertStringNotContainsString('notice', $html);
    }

    /** フォームの無いページは、wrapと見出しの中にrender()だけを出す。 */
    public function testPageWithoutFormRendersOnlyTheBody(): void
    {
        $this->bootApp();
        $this->loginAsAdministrator();

        $html = $this->requestPage('GET', hook: self::TOOLS_HOOK);

        self::assertSame('<div class="wrap"><h1>Tools</h1><p class="tmswp-sp-tools">tools</p></div>', $html);
    }

    /**
     * 送信が完了したら、int・bool・arrayのオプションをスキーマの型で保存し、保存の完了を示すURL(エスケープしていない
     * 生のURL)へリダイレクトする。リダイレクト先で成功の通知を出し、保存値(optionsテーブルを経た文字列の形を含む)を
     * 項目の既定値にする。
     */
    public function testSubmitSavesIntBoolAndArrayOptionsAndRedirectsToTheUpdatedPage(): void
    {
        $this->bootApp();
        $this->loginAsAdministrator();
        $hidden = self::hiddenValues($this->requestPage('GET'));

        $location = $this->submitExpectingRedirect([...$hidden, 'site_name' => 'Shop', 'max_items' => '42', 'enabled' => '1', 'channels' => ['mail']]);

        self::assertSame(admin_url('options-general.php?page=' . self::SLUG . '&' . PageRegistrar::SAVED_QUERY_ARG . '=1'), $location);
        self::assertSame('Shop', get_option(self::PREFIX . 'site_name'));
        self::assertSame(42, get_option(self::PREFIX . 'max_items'));
        self::assertTrue(get_option(self::PREFIX . 'enabled'));
        self::assertSame(['mail'], get_option(self::PREFIX . 'channels'));

        // optionsテーブルから読み直すと、intとboolは文字列('42'・'1')になる
        wp_cache_flush();
        self::assertSame('42', get_option(self::PREFIX . 'max_items'));
        self::assertSame('1', get_option(self::PREFIX . 'enabled'));

        $html = $this->requestPage('GET', ['page' => self::SLUG, PageRegistrar::SAVED_QUERY_ARG => '1']);

        self::assertStringContainsString('<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>', $html);
        self::assertStringContainsString('name="max_items" value="42"', $html);
        self::assertMatchesRegularExpression('#name="enabled"[^>]*checked#', $html);
        self::assertMatchesRegularExpression('#value="mail"[^>]*checked#', $html);
        self::assertDoesNotMatchRegularExpression('#value="sms"[^>]*checked#', $html);
    }

    /**
     * 保存の後のURLでは、親がoptions-general.phpのページでもトップレベルのページでも、「Settings saved.」がちょうど1つ
     * 出る。WordPress本体の管理画面の描画(wp-admin/admin-header.php)は、親がoptions-general.phpのページでだけ
     * options-head.php(`updated`と`page`があれば「Settings saved.」を出す)を読むため、そのページでは本体の出力も合わせて
     * 数える。保存の後のクエリ変数は、表示の後にWordPressがURLから除く(removable_query_args)。
     */
    public function testSavedNoticeIsShownExactlyOnceUnderEveryParent(): void
    {
        $this->bootApp();
        $this->loginAsAdministrator();
        $pages = [
            'options-general.php' => [self::SLUG, self::SUBMENU_HOOK, ['site_name' => 'Shop', 'max_items' => '3']],
            'トップレベル' => ['tmswp-sp-top', self::TOP_HOOK, ['note' => 'x']],
        ];

        foreach ($pages as $parent => [$slug, $hook, $values]) {
            $hidden = self::hiddenValues($this->requestPage('GET', ['page' => $slug], hook: $hook));
            $location = $this->submitExpectingRedirect([...$hidden, ...$values], $hook, $slug);
            parse_str((string) wp_parse_url($location, PHP_URL_QUERY), $query);
            self::assertSame(['page' => $slug, PageRegistrar::SAVED_QUERY_ARG => '1'], $query, $parent);

            $html = $this->requestPage('GET', ['page' => $slug, PageRegistrar::SAVED_QUERY_ARG => '1'], hook: $hook);

            if ($parent === 'options-general.php') {
                $html .= self::optionsHead();
            }

            self::assertSame(1, substr_count($html, 'Settings saved.'), $parent);
        }

        self::assertContains(PageRegistrar::SAVED_QUERY_ARG, wp_removable_query_args());
    }

    /** falseのboolのオプションも保存でき、読み直した''を未チェックの既定値にする。 */
    public function testUncheckedCheckboxSavesFalse(): void
    {
        $this->bootApp();
        $this->loginAsAdministrator();
        update_option(self::PREFIX . 'enabled', true);
        $hidden = self::hiddenValues($this->requestPage('GET'));

        $this->submitExpectingRedirect([...$hidden, 'site_name' => 'Shop', 'max_items' => '3']);

        self::assertFalse(get_option(self::PREFIX . 'enabled'));
        self::assertSame([], get_option(self::PREFIX . 'channels'));
        wp_cache_flush();
        self::assertDoesNotMatchRegularExpression('#name="enabled"[^>]*checked#', $this->requestPage('GET'));
    }

    /** 値の検証のエラーは、保存せずにエラーの通知を出す。保存後のURL(PageRegistrar::SAVED_QUERY_ARG=1)のままの送信でも成功の通知を出さない。 */
    public function testValidationErrorsAreShownAsAnErrorNotice(): void
    {
        $this->bootApp();
        $this->loginAsAdministrator();
        $hidden = self::hiddenValues($this->requestPage('GET'));

        $html = $this->requestPage('POST', ['page' => self::SLUG, PageRegistrar::SAVED_QUERY_ARG => '1'], [...$hidden, Form::FIELD_STEP => 'submit', 'site_name' => '', 'max_items' => '5']);

        self::assertStringContainsString('<div class="notice notice-error"><p>The settings were not saved. Please check the errors in the fields.</p></div>', $html);
        self::assertStringContainsString('Site name is required.', $html);
        self::assertStringNotContainsString('notice-success', $html);
        self::assertFalse(get_option(self::PREFIX . 'max_items'));
    }

    /** CSRFの検証の失敗(Formのフォームエラー)は、エラーの通知に出す。 */
    public function testFormErrorsAreShownAsErrorNotices(): void
    {
        $this->bootApp();
        $this->loginAsAdministrator();
        $hidden = self::hiddenValues($this->requestPage('GET'));

        $html = $this->requestPage('POST', post: [...$hidden, Form::FIELD_NONCE => 'invalid', Form::FIELD_STEP => 'submit', 'site_name' => 'Shop']);

        self::assertStringContainsString('<div class="notice notice-error"><p>Your session has expired. Please submit the form again.</p></div>', $html);
        self::assertFalse(get_option(self::PREFIX . 'site_name'));
    }

    /** スキーマの型に変換できない送信の値(整数の項目に整数以外を許した場合)は、保存せずに例外にする。 */
    public function testSubmittedValueThatDoesNotMatchTheSchemaTypeIsAnException(): void
    {
        $this->bootApp();
        $this->loginAsAdministrator();
        $hidden = self::hiddenValues($this->requestPage('GET'));

        try {
            $this->requestPage('POST', post: [...$hidden, Form::FIELD_STEP => 'submit', 'site_name' => 'Shop', 'max_items' => 'many']);
            self::fail('型に合わない値が例外になりませんでした');
        } catch (LogicException $exception) {
            self::assertStringStartsWith("the submitted value of field 'max_items' (string) cannot be converted to the integer type of option 'max_items'", $exception->getMessage());
        }

        self::assertFalse(get_option(self::PREFIX . 'max_items'));
    }

    /** url()はesc_url()していない生のURLを返す(menu_page_url()と同じ規則)。 */
    public function testUrlReturnsTheRawAdminUrl(): void
    {
        $this->bootApp();
        $this->loginAsAdministrator();
        do_action('admin_menu');

        self::assertSame(admin_url('options-general.php?page=' . self::SLUG . '&updated=1'), $this->page('GeneralSettingsPage')->url(['updated' => '1']));
        self::assertSame(admin_url('admin.php?page=tmswp-sp-tools'), $this->page('ToolsPage')->url());
    }

    /** ロケールがjaなら、init(priority 1)で読み込むja訳でFWの文言が日本語になる。 */
    public function testJapaneseTranslationsAreLoadedOnInit(): void
    {
        $callbacks = $this->bootApp();
        $locale = static fn(): string => 'ja';
        add_filter('pre_determine_locale', $locale);

        try {
            self::fireInit($callbacks);

            self::assertSame('設定を保存しました。', __('Settings saved.', 'tmswp'));
            self::assertSame('変更を保存', __('Save Changes', 'tmswp'));
            self::assertSame('このページにアクセスする権限がありません。', __('Sorry, you are not allowed to access this page.', 'tmswp'));
            self::assertSame('設定を保存できませんでした。項目のエラーを確認してください。', __('The settings were not saved. Please check the errors in the fields.', 'tmswp'));
        } finally {
            remove_filter('pre_determine_locale', $locale);
            unload_textdomain('tmswp');
        }
    }

    /**
     * CSRFの検証(nonce)を通らないPOSTは、onSubmit()を呼ばず、保存もリダイレクトもせずに、セッション切れのエラーの
     * 通知を出す。nonceの欠落・空・改ざん・別のフォームのnonce・別のユーザー(同じ権限の管理者)のnonceのどれでも同じ。
     * nonce以外の送信の値(送信トークン・項目)は正しい。
     */
    public function testPostWithoutValidNonceDoesNotCallOnSubmit(): void
    {
        $this->bootApp();
        $this->loginAsAdministrator();
        $hidden = self::hiddenValues($this->requestPage('GET'));
        $valid = [...$hidden, Form::FIELD_STEP => 'submit', 'site_name' => 'Shop', 'max_items' => '5'];
        $withoutNonce = $valid;
        unset($withoutNonce[Form::FIELD_NONCE]);
        $nonce = $hidden[Form::FIELD_NONCE];
        $cases = [
            'nonceが無い' => $withoutNonce,
            'nonceが空' => [...$valid, Form::FIELD_NONCE => ''],
            'nonceの改ざん' => [...$valid, Form::FIELD_NONCE => substr($nonce, 0, -1) . (str_ends_with($nonce, 'a') ? 'b' : 'a')],
            '別のフォームのnonce' => [...$valid, Form::FIELD_NONCE => wp_create_nonce('tmswp_form_tmswp_sp_top')],
        ];
        // CSRFの検証を通ってしまった場合に、保存の後のexitへ進ませない
        add_filter('wp_redirect', [SettingsPageRedirected::class, 'stop'], 10, 2);

        try {
            foreach ($cases as $case => $post) {
                $this->assertRejectedByCsrfCheck($post, $case);
            }

            $this->loginAsAdministrator();
            $this->assertRejectedByCsrfCheck($valid, '別のユーザーのnonce');
        } finally {
            remove_filter('wp_redirect', [SettingsPageRedirected::class, 'stop'], 10);
        }
    }

    /**
     * capabilityを持たないユーザーは、フォームのあるページの表示(GET)も送信(POST)も、handleRequest()のwp_die()の403で
     * 拒否され、onSubmit()は呼ばれない。送信の値(そのユーザーのnonceと送信トークン)は正しく、同じ値でもcapabilityを
     * 持てば保存できる(拒否の理由がcapabilityだけである)。
     */
    public function testUserWithoutCapabilityIsRejectedOnGetAndPost(): void
    {
        $this->bootApp();
        $this->loginAs('editor');
        $grant = static fn(array $allcaps): array => [...$allcaps, 'manage_options' => true];
        $submitted = did_action(self::SUBMITTED_ACTION);

        // capabilityを持つ間に、メニューを作って(描画のコールバックを接続し)、このユーザーのnonceと送信トークンを得る
        add_filter('user_has_cap', $grant);

        try {
            do_action('admin_menu');
            $hidden = self::hiddenValues($this->requestPage('GET', ['page' => 'tmswp-sp-top'], hook: self::TOP_HOOK));
        } finally {
            remove_filter('user_has_cap', $grant);
        }

        $post = [...$hidden, Form::FIELD_STEP => 'submit', 'note' => 'x'];
        add_filter('wp_redirect', [SettingsPageRedirected::class, 'stop'], 10, 2);

        try {
            foreach (['GET' => [], 'POST' => $post] as $method => $body) {
                try {
                    $this->requestPage($method, ['page' => 'tmswp-sp-top'], $body, self::TOP_HOOK);
                    self::fail("{$method}: 権限の無いユーザーが拒否されませんでした");
                } catch (WPDieException $exception) {
                    self::assertSame(403, $exception->getCode(), $method);
                    self::assertSame('Sorry, you are not allowed to access this page.', $exception->getMessage(), $method);
                }
            }
        } finally {
            remove_filter('wp_redirect', [SettingsPageRedirected::class, 'stop'], 10);
        }

        self::assertSame($submitted, did_action(self::SUBMITTED_ACTION), '権限の無いユーザーのPOSTでonSubmit()が呼ばれました');

        add_filter('user_has_cap', $grant);

        try {
            self::assertSame(admin_url('admin.php?page=tmswp-sp-top&' . PageRegistrar::SAVED_QUERY_ARG . '=1'), $this->submitExpectingRedirect($post, self::TOP_HOOK, 'tmswp-sp-top'));
        } finally {
            remove_filter('user_has_cap', $grant);
        }

        self::assertSame($submitted + 1, did_action(self::SUBMITTED_ACTION));
    }

    /**
     * KernelにSettingsPagesを登録していなければ、app/の`#[AsSettingsPage]`のページの初期化(admin_menu)が、debugに
     * 関係なく例外になる。
     *
     * @dataProvider debugModes
     */
    public function testAttributePageWithoutSettingsPagesFailsOnAdminMenu(bool $debug): void
    {
        $this->bootApp([], $debug);
        $this->loginAsAdministrator();

        try {
            do_action('admin_menu');
            self::fail('SettingsPagesの無いKernelの設定ページの初期化が例外になりませんでした');
        } catch (LogicException $exception) {
            self::assertSame("SettingsPages is not registered to the kernel '{$this->namespace}'; pass new SettingsPages() in the extensions of its KernelConfig", $exception->getMessage());
        }

        $parentPages = $GLOBALS['_parent_pages'];
        self::assertIsArray($parentPages);
        self::assertArrayNotHasKey(self::SLUG, $parentPages);
    }

    /** @return array<string, array{bool}> */
    public static function debugModes(): iterable
    {
        return ['debug' => [true], '本番' => [false]];
    }

    /**
     * 一時ディレクトリのプラグインのKernel(既定はdebugで、SettingsPagesを登録)をinitより前にbootする。
     *
     * @param list<ExtensionInterface>|null $extensions nullなら`new SettingsPages()`だけ
     * @param bool $debug falseなら本番のboot(探索結果ファイルを先に作る)
     *
     * @return list<array{hook: string, priority: int, function: mixed, acceptedArgs: int}> bootがinitに登録したコールバック
     */
    private function bootApp(?array $extensions = null, bool $debug = true): array
    {
        $root = $this->createTempDirectory('integration-settings-page');
        $this->namespace = 'TmswpIntegrationSettingsPage' . uniqid();

        foreach (['Option/SiteOption.php' => self::OPTION_GROUP, 'SettingsPage/GeneralSettingsPage.php' => self::GENERAL_PAGE, 'SettingsPage/ToolsPage.php' => self::TOOLS_PAGE, 'SettingsPage/TopFormPage.php' => self::TOP_FORM_PAGE] as $path => $contents) {
            $this->writeFile($root . '/app/' . $path, str_replace('{namespace}', $this->namespace, $contents));
        }

        $this->writeFile($root . '/plugin.php', "<?php\n");
        $logger = new RecordingLogger();
        $config = KernelConfig::plugin(
            $root . '/plugin.php',
            $root . '/app',
            $this->namespace,
            $debug,
            $extensions ?? [new SettingsPages()],
            static fn(): array => ['integration' => new LogChannel($logger)],
        );
        $this->registerAppAutoloader($config);

        if (!$debug) {
            (new DiscoveryFile($config))->write((new Discovery($config, new DiscoveryFile($config)))->scan());
        }

        return $this->bootBeforeInit($config);
    }

    /**
     * CSRFの検証で拒否された送信: onSubmit()を呼ばず、保存せず、セッション切れのエラーの通知を出す。
     *
     * @param array<string, mixed> $post
     */
    private function assertRejectedByCsrfCheck(array $post, string $case): void
    {
        $submitted = did_action(self::SUBMITTED_ACTION);

        $html = $this->requestPage('POST', ['page' => self::SLUG], $post);

        self::assertSame($submitted, did_action(self::SUBMITTED_ACTION), "{$case}: onSubmit()が呼ばれました");
        self::assertStringContainsString('<div class="notice notice-error"><p>Your session has expired. Please submit the form again.</p></div>', $html, $case);
        self::assertFalse(get_option(self::PREFIX . 'site_name'), $case);
        self::assertFalse(get_option(self::PREFIX . 'max_items'), $case);
    }

    private function loginAsAdministrator(): void
    {
        $this->loginAs('administrator');
    }

    private function loginAs(string $role): void
    {
        $userId = self::factory()->user->create(['role' => $role]);
        self::assertIsInt($userId);
        wp_set_current_user($userId);
    }

    /**
     * 管理画面のメニューを作り(初回だけ)、ページのload-{hook_suffix}と描画を、WordPressと同じ順に実行して出力を返す。
     *
     * @param array<string, string> $query
     * @param array<string, mixed> $post
     * @param non-empty-string $hook ページのhook_suffix
     */
    private function requestPage(string $method, array $query = [], array $post = [], string $hook = self::SUBMENU_HOOK): string
    {
        if (!has_action($hook)) {
            do_action('admin_menu');
        }

        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = $query;
        $_POST = wp_slash($post);

        do_action('load-' . $hook);
        ob_start();

        try {
            do_action($hook);
        } finally {
            $html = ob_get_clean();
        }

        self::assertIsString($html);

        return $html;
    }

    /**
     * 保存の送信を処理し、302のリダイレクト(PRG)であることを確かめて、リダイレクト先を返す。wp_safe_redirect()の後の
     * exitに進まないよう、wp_redirectのフィルタで止める。
     *
     * @param array<string, mixed> $post
     * @param non-empty-string $hook ページのhook_suffix
     * @param string $slug ページのslug(`page`のクエリ)
     */
    private function submitExpectingRedirect(array $post, string $hook = self::SUBMENU_HOOK, string $slug = self::SLUG): string
    {
        add_filter('wp_redirect', [SettingsPageRedirected::class, 'stop'], 10, 2);

        try {
            $this->requestPage('POST', ['page' => $slug], [...$post, Form::FIELD_STEP => 'submit'], $hook);
        } catch (SettingsPageRedirected $redirect) {
            self::assertSame(302, $redirect->getCode(), 'PRGのリダイレクトが302ではありません');

            return $redirect->getMessage();
        } finally {
            remove_filter('wp_redirect', [SettingsPageRedirected::class, 'stop'], 10);
        }

        self::fail('保存の後にリダイレクトしませんでした');
    }

    /**
     * WordPress本体のwp-admin/options-head.php(親がoptions-general.phpのページで、admin-header.phpが読む)の出力。
     * 今の$_GETで読み、足された設定の通知(add_settings_error())は片付ける。
     */
    private static function optionsHead(): string
    {
        ob_start();

        try {
            require ABSPATH . 'wp-admin/options-head.php';
        } finally {
            $html = ob_get_clean();
            $GLOBALS['wp_settings_errors'] = [];
        }

        self::assertIsString($html);

        return $html;
    }

    private function page(string $shortName): SettingsPage
    {
        $class = self::fixtureClass($this->namespace . '\SettingsPage\\' . $shortName, SettingsPage::class);

        return new $class();
    }

    /** @return array<string, string> open()が出力したhiddenの名前と値 */
    private static function hiddenValues(string $html): array
    {
        preg_match_all('/<input type="hidden" name="([^"]+)" value="([^"]*)">/', $html, $matches, PREG_SET_ORDER);
        $values = [];

        foreach ($matches as $match) {
            $values[$match[1]] = html_entity_decode($match[2], ENT_QUOTES);
        }

        self::assertArrayHasKey(Form::FIELD_NONCE, $values);
        self::assertArrayHasKey(Form::FIELD_SUBMIT_TOKEN, $values);

        return $values;
    }
}

/** 保存の後のリダイレクトを、exitの前に止めるための例外。メッセージはリダイレクト先、コードは状態コード */
final class SettingsPageRedirected extends RuntimeException
{
    /**
     * wp_redirectのフィルタ。テストの設定ページへのリダイレクトなら、リダイレクト先と状態コードを持たせて投げ、
     * wp_safe_redirect()の後のexitに進ませない。ほかのリダイレクトは変えない。
     */
    public static function stop(string $location, int $status): string
    {
        if (str_contains($location, 'page=tmswp-sp-')) {
            throw new self($location, $status);
        }

        return $location;
    }
}
