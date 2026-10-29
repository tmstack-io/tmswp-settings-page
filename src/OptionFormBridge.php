<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage;

use ReflectionEnum;
use ReflectionEnumBackedCase;
use TMSWP\Core\Attribute\OptionSchema;
use TMSWP\Core\Exception\InvalidSchemaException;
use TMSWP\Core\Exception\LogicException;
use TMSWP\Core\Exception\RuntimeException;
use TMSWP\Core\Form\Form;
use TMSWP\Core\Form\FormResult;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Meta\MetaValueType;
use TMSWP\Core\Meta\ValueCaster;
use TMSWP\Core\Option\OptionRepository;
use TMSWP\Core\Option\OptionRepositoryFactory;

/**
 * オプショングループ(`#[AsOptionGroup]`のenum)とFormの橋渡し。`#[OptionSchema]`のキーと同じ名前の項目の値を、
 * スキーマの型とFormの値の形(文字列。複数選択の項目は文字列のlist)の間で変換して受け渡す。
 *
 * 型ごとのFormの値の形:
 *
 * - string: 文字列
 * - integer: 整数の文字列(先頭の`+`と0は許す)
 * - number: 数値の文字列(is_numeric())
 * - boolean: `'1'`がtrue、`''`がfalse(Field::checkbox()の既定のcheckedValue`'1'`と、チェックしない場合の値)
 * - array: 文字列のlist(複数選択の項目)
 * - object: 項目と結べない(例外)
 *
 * ログとdebugの判定には、オプショングループのKernel(SettingsPagesを登録したKernelでなければならない)を使う。
 */
final class OptionFormBridge
{
    private const INTEGER_PATTERN = '/\A([+-]?)0*([0-9]+)\z/';

    /**
     * オプショングループの今の値を、同じ名前の項目の既定値にする(Form::applyDefaults())。handle()の前に呼ぶ。
     * 値が無い(保存値もスキーマの既定値も無い)キーは、項目の既定値のままにする。
     *
     * 保存値がスキーマの型の形でない場合(他の経路で書かれた値等)は、データの取得の失敗として扱う。debugなら例外、
     * 本番ならKernelのロガーにerrorで記録し、その項目は項目の既定値のままにする。
     *
     * @param class-string $optionGroupClass `#[AsOptionGroup]`のstring backed enum
     *
     * @throws LogicException オプショングループのKernelにSettingsPagesが無い場合、object型のキーを項目と結んだ場合
     * @throws RuntimeException debugで、保存値がスキーマの型の形でない場合
     * @throws \TMSWP\Core\Exception\Form\FormConfigException 変換した値の形が項目に合わない場合(array型のキーを
     *                                                        単一の値の項目と結んだ等)
     */
    public static function applyDefaults(Form $form, string $optionGroupClass): void
    {
        $kernel = self::kernel($optionGroupClass);
        $repository = self::repository($kernel, $optionGroupClass);
        $fieldNames = array_keys($form->fields());
        $schemaKeys = [];
        $defaults = [];

        foreach (self::schema($optionGroupClass) as [$key, $type]) {
            $schemaKeys[] = $key;

            if (!\in_array($key, $fieldNames, true)) {
                self::debug($kernel, 'option {option} of {group} has no matching form field', ['option' => $key, 'group' => $optionGroupClass]);
                continue;
            }

            if ($type === MetaValueType::Object) {
                throw self::objectTypeException($key, $optionGroupClass);
            }

            $value = $repository->get($key);

            if ($value === null) {
                continue;
            }

            $default = self::toFormValue($type, $value, $key, $optionGroupClass);

            if ($default === null) {
                $message = "the stored value of option '{$key}' of {$optionGroupClass} (" . get_debug_type($value) . ") does not match its {$type->value} type; the form field keeps its default value";

                if ($kernel->config()->debug) {
                    throw new RuntimeException($message);
                }

                $kernel->logger()->error('tmswp.settings-page: ' . $message);
                continue;
            }

            $defaults[$key] = $default;
        }

        foreach ($fieldNames as $fieldName) {
            if (!\in_array($fieldName, $schemaKeys, true)) {
                self::debug($kernel, 'form field {field} has no matching option of {group}', ['field' => $fieldName, 'group' => $optionGroupClass]);
            }
        }

        $form->applyDefaults($defaults);
    }

