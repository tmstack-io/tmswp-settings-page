<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormAction;
use TMSWP\Core\Form\FormStep;

/**
 * 設定ページ1つのメニューの登録・送信の処理・画面の描画。
 *
 * @internal SettingsPagesが作る
 */
final class PageRegistrar
{
    /**
     * 保存の後のリダイレクト先に付けるクエリ変数。WordPressの`updated`は使わない。本体のoptions-head.phpは、親が
     * options-general.phpのページで`updated`を見て「Settings saved.」を出すため、FWの通知と二重になる。
     */
    public const string SAVED_QUERY_ARG = 'tmswp-settings-saved';

    private ?SettingsPage $page = null;

    private ?Form $form = null;

    /** @param class-string<SettingsPage> $pageClass */
    public function __construct(private readonly string $pageClass) {}

    /**
     * definition()を読み、parentの有無でadd_menu_page()かadd_submenu_page()を呼び、load-{hook_suffix}に
     * handleRequest()を接続する。admin_menuで呼ぶ。登録したときは、保存の後のクエリ変数(SAVED_QUERY_ARG)を
     * removable_query_argsに足し、表示の後にWordPressがアドレスバーのURLから除くようにする(再読み込みで通知を
     * もう一度出さない。本体の`updated`と同じ扱い)。
     *
     * add_submenu_page()は、今のユーザーがcapabilityを持たなければページを登録せずにfalseを返す。これは登録の失敗では
     * なく「権限が無いため登録しない」という正常な結果なので、何もしない(handleRequest()も接続しない)。
     *
     * @throws LogicException WordPressが空のhook_suffixを返した場合
     */
    public function registerMenu(): void
    {
        $definition = $this->pageClass::definition();

        if ($definition->parent !== null) {
            $hookSuffix = add_submenu_page(
                $definition->parent,
                $definition->pageTitle,
                $definition->menuTitle,
                $definition->capability,
                $definition->slug,
                [$this, 'output'],
                $definition->position,
            );
        } else {
            $hookSuffix = add_menu_page(
                $definition->pageTitle,
                $definition->menuTitle,
                $definition->capability,
                $definition->slug,
                [$this, 'output'],
                $definition->icon ?? '',
                $definition->position,
            );
        }

        if ($hookSuffix === false) {
            return;
        }

        if ($hookSuffix === '') {
            throw new LogicException("failed to register settings page '{$definition->slug}'");
        }

        add_action("load-{$hookSuffix}", [$this, 'handleRequest'], 10, 0);
        add_filter('removable_query_args', [self::class, 'removableQueryArgs']);
    }

    /**
     * removable_query_argsのフィルタ。SAVED_QUERY_ARGを足す。
     *
     * @param array<int, string> $args
     *
     * @return array<int, string>
     */
    public static function removableQueryArgs(array $args): array
    {
        $args[] = self::SAVED_QUERY_ARG;

        return $args;
    }

    /**
     * 画面の出力の前(load-{hook_suffix})に、権限を確かめてフォームを処理する。送信が完了したら、onSubmit()の後に
     * 保存の完了を示すURLへリダイレクトする(PRG)。
     *
     * 権限が無ければwp_die()で403を返す。
     *
     * @throws LogicException createForm()が確認画面の有効なフォームを返した場合(設定の誤り。debugに関係なく投げる)
     */
    public function handleRequest(): void
    {
        $definition = $this->pageClass::definition();

        if (!current_user_can($definition->capability)) {
            wp_die(esc_html__('Sorry, you are not allowed to access this page.', 'tmswp'), '', ['response' => 403]);
        }

        $page = $this->page();
        $form = $page->frameworkCreateForm();
        $this->form = $form;

        if (!$form instanceof Form) {
            return;
        }

        // 保存ボタンは確認画面の無いフォームの送信(_step=submit)だけを送るため、確認画面の有効なフォームは保存できない
        if ($form->isConfirmEnabled()) {
            throw new LogicException("the form '{$form->id()}' of settings page {$this->pageClass} enables the confirmation screen, which settings pages do not support; remove confirm() from its FormDefinition");
        }

        $result = $form->handle();

        if ($result->step !== FormStep::Complete) {
            return;
        }

        $page->frameworkSubmit($result);
        wp_safe_redirect($page->url([self::SAVED_QUERY_ARG => '1']));
        // FWはexitを使わないが、設定ページの保存の後のwp_safe_redirect()の直後だけは例外とし、リダイレクトの応答をここで確定させる
        exit;
    }

    /**
     * メニューのコールバック。wrap・見出し・通知と、フォームのあるページではフォームの外枠(開始タグと必須のhidden・
     * 保存ボタン・終了タグ)をFWが出し、その中をrender()が描画する。フォームの無いページはrender()だけを呼ぶ。
     */
    public function output(): void
    {
        $definition = $this->pageClass::definition();
        $page = $this->page();
        $form = $this->form;

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($definition->pageTitle) . '</h1>';

        // 保存後のURL(SAVED_QUERY_ARG=1)のままもう一度送信したPOSTには出さない
        if (($_GET[self::SAVED_QUERY_ARG] ?? null) === '1' && ($_SERVER['REQUEST_METHOD'] ?? null) !== 'POST') {
            echo self::notice('notice-success is-dismissible', __('Settings saved.', 'tmswp'));
        }

        if (!$form instanceof Form) {
            $page->frameworkRender(null);
            echo '</div>';

            return;
        }

        foreach ($form->formErrors() as $message) {
            echo self::notice('notice-error', $message);
        }

        if ($form->errors() !== []) {
            echo self::notice('notice-error', __('The settings were not saved. Please check the errors in the fields.', 'tmswp'));
        }

        echo $form->open();
        $page->frameworkRender($form);
        echo '<p class="submit"><button type="submit" name="' . esc_attr(Form::FIELD_STEP) . '" value="' . esc_attr(FormAction::Submit->value) . '" class="button button-primary">' . esc_html__('Save Changes', 'tmswp') . '</button></p>';
        echo $form->close();
        echo '</div>';
    }

    /** 管理画面の通知。$classesはnotice-successやnotice-error等のクラス(固定の文字列だけを渡す) */
    private static function notice(string $classes, string $message): string
    {
        return '<div class="notice ' . $classes . '"><p>' . esc_html($message) . '</p></div>';
    }

    private function page(): SettingsPage
    {
        if (!$this->page instanceof SettingsPage) {
            $this->page = new $this->pageClass();
        }

        return $this->page;
    }
}
