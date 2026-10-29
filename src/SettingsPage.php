<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormResult;

/**
 * 設定ページの基底。`#[AsSettingsPage]`を付けてapp/に置くか、SettingsPagesのコンストラクタに渡す。
 */
abstract class SettingsPage
{
    /** メニューの定義。admin_menu以降(init以降)に呼ばれるため、__()で訳した文字列を使える */
    abstract public static function definition(): SettingsPageDefinition;

    /** 設定フォーム。フォームの無い情報表示のページはnullのまま。確認画面の有効なフォームはLogicExceptionになる */
    protected function createForm(): ?Form
    {
        return null;
    }

    /** フォームの送信が完了したとき(CSRFと値の検証を通ったとき)の保存処理 */
    protected function onSubmit(FormResult $result): void {}

    /**
     * ページの本体を描画する(echoする)。フォームのあるページでは、FWが出す`<form>`の中(項目の部分)だけを描画する。
     * フォームの開始タグと必須のhidden・保存ボタン・終了タグ・エラーの通知は、FWが出す。
     *
     * @param Form|null $form createForm()の戻り値(handle()済み)
     */
    abstract protected function render(?Form $form): void;

    /**
     * このページの管理画面のURL(エスケープしていない生のURL)。HTMLに出すときはesc_url()でエスケープする。
     * admin_menuでメニューに登録した後に使える。
     *
     * URLの組み立てはmenu_page_url()と同じ規則による。menu_page_url()はesc_url()済みの文字列(`&`が`&#038;`)を返すため
     * 使わない。
     *
     * @param array<string, scalar|null> $queryArgs URLに足すクエリの引数
     *
     * @throws LogicException ページがメニューに登録されていない場合(admin_menuの前、または権限が無く登録しなかった場合)
     */
    final public function url(array $queryArgs = []): string
    {
        $slug = static::definition()->slug;
        $parentPages = $GLOBALS['_parent_pages'] ?? null;

        if (!\is_array($parentPages) || !\array_key_exists($slug, $parentPages)) {
            throw new LogicException("settings page '{$slug}' is not registered in the admin menu; url() is available after the admin_menu hook");
        }

        $parent = $parentPages[$slug];

        // 親が設定ページ(add_menu_page()のページ)でないファイル(options-general.php等)なら、そのファイルの下のURL
        $url = \is_string($parent) && $parent !== '' && !\array_key_exists($parent, $parentPages)
            ? admin_url(add_query_arg('page', $slug, $parent))
            : admin_url('admin.php?page=' . $slug);

        return $queryArgs === [] ? $url : add_query_arg($queryArgs, $url);
    }

    /**
     * @internal
     */
    final public function frameworkCreateForm(): ?Form
    {
        return $this->createForm();
    }

    /**
     * @internal
     */
    final public function frameworkSubmit(FormResult $result): void
    {
        $this->onSubmit($result);
    }

    /**
     * @internal
     */
    final public function frameworkRender(?Form $form): void
    {
        $this->render($form);
    }
}