    /**
     * 送信が完了したフォームの値を、スキーマの型に変換してオプショングループに保存する(onSubmit()で呼ぶ)。
     * 変換できない値は、項目の検証の規則が足りない(整数の項目に整数以外を許す等)ための誤りとして例外にし、どのキーも
     * 保存しない。
     *
     * @param class-string $optionGroupClass `#[AsOptionGroup]`のstring backed enum
     * @param list<string> $only 保存するキー。空なら`#[OptionSchema]`のすべてのキー
     *
     * @throws LogicException オプショングループのKernelにSettingsPagesが無い場合、$onlyに`#[OptionSchema]`で宣言して
     *                        いない名前がある場合、値をスキーマの型に変換できない場合
     * @throws RuntimeException データベースのエラー(OptionRepository::set())
     */
    public static function persist(FormResult $result, string $optionGroupClass, array $only = []): void
    {
        $kernel = self::kernel($optionGroupClass);
        $repository = self::repository($kernel, $optionGroupClass);
        $schema = self::schema($optionGroupClass);
        $schemaKeys = array_column($schema, 0);
        $undeclared = array_values(array_diff($only, $schemaKeys));

        if ($undeclared !== []) {
            throw new LogicException("persist() got names that are not declared with #[OptionSchema] in {$optionGroupClass}: " . implode(', ', $undeclared));
        }

        $values = $result->values;
        $converted = [];

        // 一部だけを保存しないよう、すべての値を変換してから保存する
        foreach ($schema as [$key, $type]) {
            if ($only !== [] && !\in_array($key, $only, true)) {
                continue;
            }

            if (!\array_key_exists($key, $values)) {
                self::debug($kernel, 'option {option} of {group} has no matching submitted field', ['option' => $key, 'group' => $optionGroupClass]);
                continue;
            }

            $converted[] = [$key, self::toOptionValue($type, $values[$key], $key, $optionGroupClass)];
        }

        foreach ($converted as [$key, $value]) {
            // set()の戻り値のfalseは「保存値が変わらなかった」の意味で、失敗ではない(失敗は例外)
            $repository->set($key, $value);
        }

        foreach (array_keys($values) as $fieldName) {
            if (!\in_array($fieldName, $schemaKeys, true)) {
                self::debug($kernel, 'submitted field {field} has no matching option of {group}', ['field' => $fieldName, 'group' => $optionGroupClass]);
            }
        }
    }

    /**
     * 保存値を項目の既定値の形にする。型の形でない保存値はnull。
     *
     * 型の形には、WordPressのoptionsテーブルを経た形も含める。update_option()はintやboolを文字列で保存し、次の
     * リクエストのget_option()は文字列を返すため(5は'5'、trueは'1'、falseは'')。
     *
     * @throws LogicException object型の場合
     *
     * @return string|list<string>|null
     */
    private static function toFormValue(MetaValueType $type, mixed $value, string $key, string $optionGroupClass): string|array|null
    {
        return match ($type) {
            MetaValueType::String => \is_scalar($value) ? ValueCaster::toString($value) : null,
            MetaValueType::Integer => \is_int($value) ? (string) $value : (\is_string($value) && self::toInt($value) !== null ? $value : null),
            MetaValueType::Number => \is_int($value) || \is_float($value) ? (string) $value : (\is_string($value) && self::toNumber($value) !== null ? $value : null),
            MetaValueType::Boolean => self::boolToFormValue($value),
            MetaValueType::Array => self::listToFormValue($value),
            MetaValueType::Object => throw self::objectTypeException($key, $optionGroupClass),
        };
    }

    private static function boolToFormValue(mixed $value): ?string
    {
        if (\in_array($value, [true, 1, '1'], true)) {
            return '1';
        }

        if (\in_array($value, [false, 0, '0', ''], true)) {
            return '';
        }

        return null;
    }

    /** @return list<string>|null */
    private static function listToFormValue(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }

        $items = [];

        foreach ($value as $item) {
            if (!\is_scalar($item)) {
                return null;
            }

            $items[] = ValueCaster::toString($item);
        }

