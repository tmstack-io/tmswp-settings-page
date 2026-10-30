# 管理画面の設定ページを作る(tmswp/settings-page)

管理画面のメニューに設定ページを足し、オプショングループ(`#[AsOptionGroup]`のenum)の値を、coreのFormで検証して保存する。CSRFの対策(nonce)・PRG・保存の通知・権限の確認はFWが行い、利用側はページの定義・フォームの項目・保存の処理・項目の描画だけを書く。
例の名前空間`App\`は、プロジェクトの名前空間(`tmswp-kernel.php`の`KernelConfig`の`appNamespace`)に読み替える。例のテキストドメイン`my-theme`は、テーマ・プラグインのテキストドメインに置き換える。

- `register_setting()`・`add_settings_section()`(WordPressのSettings API)と`options.php`への送信は使わない。値はオプショングループで宣言し、`OptionFormBridge`で読み書きする。
- 設定ページは`app/SettingsPage/<Name>SettingsPage.php`に置き、名前空間は`App\SettingsPage`にする。保存先のオプショングループは`app/Option/<Name>Option.php`(`App\Option`)に置く。

## 手順

1. `composer require tmswp/settings-page`を実行する。
2. `tmswp-kernel.php`の`KernelConfig::theme()` / `KernelConfig::plugin()`の`extensions`に`new SettingsPages()`を渡す(下の「完全な実装例」)。渡したKernelの`app/`にある`#[AsSettingsPage]`のクラスだけが、メニューに登録される。
3. 保存先のオプショングループ`app/Option/ShopOption.php`を作る。フォームの項目の名前と、`#[OptionSchema]`を付けたケースのbacking値(オプションのキー)を同じにする。
4. `app/SettingsPage/ShopSettingsPage.php`を作る。`#[AsSettingsPage]`を付け、`SettingsPage`を継承した`final class`にする。
5. `definition()`で、slug・見出し・メニューの名前・権限・親メニューを`SettingsPageDefinition`に渡す。
6. `createForm()`で、確認画面の無いフォームを作り、`OptionFormBridge::applyDefaults()`で保存済みの値を初期値にして返す。
7. `onSubmit()`で`OptionFormBridge::persist()`を呼んで保存する。
8. `render()`で、フォームの項目(`<form>`の中身)だけを描画する。
9. フロントでは、オプショングループのアクセサで値を読む(下の「テンプレートでの使い方」)。

## 完全な実装例

`tmswp-kernel.php`(テーマ):

```php
<?php

declare(strict_types=1);

use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\SettingsPage\SettingsPages;

return KernelConfig::theme(
    __DIR__ . '/app',
    'App',
    extensions: [new SettingsPages()],
);
```

`app/Option/ShopOption.php`(保存先。オプション名は`shop_notice_text`等になる):

```php
<?php

declare(strict_types=1);

namespace App\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Option\OptionAccess;

#[AsOptionGroup(prefix: 'shop_')]
enum ShopOption: string
{
    use OptionAccess;

    /** トップページに出すお知らせ。空なら出さない */
    #[OptionSchema(type: MetaValueType::String, default: '')]
    case NoticeText = 'notice_text';

    /** 商品の一覧の1ページの件数 */
    #[OptionSchema(type: MetaValueType::Integer, default: 12)]
    case ItemsPerPage = 'items_per_page';

    /** お知らせを出すか */
    #[OptionSchema(type: MetaValueType::Boolean, default: false)]
    case ShowNotice = 'show_notice';

    /** 定休日(sat・sun・holidayのlist) */
    #[OptionSchema(type: MetaValueType::Array, default: [])]
    case ClosedDays = 'closed_days';

    public static function noticeText(): string
    {
        return self::NoticeText->string();
    }

    public static function itemsPerPage(): int
    {
        return self::ItemsPerPage->int();
    }

    public static function showNotice(): bool
    {
        return self::ShowNotice->bool();
    }

    /** @return list<string> */
    public static function closedDays(): array
    {
        return array_values(array_filter(self::ClosedDays->list(), is_string(...)));
    }
}
```

`app/SettingsPage/ShopSettingsPage.php`(「設定 > Shop」のページ):

