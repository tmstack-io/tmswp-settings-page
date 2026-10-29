<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LogLevel;
use stdClass;
use TMSWP\Core\Exception\Form\FormConfigException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormDefinition;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Form\FormStep;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\SettingsPage\OptionFormBridge;
use TMSWP\SettingsPage\Tests\Support\BootsSettingsPagesKernel;
use TMSWP\Testing\MemoryOptionStorage;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\UnitTestCase;

/**
 * OptionFormBridgeの、スキーマの型とFormの値の形の変換・宣言の照合・Kernelのロガーへの記録。
 */
final class OptionFormBridgeTest extends UnitTestCase
{
    use BootsSettingsPagesKernel;

    private const OPTION_GROUP = <<<'PHP'
<?php

declare(strict_types=1);

namespace {namespace}\Option;

use TMSWP\Core\Attribute\AsOptionGroup;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Meta\MetaValueType;

#[AsOptionGroup(prefix: 'unit_')]
enum SiteOption: string
{
    #[OptionSchema(MetaValueType::String, default: 'Example')]
    case SiteName = 'site_name';

    #[OptionSchema(MetaValueType::Integer, default: 10)]
    case MaxItems = 'max_items';

    #[OptionSchema(MetaValueType::Number)]
    case Ratio = 'ratio';

    #[OptionSchema(MetaValueType::Boolean, default: false)]
    case Enabled = 'enabled';

    #[OptionSchema(MetaValueType::Array, default: [])]
    case Channels = 'channels';

    #[OptionSchema(MetaValueType::Object)]
    case Extra = 'extra';

    #[OptionSchema]
    case ApiToken = 'api_token';