        return $items;
    }

    /**
     * Formの値をスキーマの型の値にする。
     *
     * @throws LogicException 変換できない場合、object型の場合
     */
    private static function toOptionValue(MetaValueType $type, mixed $value, string $key, string $optionGroupClass): mixed
    {
        $converted = match ($type) {
            MetaValueType::String => \is_string($value) ? $value : null,
            MetaValueType::Integer => \is_string($value) ? self::toInt($value) : null,
            MetaValueType::Number => \is_string($value) ? self::toNumber($value) : null,
            MetaValueType::Boolean => match ($value) {
                '1' => true,
                '' => false,
                default => null,
            },
            MetaValueType::Array => self::isStringList($value) ? $value : null,
            MetaValueType::Object => throw self::objectTypeException($key, $optionGroupClass),
        };

        if ($converted === null) {
            // 利用者の入力値はメッセージに入れない(例外がログに記録されうるため)
            throw new LogicException("the submitted value of field '{$key}' (" . get_debug_type($value) . ") cannot be converted to the {$type->value} type of option '{$key}' of {$optionGroupClass}; add rules to the field that accept only " . self::acceptedFormValue($type));
        }

        return $converted;
    }

    private static function acceptedFormValue(MetaValueType $type): string
    {
        return match ($type) {
            MetaValueType::String => 'a string',
            MetaValueType::Integer => 'integer strings',
            MetaValueType::Number => 'numeric strings',
            MetaValueType::Boolean => "'1' or '' (a single checkbox with the checked value '1')",
            MetaValueType::Array => 'a list of strings (a multiple choice field)',
            MetaValueType::Object => 'nothing',
        };
    }

    /** 整数の文字列(先頭の`+`と0を許す)をintにする。整数でない・intの範囲を超える場合はnull */
    private static function toInt(string $value): ?int
    {
        if (preg_match(self::INTEGER_PATTERN, $value, $matches) !== 1) {
            return null;
        }

        $digits = ltrim($matches[2], '0');

        if ($digits === '') {
            return 0;
        }

        $normalized = ($matches[1] === '-' ? '-' : '') . $digits;
        $int = (int) $normalized;

        return (string) $int === $normalized ? $int : null;
    }

    /** 数値の文字列を、整数ならint、それ以外は有限のfloatにする。数値でない場合はnull */
    private static function toNumber(string $value): int|float|null
    {
        if (!is_numeric($value)) {
            return null;
        }

        $int = self::toInt(trim($value));

        if ($int !== null) {
            return $int;
        }

        $float = (float) $value;

        return is_finite($float) ? $float : null;
    }

    /** @phpstan-assert-if-true list<string> $value */
    private static function isStringList(mixed $value): bool
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return false;
        }

        foreach ($value as $item) {
            if (!\is_string($item)) {
                return false;
            }
        }

        return true;
    }

    private static function objectTypeException(string $key, string $optionGroupClass): LogicException
    {
        return new LogicException("option '{$key}' of {$optionGroupClass} has the object type, which cannot be bound to a form field; rename the field or the option, or save the option in onSubmit() yourself");
    }

    /**
     * オプショングループのKernel。そのKernelにSettingsPagesを登録していなければ、設定の誤りとして例外にする。
     *
     * @param class-string $optionGroupClass
     *
     * @throws LogicException
     */
    private static function kernel(string $optionGroupClass): Kernel
    {
        $kernel = Kernel::forClass($optionGroupClass);
        SettingsPages::forKernel($kernel);

        return $kernel;
    }

    /**
     * @param class-string $optionGroupClass
     */
    private static function repository(Kernel $kernel, string $optionGroupClass): OptionRepository
    {
        return $kernel->container()->get(OptionRepositoryFactory::class)->for($optionGroupClass);
    }

    /**
     * `#[OptionSchema]`を付けたケースのキーと型(宣言の順)。
     *
     * @param class-string $optionGroupClass
     *
     * @throws InvalidSchemaException enumでない場合、ケースのbacking値が文字列でない場合
     *
     * @return list<array{string, MetaValueType}>
     */
    private static function schema(string $optionGroupClass): array
    {
        if (!enum_exists($optionGroupClass)) {
            throw new InvalidSchemaException("option group must be an enum: {$optionGroupClass}");
        }

        $reflection = new ReflectionEnum($optionGroupClass);
        $schema = [];

        foreach ($reflection->getCases() as $case) {
            $attributes = $case->getAttributes(OptionSchema::class);

            if (!$case instanceof ReflectionEnumBackedCase || $attributes === []) {
                continue;
            }

            $value = $case->getBackingValue();

            if (!\is_string($value)) {
                throw new InvalidSchemaException("option group case must use string backing value: {$optionGroupClass}::{$case->getName()}");
            }

            $schema[] = [$value, $attributes[0]->newInstance()->type];
        }

        return $schema;
    }

    /**
     * 片側にしかない名前を、Kernelのロガーにdebugで記録する。
     *
     * @param array<string, string> $context
     */
    private static function debug(Kernel $kernel, string $message, array $context): void
    {
        $kernel->logger()->debug('tmswp.settings-page: ' . $message, $context);
    }
}