```php
<?php

declare(strict_types=1);

namespace App\SettingsPage;

use App\Option\ShopOption;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\Rule\MaxLength;
use TMSWP\Core\Form\Rule\Range;
use TMSWP\Core\Form\Rule\Regex;
use TMSWP\Core\Form\Rule\Required;
use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\OptionFormBridge;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPageDefinition;

#[AsSettingsPage]
final class ShopSettingsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(
            slug: 'shop-settings',
            pageTitle: __('Shop settings', 'my-theme'),
            menuTitle: __('Shop', 'my-theme'),
            parent: 'options-general.php',
        );
    }

    protected function createForm(): Form
    {
        $form = new Form(
            FormDefinition::create('shop_settings')
                ->textarea('notice_text', __('Notice', 'my-theme'), [new MaxLength(200)])
                ->checkbox('show_notice', __('Show the notice', 'my-theme'))
                ->text('items_per_page', __('Items per page', 'my-theme'), [new Required(), new Regex('/\A[0-9]+\z/'), new Range(1, 50)])
                ->checkboxes('closed_days', __('Closed days', 'my-theme'), [
                    'sat' => __('Saturday', 'my-theme'),
                    'sun' => __('Sunday', 'my-theme'),
                    'holiday' => __('Holidays', 'my-theme'),
                ]),
        );
        // 保存済みの値(無ければ#[OptionSchema]の既定値)を、項目の初期値にする
        OptionFormBridge::applyDefaults($form, ShopOption::class);

        return $form;
    }

    protected function onSubmit(FormResult $result): void
    {
        OptionFormBridge::persist($result, ShopOption::class);
    }

    protected function render(?Form $form): void
    {
        if ($form === null) {
            return;
        }

        $notice = $form->field('notice_text');
        $show = $form->field('show_notice');
        $perPage = $form->field('items_per_page');
        $closedDays = $form->field('closed_days');

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row">' . $notice->labelTag() . '</th><td>';
        echo $notice->textarea(['class' => 'large-text', 'rows' => 3]) . $notice->errors();
        echo '<label>' . $show->checkbox() . ' ' . esc_html__('Show the notice on the front page', 'my-theme') . '</label>' . $show->errors();
        echo '</td></tr>';
        echo '<tr><th scope="row">' . $perPage->labelTag() . '</th><td>';
        echo $perPage->input(['type' => 'number', 'min' => '1', 'max' => '50', 'class' => 'small-text']) . $perPage->errors();
        echo '</td></tr>';
        echo '<tr><th scope="row">' . $closedDays->label() . '</th><td>';
        echo $closedDays->checkboxes() . $closedDays->errors();
        echo '</td></tr>';
        echo '</tbody></table>';
    }
}
```

- FWは、`<div class="wrap">`・見出し(`pageTitle`)・保存とエラーの通知・`$form->open()`(`<form>`の開始タグとnonce等の必須のhidden)・「変更を保存」のボタン・`$form->close()`を出す。`render()`は、その間に入る項目だけを出す。
- 送信が完了すると(CSRFと項目の検証を通ると)、FWが`onSubmit()`を呼び、同じページの`&tmswp-settings-saved=1`のURLへリダイレクトする(PRG)。リダイレクトの後に「設定を保存しました。」の通知が1つ出る(WordPressの`updated`は使わない。親が`options-general.php`のページで、本体も同じ通知を出して二重になるため)。検証のエラーのときは保存せず、項目のエラーと「設定を保存できませんでした。項目のエラーを確認してください。」の通知を出す。
- `onSubmit()`の`$result->values`は、項目の検証を通った値(項目の名前 => 値)である。

### ページの定義

`SettingsPageDefinition`のコンストラクタの引数(名前付き引数で必要なものだけを渡す):

| 引数 | 既定 | 意味 |
|---|---|---|
| `slug` | (必須) | ページのslug(`admin.php?page={slug}`の値)。起動したすべてのKernelの設定ページの中で一意にする。重複は`LogicException` |
| `pageTitle` | slugの`-`・`_`を空白にしてucwordsした文字列 | ページの見出しと`<title>` |
| `menuTitle` | `pageTitle` | メニューの名前 |
| `capability` | `manage_options` | ページを表示・保存できる権限 |
| `parent` | `null` | 親メニューのslug(`options-general.php`等)。指定するとサブメニュー、`null`ならトップレベルのメニューになる |
| `icon` | `null` | トップレベルのメニューのアイコン(dashiconの名前かURL)。サブメニューでは使われない |
| `position` | `null` | メニューの位置 |

