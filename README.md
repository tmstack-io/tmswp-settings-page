# tmswp/settings-page

TMStack WordPress Framework(TMSWP)の拡張パッケージで、管理画面の設定ページを作ります。`#[AsSettingsPage]`を付けたクラスでメニューを宣言し、coreのFormで入力を検証して、オプショングループ(`#[AsOptionGroup]`のenum)に保存します。CSRFの対策(nonce)・保存の後のリダイレクト(PRG)・保存の通知・権限の確認はパッケージが行います。WordPressのSettings API(`register_setting()`・`options.php`)は使いません。

## 動作環境

- PHP 8.3〜8.5、WordPress 7.0以上
- tmswp/core `^0.10`(composer.jsonの`require`)

## 提供するもの

| 名前 | 役割 |
|---|---|
| `TMSWP\SettingsPage\SettingsPages` | `KernelConfig`の`extensions`に渡す拡張。登録したKernelの`app/`にある`#[AsSettingsPage]`のクラスを、`admin_menu`でメニューに登録する。`app/`の外のページはコンストラクタに渡す |
| `TMSWP\SettingsPage\Attribute\AsSettingsPage` | 設定ページのクラスに付ける属性 |
| `TMSWP\SettingsPage\SettingsPage` | 設定ページの基底。`definition()`・`createForm()`・`onSubmit()`・`render()`を書く。`url()`でページのURLを返す |
| `TMSWP\SettingsPage\SettingsPageDefinition` | slug・見出し・メニューの名前・権限・親メニュー・アイコン・位置 |
| `TMSWP\SettingsPage\OptionFormBridge` | `applyDefaults()`でオプショングループの値をフォームの初期値にし、`persist()`でフォームの値を型に変換して保存する |

## 導入

1. `composer require tmswp/settings-page`を実行します。
2. テーマ・プラグインのルートの`tmswp-kernel.php`で、`extensions`に`SettingsPages`を渡します。

```php
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\SettingsPage\SettingsPages;

return KernelConfig::theme(__DIR__ . '/app', 'App', extensions: [new SettingsPages()]);
```

3. `app/SettingsPage/`に、`#[AsSettingsPage]`を付けて`SettingsPage`を継承したクラスを置きます。

```php
#[AsSettingsPage]
final class ShopSettingsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(slug: 'shop-settings', pageTitle: __('Shop settings', 'my-theme'), parent: 'options-general.php');
    }

    protected function createForm(): Form
    {
        $form = new Form(FormDefinition::create('shop_settings')->textarea('notice_text', __('Notice', 'my-theme'), [new MaxLength(200)]));
        OptionFormBridge::applyDefaults($form, ShopOption::class);

        return $form;
    }

    protected function onSubmit(FormResult $result): void
    {
        OptionFormBridge::persist($result, ShopOption::class);
    }

    protected function render(?Form $form): void
    {
        // <form>の中の項目だけを出力する。<form>・nonce・保存のボタン・通知はパッケージが出す
    }
}
```

完全な例(`use`文・オプショングループ・項目の描画・型ごとの項目の作り方)は、レシピ`vendor/tmswp/settings-page/rules/settings-page.md`にあります。

## 制約

- 1つの`SettingsPages`のインスタンスは、1つのKernelにだけ登録できます。テーマとプラグインの両方で使うときは、それぞれの`tmswp-kernel.php`で`new SettingsPages()`を渡します。
- slugは、起動したすべてのKernelの設定ページの中で一意にします。重複すると`LogicException`になります。
- 設定ページのフォームは確認画面を使えません。`createForm()`が確認画面の有効なフォーム(`Form::isConfirmEnabled()`が`true`)を返すと`LogicException`になります。
- オプショングループと設定ページは、`SettingsPages`を登録したKernelの`app/`に置きます。
- 通知・保存のボタン・権限のエラーの文言は英語で、日本語訳を同梱しています(テキストドメイン`tmswp`)。

## レシピ

- 設定ページを作る(ページの定義・項目と保存の値の形・値の読み出し・権限・確認画面): `vendor/tmswp/settings-page/rules/settings-page.md`
- 拡張の登録の仕方: `vendor/tmswp/core/rules/extensions.md`
- オプショングループの宣言: `vendor/tmswp/core/rules/options.md`

設計の正本は、TMSWPのmonorepo([tmstack-io/tmswp](https://github.com/tmstack-io/tmswp))の[docs/08-extensions.md](https://github.com/tmstack-io/tmswp/blob/v0.10.0/docs/08-extensions.md)の「tmswp/settings-page — 管理画面設定ページ」の節です。

## ライセンス

GPL-2.0-or-later(`LICENSE`)