    case Undeclared = 'undeclared';
}
PHP;

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['wp_version'] = '7.0';
        $GLOBALS['wp_actions'] = [];
    }

    protected function tearDown(): void
    {
        Kernel::reset();
        unset($GLOBALS['wp_version'], $GLOBALS['wp_actions']);
        parent::tearDown();
    }

    /** 保存値(PHPの型のまま)を、項目の既定値の形(文字列、複数選択は文字列のlist)にして渡す。 */
    public function testApplyDefaultsConvertsStoredValuesToFormValues(): void
    {
        $app = $this->bootApp('Defaults');
        $this->store($app['options'], ['site_name' => 'Shop', 'max_items' => 25, 'ratio' => 1.5, 'enabled' => true, 'channels' => ['mail', 'sms']]);
        $form = self::form();

        OptionFormBridge::applyDefaults($form, $app['group']);

        self::assertSame(['site_name' => 'Shop', 'max_items' => '25', 'ratio' => '1.5', 'enabled' => '1', 'channels' => ['mail', 'sms']], $form->values());
    }

    /**
     * WordPressのoptionsテーブルを経た保存値(intやboolが文字列になったもの)も受け付ける。update_option()は5を'5'、
     * trueを'1'、falseを''で保存する。
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $expected
     */
    #[DataProvider('storedStringForms')]
    public function testApplyDefaultsAcceptsTheStringFormsThatWordPressStores(array $stored, array $expected): void
    {
        $app = $this->bootApp('StringForms');
        $this->store($app['options'], $stored);
        $form = self::form();

        OptionFormBridge::applyDefaults($form, $app['group']);

        self::assertSame($expected, array_intersect_key($form->values(), $expected));
    }

    /** @return iterable<string, array{array<string, mixed>, array<string, mixed>}> */
    public static function storedStringForms(): iterable
    {
        yield 'int and true' => [['max_items' => '25', 'ratio' => '0.25', 'enabled' => '1'], ['max_items' => '25', 'ratio' => '0.25', 'enabled' => '1']];
        yield 'false as empty string' => [['enabled' => ''], ['enabled' => '']];
        yield 'false as zero' => [['enabled' => '0'], ['enabled' => '']];
        yield 'native false' => [['enabled' => false], ['enabled' => '']];
        yield 'int number' => [['ratio' => 3], ['ratio' => '3']];
        yield 'scalars in a list' => [['channels' => [1, 'sms']], ['channels' => ['1', 'sms']]];
    }

    /** 保存値が無いキーはスキーマの既定値を使い、スキーマの既定値も無いキーは項目の既定値のままにする。 */
    public function testApplyDefaultsUsesSchemaDefaultsAndKeepsFieldDefaultsWithoutValues(): void
    {
        $app = $this->bootApp('SchemaDefaults');
        $form = self::form();

        OptionFormBridge::applyDefaults($form, $app['group']);

        self::assertSame(['site_name' => 'Example', 'max_items' => '10', 'ratio' => '0.5', 'enabled' => '', 'channels' => []], $form->values());
    }

    /** 型の形でない保存値は、debugではデータの取得の失敗として例外になる。 */
    public function testStoredValueOfAnotherTypeThrowsInDebug(): void
    {
        $app = $this->bootApp('StoredMismatchDebug');
        $this->store($app['options'], ['max_items' => 'many']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("the stored value of option 'max_items' of {$app['group']} (string) does not match its integer type");

        OptionFormBridge::applyDefaults(self::form(), $app['group']);
    }

    /** 本番では、型の形でない保存値を登録したKernelのロガーにerrorで記録し、その項目は項目の既定値のままにする。 */
    public function testStoredValueOfAnotherTypeIsLoggedToTheKernelInProduction(): void
    {
        $app = $this->bootApp('StoredMismatchProduction', debug: false);
        $this->store($app['options'], ['max_items' => 'many', 'enabled' => 'yes', 'site_name' => 'Shop']);
        $form = self::form();

        OptionFormBridge::applyDefaults($form, $app['group']);

        self::assertSame('', $form->values()['max_items']);
        self::assertSame('', $form->values()['enabled']);
        self::assertSame('Shop', $form->values()['site_name']);
        $errors = array_values(array_filter($app['logger']->records, static fn(array $record): bool => $record['level'] === LogLevel::ERROR));
        self::assertCount(2, $errors);
        self::assertStringStartsWith("tmswp.settings-page: the stored value of option 'max_items' of {$app['group']} (string) does not match its integer type", $errors[0]['message']);
        self::assertStringStartsWith("tmswp.settings-page: the stored value of option 'enabled' of {$app['group']} (string) does not match its boolean type", $errors[1]['message']);
    }

    /** object型のキーは項目と結べない(設定の誤り)。 */
    public function testObjectOptionCannotBeBoundToAField(): void
    {
        $app = $this->bootApp('Object');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("option 'extra' of {$app['group']} has the object type, which cannot be bound to a form field");

        OptionFormBridge::applyDefaults(new Form(FormDefinition::create('site')->text('extra', 'Extra')), $app['group']);
    }

    /** array型のキーを単一の値の項目と結ぶと、Formが値の形の誤りとして例外にする。 */
    public function testArrayOptionBoundToASingleValueFieldIsRejectedByTheForm(): void
    {
        $app = $this->bootApp('ArrayToText');

        $this->expectException(FormConfigException::class);

        OptionFormBridge::applyDefaults(new Form(FormDefinition::create('site')->text('channels', 'Channels')), $app['group']);
    }

    /** 送信の値(文字列)をスキーマの型に変換して保存する。 */
    public function testPersistConvertsSubmittedValuesToTheSchemaTypes(): void
    {
        $app = $this->bootApp('Persist');

        OptionFormBridge::persist(self::completed(['site_name' => 'Shop', 'max_items' => '+007', 'ratio' => '2.5', 'enabled' => '1', 'channels' => ['mail']]), $app['group']);

        self::assertSame('Shop', $app['options']->get('unit_site_name'));
        self::assertSame(7, $app['options']->get('unit_max_items'));
        self::assertSame(2.5, $app['options']->get('unit_ratio'));
        self::assertTrue($app['options']->get('unit_enabled'));
        self::assertSame(['mail'], $app['options']->get('unit_channels'));

        OptionFormBridge::persist(self::completed(['max_items' => '-12', 'ratio' => '3', 'enabled' => '', 'channels' => []]), $app['group']);

        self::assertSame(-12, $app['options']->get('unit_max_items'));
        self::assertSame(3, $app['options']->get('unit_ratio'));
        self::assertFalse($app['options']->get('unit_enabled'));
        self::assertSame([], $app['options']->get('unit_channels'));
    }

    /** スキーマの型に変換できない値は例外になり、ほかのキーも含めて保存しない。例外のメッセージに入力値を入れない。 */
    #[DataProvider('unconvertibleValues')]
    public function testPersistRejectsValuesThatCannotBeConverted(string $field, mixed $value, string $type): void
    {
        $app = $this->bootApp('Unconvertible');

        try {
            // site_nameは宣言の順で最初のキー。先に変換できたキーも保存しないことを確かめる
            OptionFormBridge::persist(self::completed(['site_name' => 'Shop', $field => $value]), $app['group']);
            self::fail('変換できない値が例外になりませんでした');
        } catch (LogicException $exception) {
            self::assertStringStartsWith("the submitted value of field '{$field}' (" . get_debug_type($value) . ") cannot be converted to the {$type} type of option '{$field}' of {$app['group']}", $exception->getMessage());

            if (\is_string($value) && \strlen($value) > 1) {
                self::assertStringNotContainsString($value, $exception->getMessage());
            }
        }

        self::assertFalse($app['options']->has('unit_' . $field));
        self::assertFalse($app['options']->has('unit_site_name'), '変換できた値も保存しない');
    }

    /** @return iterable<string, array{string, mixed, string}> */
    public static function unconvertibleValues(): iterable
    {
        yield 'text for integer' => ['max_items', 'many', 'integer'];
        yield 'empty for integer' => ['max_items', '', 'integer'];
        yield 'decimal for integer' => ['max_items', '1.5', 'integer'];
        yield 'integer overflow' => ['max_items', '99999999999999999999', 'integer'];
        yield 'text for number' => ['ratio', 'half', 'number'];
        yield 'infinite number' => ['ratio', '1e999', 'number'];
        yield 'other checked value for boolean' => ['enabled', 'yes', 'boolean'];
        yield 'zero for boolean' => ['enabled', '0', 'boolean'];
        yield 'string for array' => ['channels', 'mailbox', 'array'];
        yield 'list for string' => ['site_name', ['Shop'], 'string'];
        yield 'object for string' => ['site_name', new stdClass(), 'string'];
    }

    /** object型のキーは、送信の値があれば保存せずに例外にする。 */
    public function testPersistRejectsObjectOption(): void
    {
        $app = $this->bootApp('PersistObject');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("option 'extra' of {$app['group']} has the object type");

        OptionFormBridge::persist(self::completed(['extra' => 'x']), $app['group']);
    }

    /** $onlyの名前に`#[OptionSchema]`で宣言していないものがあれば、何も保存せずに例外にする。 */
    public function testPersistRejectsUndeclaredNamesInOnly(): void
    {
        $app = $this->bootApp('OnlyUndeclared');

        try {
            OptionFormBridge::persist(self::completed(['site_name' => 'Shop', 'undeclared' => 'x']), $app['group'], ['site_name', 'undeclared', 'missing']);
            self::fail('宣言の無い名前が例外になりませんでした');
        } catch (LogicException $exception) {
            self::assertSame("persist() got names that are not declared with #[OptionSchema] in {$app['group']}: undeclared, missing", $exception->getMessage());
        }

        self::assertFalse($app['options']->has('unit_site_name'));
    }

    /** $onlyを指定したら、そのキーだけを保存する。 */
    public function testPersistSavesOnlyTheNamesInOnly(): void
    {
        $app = $this->bootApp('Only');

        OptionFormBridge::persist(self::completed(['site_name' => 'Shop', 'max_items' => '5']), $app['group'], ['max_items']);

        self::assertFalse($app['options']->has('unit_site_name'));
        self::assertSame(5, $app['options']->get('unit_max_items'));
    }

    /**
     * `#[OptionSchema]`で宣言したキーだけを保存する。宣言の無いケース(undeclared)とオプショングループに無い名前の
     * 送信の値は、保存しない。
     */
    public function testPersistSavesOnlyDeclaredKeys(): void
    {
        $app = $this->bootApp('DeclaredOnly');

        OptionFormBridge::persist(self::completed(['site_name' => 'Shop', 'undeclared' => 'x', 'note' => 'y']), $app['group']);

        self::assertSame('Shop', $app['options']->get('unit_site_name'));
        self::assertFalse($app['options']->has('unit_undeclared'));
        self::assertFalse($app['options']->has('unit_note'));
    }

    /** 片側にしかない名前は、黙って無視せず、登録したKernelのロガーにdebugで記録する。 */
    public function testUnmatchedNamesAreLoggedToTheKernelLogger(): void
    {
        $app = $this->bootApp('Unmatched');
        $form = new Form(FormDefinition::create('site')->text('site_name', 'Site name')->text('note', 'Note'));

        OptionFormBridge::applyDefaults($form, $app['group']);
        OptionFormBridge::persist(self::completed(['site_name' => 'Shop', 'note' => 'x']), $app['group'], ['site_name', 'max_items']);

        $debug = array_map(
            static fn(array $record): array => [$record['message'], $record['context']],
            array_values(array_filter($app['logger']->records, static fn(array $record): bool => $record['level'] === LogLevel::DEBUG)),
        );
        $group = $app['group'];

        self::assertContains(['tmswp.settings-page: option {option} of {group} has no matching form field', ['option' => 'api_token', 'group' => $group]], $debug);
        self::assertContains(['tmswp.settings-page: form field {field} has no matching option of {group}', ['field' => 'note', 'group' => $group]], $debug);
        self::assertContains(['tmswp.settings-page: option {option} of {group} has no matching submitted field', ['option' => 'max_items', 'group' => $group]], $debug);
        self::assertContains(['tmswp.settings-page: submitted field {field} has no matching option of {group}', ['field' => 'note', 'group' => $group]], $debug);
    }

    /** オプショングループのKernelにSettingsPagesを登録していなければ、設定の誤りとして例外にする。 */
    public function testBridgeRequiresSettingsPagesOnTheKernelOfTheOptionGroup(): void
    {
        $app = $this->bootApp('WithoutExtension', extensions: []);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SettingsPages is not registered to the kernel');

        OptionFormBridge::persist(self::completed(['site_name' => 'Shop']), $app['group']);
    }

    /**
     * @param list<\TMSWP\Core\Kernel\ExtensionInterface>|null $extensions
     *
     * @return array{group: class-string, options: MemoryOptionStorage, logger: RecordingLogger}
     */
    private function bootApp(string $suffix, bool $debug = true, ?array $extensions = null): array
    {
        $app = $this->bootSettingsPagesKernel($suffix, ['Option/SiteOption.php' => self::OPTION_GROUP], $extensions, $debug);
        $group = $app['namespace'] . '\Option\SiteOption';
        self::assertTrue(enum_exists($group));

        return ['group' => $group, 'options' => $app['options'], 'logger' => $app['logger']];
    }

    /** @param array<string, mixed> $values プレフィックスを除いたキー => 保存値 */
    private function store(MemoryOptionStorage $options, array $values): void
    {
        foreach ($values as $key => $value) {
            $options->set('unit_' . $key, $value);
        }
    }

    private static function form(): Form
    {
        return new Form(
            FormDefinition::create('site')
                ->text('site_name', 'Site name')
                ->text('max_items', 'Max items')
                ->text('ratio', 'Ratio', default: '0.5')
                ->checkbox('enabled', 'Enabled')
                ->checkboxes('channels', 'Channels', ['mail' => 'Mail', 'sms' => 'SMS', '1' => 'One']),
        );
    }

    /** @param array<string, mixed> $values */
    private static function completed(array $values): FormResult
    {
        return new FormResult(FormStep::Complete, $values, [], []);
    }
}