- `definition()`は、`admin_menu`(`init`の後)に初めて呼ばれる。`__()`で訳した文字列を使ってよい。WordPressの状態(オプションの値等)で定義を変えない。
- `SettingsPageDefinition`は、名前付き引数で作る読み取り専用の値オブジェクトである(coreの`PostTypeDefinition`のメソッドの連鎖とは書き方が違う。引数を`add_menu_page()`・`add_submenu_page()`に渡すだけで、未指定と既定値を区別しないため)。
- トップレベルのメニューの下にページを並べるときは、親のページの`slug`を子の`parent`に渡す(例: 親`new SettingsPageDefinition(slug: 'shop', pageTitle: __('Shop', 'my-theme'), icon: 'dashicons-store', position: 58)`、子`new SettingsPageDefinition(slug: 'shop-shipping', pageTitle: __('Shipping', 'my-theme'), parent: 'shop')`)。

### 項目と保存の値の形

`OptionFormBridge`は、項目の名前と同じキーの`#[OptionSchema]`の値を受け渡す。片側にしかない名前は、Kernelのロガーにdebugのレベルで記録するだけで、受け渡さない。

| `#[OptionSchema]`の型 | 項目 | 項目に付ける規則 | 保存する値 |
|---|---|---|---|
| `String` | `text`・`textarea`・`select`・`radios`等 | 必要に応じて`MaxLength`等 | 文字列 |
| `Integer` | `text` | `Required`・`Regex('/\A[0-9]+\z/')`(負の数も許すなら`'/\A-?[0-9]+\z/'`)・`Range` | int |
| `Number` | `text` | `Required`と、数値だけを通す規則(`Range`は数値でない値を通さない) | 整数ならint、それ以外はfloat |
| `Boolean` | `checkbox`(`checkedValue`は既定の`'1'`) | 不要 | bool(チェックなしは`false`) |
| `Array` | `checkboxes` | 不要(選択肢に無い値はFWが拒否する) | 文字列のlist |
| `Object` | 結べない | — | 同じ名前の項目(`persist()`では送信された値)があると、`applyDefaults()`・`persist()`が`LogicException`。項目の名前を変え、自分で`onSubmit()`の中で保存する |

- `persist()`は、すべての値を型に変換してから保存する。変換できない値(`Integer`の項目に、整数でない値を通す規則しか無い等)があると`LogicException`になり、どのキーも保存しない。項目の規則で、型に合う値だけを通す。`Regex`・`Range`等の規則は空の値を検証しないため、`Integer`・`Number`の項目に`Required`が無いと、空欄の送信が`LogicException`になる。
- 一部のキーだけを保存するときは、`OptionFormBridge::persist($result, ShopOption::class, ['notice_text', 'show_notice'])`のように第3引数でキーを渡す。`#[OptionSchema]`に無い名前は`LogicException`。
- 保存値が型の形でない(ほかの経路で`update_option()`された等)ときは、`applyDefaults()`が、登録したKernelのdebugなら`RuntimeException`、本番ならロガーにerrorで記録してその項目を項目の既定値のままにする。
- オプショングループと設定ページは、`SettingsPages`を登録したKernelの`app/`に置く。オプショングループのKernelに`SettingsPages`が無いと、`OptionFormBridge`は`LogicException`になる。
- 項目の規則・描画の詳しい使い方(`select`・`radios`・エラーの出し方等)は、vendor/tmswp/core/rules/form.mdと同じである。

### フォームの無いページ

情報を表示するだけのページは、`createForm()`を書かない(既定で`null`を返す)。`render()`には`null`が渡され、FWは`<form>`と保存のボタンを出さない。

```php
<?php

declare(strict_types=1);

namespace App\SettingsPage;

use TMSWP\Core\Form\Form;
use TMSWP\SettingsPage\Attribute\AsSettingsPage;
use TMSWP\SettingsPage\SettingsPage;
use TMSWP\SettingsPage\SettingsPageDefinition;

#[AsSettingsPage]
final class ShopStatusSettingsPage extends SettingsPage
{
    public static function definition(): SettingsPageDefinition
    {
        return new SettingsPageDefinition(
            slug: 'shop-status',
            pageTitle: __('Shop status', 'my-theme'),
            capability: 'edit_others_posts',
            parent: 'tools.php',
        );
    }

    protected function render(?Form $form): void
    {
        echo '<table class="widefat striped"><tbody>';
        echo '<tr><th>' . esc_html__('WordPress version', 'my-theme') . '</th><td>' . esc_html(get_bloginfo('version')) . '</td></tr>';
        echo '<tr><th>' . esc_html__('PHP version', 'my-theme') . '</th><td>' . esc_html(PHP_VERSION) . '</td></tr>';
        echo '</tbody></table>';
    }
}
```

### app/の外のページ

`app/`の外(別のComposerのパッケージ等)にある`SettingsPage`のクラスは、属性の探索で見つからないため、`new SettingsPages([\Vendor\Package\ReportSettingsPage::class])`のようにコンストラクタに渡す。`SettingsPage`を継承していないクラスを渡すと、bootが`LogicException`で止まる。

### 確認画面と権限

- 設定ページのフォームは確認画面を使えない。`FormDefinition`で`confirm()`を呼んだフォーム(`Form::isConfirmEnabled()`が`true`)を`createForm()`が返すと、ページを開いた時点で`LogicException`になる(debugに関係なく止まる)。保存のボタンは確認画面の無い送信だけを送るためである。
- `capability`の権限を持たないユーザーには、メニューが登録されない。URLを直接開いても、WordPressの権限のエラーか、FWの403(「このページにアクセスする権限がありません。」)になり、`createForm()`・`onSubmit()`は呼ばれない。
- 保存は`options.php`を通らず、`capability`の権限を持つユーザーがそのまま保存できる。`capability`を`manage_options`より弱い権限にするときは、その権限のユーザーに書き換えさせてよい値だけをそのページに置く。

## テンプレートでの使い方

保存した値は、オプショングループのアクセサで読む。`get_option('shop_notice_text')`を直接呼ばない。

```php
<?php

declare(strict_types=1);

use App\Option\ShopOption;

$notice = ShopOption::noticeText();
?>
<?php if (ShopOption::showNotice() && $notice !== '') : ?>
<p class="shop-notice"><?php echo nl2br(esc_html($notice)); ?></p>
<?php endif; ?>
```

- 文字列は出力の時点でエスケープする(`esc_html()`・`esc_url()`・`esc_attr()`)。保存の時点でHTMLを許したわけではない。
- 一覧の件数等は、前処理クラス(vendor/tmswp/core/rules/page.md)の中で`ShopOption::itemsPerPage()`を読んでクエリに渡す。

## してはいけないこと

- `register_setting()`・`add_settings_section()`・`settings_fields()`を使わない。`<form action="options.php">`を書かない。
- `add_menu_page()`・`add_submenu_page()`を自分で呼ばない。ページは`#[AsSettingsPage]`と`definition()`で宣言する。
- `KernelConfig`の`extensions`に`new SettingsPages()`を渡し忘れない(`#[AsSettingsPage]`のページの登録の時点で`LogicException`になる)。1つの`SettingsPages`のインスタンスを2つのKernelに渡さない。
- `render()`で`<form>`・`</form>`・保存のボタン・`wp_nonce_field()`を出さない。FWが出す。
- `$_POST`を直接読まない。`check_admin_referer()`等でnonceを自分で確かめない。値は`$result->values`か`OptionFormBridge::persist()`で受け取る。
- `onSubmit()`で`echo`・`wp_redirect()`・`exit`をしない。保存の後のリダイレクトはFWが行う。
- `FormDefinition`で`confirm()`を呼ばない。
- 項目の値を`update_option()`で直接保存しない。オプショングループと`OptionFormBridge`を使う。
- 型に合わない値を通す規則のまま`Integer`・`Number`の項目を作らない(`persist()`が`LogicException`になる)。
- `definition()`でオプションの値やユーザーによってslug・権限を変えない。
- slugを、ほかのKernelの設定ページやほかのプラグインの管理画面のページと同じにしない。

## 確認方法

1. `composer qa`が通ること。
2. 管理者でログインし、「設定 > Shop」を開く。項目に保存済みの値(初回は`#[OptionSchema]`の既定値)が入っていること。
3. 値を変えて「変更を保存」を押し、「設定を保存しました。」の通知が出て、ページを開き直しても値が残っていること。`wp option get shop_items_per_page`で保存した値を確かめる。
4. 「Items per page」に`0`や`abc`を入れて保存し、項目のエラーと「設定を保存できませんでした。項目のエラーを確認してください。」が出て、値が保存されないこと。
5. `capability`の権限の無いユーザー(`manage_options`なら編集者)でログインし、メニューに出ないこと、URL(`/wp-admin/options-general.php?page=shop-settings`)を直接開いても表示されないこと。
6. フロントで、`ShopOption`で読んだ値が表示されること。

## 発展

- オプショングループの宣言・読み書きの規則・テスト: vendor/tmswp/core/rules/options.md
- フォームの項目・規則・描画: vendor/tmswp/core/rules/form.md
- 拡張の登録の仕方と制約: vendor/tmswp/core/rules/extensions.md
- 設計の正本: TMSWPのmonorepoの[docs/08-extensions.md](https://github.com/tmstack-io/tmswp/blob/v0.9.1/docs/08-extensions.md)の「tmswp/settings-page — 管理画面設定ページ」の節
