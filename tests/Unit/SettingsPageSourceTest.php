<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage\Tests\Unit;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use TMSWP\Testing\UnitTestCase;

/**
 * docs/08の受け入れ基準のうち、settings-pageのソースの形で決まる項目(exitの位置・ファイルのinclude・独自の探索)を、
 * src/のトークンで確かめる。
 */
final class SettingsPageSourceTest extends UnitTestCase
{
    /** ファイルの一覧やクラスの一覧を得る関数・クラス(独自の探索のコードに要るもの) */
    private const DISCOVERY_NAMES = ['scandir', 'glob', 'opendir', 'readdir', 'get_declared_classes', 'DirectoryIterator', 'RecursiveDirectoryIterator', 'FilesystemIterator'];

    /**
     * exit(die)は、保存の後のwp_safe_redirect()の直後の1箇所だけにある(00-overview.mdの原則1の例外。D-35)。
     */
    public function testExitIsOnlyRightAfterTheRedirectOfTheSubmit(): void
    {
        self::assertSame([], self::exitViolations(self::sourceTokens()));
    }

    /**
     * src/のトークンのキーは、src/からの相対パス(区切りは`/`)である。別のディレクトリの同じ名前のファイルを、互いに
     * 上書きしないため。
     */
    public function testSourceTokensAreKeyedByThePathRelativeToSrc(): void
    {
        $paths = array_keys(self::sourceTokens());

        self::assertContains('PageRegistrar.php', $paths);
        self::assertContains('Attribute/AsSettingsPage.php', $paths);
        self::assertSame([], array_values(array_filter($paths, static fn(string $path): bool => str_starts_with($path, '/') || str_contains($path, '\\'))));
    }

    /** @return iterable<string, array{string}> exitの検査を通るPageRegistrar.phpのソース */
    public static function allowedExitSources(): iterable
    {
        yield '関数名' => ["<?php\nfunction f(): void\n{\n    wp_safe_redirect(\$url);\n    exit;\n}\n"];
        yield '完全修飾の関数名' => ["<?php\nfunction f(): void\n{\n    \\wp_safe_redirect(\$url);\n    exit;\n}\n"];
        yield 'あいだのコメント' => ["<?php\nfunction f(): void\n{\n    wp_safe_redirect(\$url);\n    // 原則1の例外\n    exit;\n}\n"];
        yield 'die' => ["<?php\nfunction f(): void\n{\n    wp_safe_redirect(\$url);\n    die;\n}\n"];
    }

    /** exitの検査の正の対照: 動作の同じ書き方(完全修飾の関数名等)を受け入れる。 */
    #[DataProvider('allowedExitSources')]
    public function testExitCheckAcceptsTheRedirectWrittenInAnyEquivalentForm(string $source): void
    {
        self::assertSame([], self::exitViolations(['PageRegistrar.php' => self::tokenize($source)]));
    }

    /** @return iterable<string, array{array<string, string>}> exitの検査で拒むソース(src/からの相対パス => ソース) */
    public static function forbiddenExitSources(): iterable
    {
        yield 'exitが無い' => [['PageRegistrar.php' => "<?php\nwp_safe_redirect(\$url);\n"]];
        yield 'redirectの削除' => [['PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    exit;\n}\n"]];
        yield 'redirectの前へのexitの移動' => [['PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    exit;\n    wp_safe_redirect(\$url);\n}\n"]];
        yield 'あいだに別の文' => [['PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    wp_safe_redirect(\$url);\n    do_action('x');\n    exit;\n}\n"]];
        yield '2つ目のexit' => [['PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    wp_safe_redirect(\$url);\n    exit;\n}\nfunction g(): void\n{\n    exit;\n}\n"]];
        yield '別のファイルのexit' => [['SettingsPage.php' => "<?php\nfunction f(): void\n{\n    wp_safe_redirect(\$url);\n    exit;\n}\n"]];
        yield '別の関数(wp_redirect)' => [['PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    wp_redirect(\$url);\n    exit;\n}\n"]];
        yield '別の名前空間の同じ名前の関数' => [['PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    \\Other\\wp_safe_redirect(\$url);\n    exit;\n}\n"]];
        yield '同じ名前のメソッド' => [['PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    \$this->wp_safe_redirect(\$url);\n    exit;\n}\n"]];
        yield '戻り値の代入' => [['PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    \$done = wp_safe_redirect(\$url);\n    exit;\n}\n"]];
        yield '同じ行の条件' => [['PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    wp_safe_redirect(\$url);\n    if (\$x) exit;\n}\n"]];
        // 既存のファイルと同じ名前の、別のディレクトリのファイル(F3の回帰)
        yield '別のディレクトリの同じ名前のファイルのexit' => [[
            'PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    wp_safe_redirect(\$url);\n    exit;\n}\n",
            'SettingsPage.php' => "<?php\nfunction g(): void\n{\n}\n",
            'Extra/SettingsPage.php' => "<?php\nfunction h(): void\n{\n    exit;\n}\n",
        ]];
        yield '別のディレクトリのPageRegistrar.phpのexit' => [[
            'PageRegistrar.php' => "<?php\nfunction f(): void\n{\n}\n",
            'Extra/PageRegistrar.php' => "<?php\nfunction f(): void\n{\n    wp_safe_redirect(\$url);\n    exit;\n}\n",
        ]];
    }

    /**
     * exitの検査の負の対照: exitの移動・redirectの削除・別の関数等を拒む。
     *
     * @param array<string, string> $sources src/からの相対パス => ソース
     */
    #[DataProvider('forbiddenExitSources')]
    public function testExitCheckRejectsForbiddenChanges(array $sources): void
    {
        self::assertNotSame([], self::exitViolations(array_map(self::tokenize(...), $sources)));
    }

    /**
     * 旧`action`クエリパラメータに相当するファイルのinclude(include・require)が無く、`#[AsSettingsPage]`のクラスを
     * 探すための独自の探索(ディレクトリの走査・宣言済みのクラスの一覧)も無い(コアのDiscoveryの収集だけを使う)。
     */
    public function testNoFileIncludeAndNoOwnDiscovery(): void
    {
        $found = [];

        foreach (self::sourceTokens() as $file => $tokens) {
            foreach ($tokens as $token) {
                if ($token->is([T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE]) || ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) && \in_array(ltrim($token->text, '\\'), self::DISCOVERY_NAMES, true))) {
                    $found[] = $file . ':' . $token->line . ' ' . $token->text;
                }
            }
        }

        self::assertSame([], $found);
    }

    /**
     * exitの規則に反する箇所。exit(die)が、src/の直下のPageRegistrar.phpの1箇所だけにあり、その直前の文がグローバルの
     * wp_safe_redirect()の呼び出し(関数名、または先頭の`\`の完全修飾名)であることを確かめる。
     *
     * @param array<string, list<PhpToken>> $sources src/からの相対パス(sourceTokens()のキーと同じ規則) => トークン
     *
     * @return list<string> 反する箇所の説明。規則を満たせば空
     */
    private static function exitViolations(array $sources): array
    {
        $exits = [];

        foreach ($sources as $file => $tokens) {
            foreach ($tokens as $index => $token) {
                if ($token->is(T_EXIT)) {
                    $exits[] = [$file, $index];
                }
            }
        }

        if (\count($exits) !== 1) {
            return [\count($exits) . ' exit(s) found; exactly one is allowed'];
        }

        [$file, $index] = $exits[0];

        if ($file !== 'PageRegistrar.php') {
            return ["exit is in {$file}, not in PageRegistrar.php directly under src/"];
        }

        $statement = self::previousStatement($sources[$file], $index);
        $name = $statement[0] ?? null;

        if (!$name instanceof PhpToken || !$name->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || ltrim($name->text, '\\') !== 'wp_safe_redirect' || ($statement[1] ?? null)?->text !== '(') {
            return ['the statement right before exit is not a call of wp_safe_redirect()'];
        }

        return [];
    }

    /**
     * ソースのトークン(空白・コメントを含む)。
     *
     * @return list<PhpToken>
     */
    private static function tokenize(string $source): array
    {
        return array_values(PhpToken::tokenize($source));
    }

    /**
     * src/の各ファイルのトークン(空白・コメントを含む)。
     *
     * @return array<string, list<PhpToken>> src/からの相対パス(区切りは`/`) => トークン
     */
    private static function sourceTokens(): array
    {
        $files = [];
        $src = \dirname(__DIR__, 2) . '/src';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            // .DS_Store等の追跡外のファイルでアサーションの数が変わらないよう、絞り込みはアサーションにしない
            if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            self::assertIsString($contents);
            $files[str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), \strlen($src) + 1))] = self::tokenize($contents);
        }

        self::assertNotSame([], $files);
        ksort($files);

        return $files;
    }

    /**
     * `$index`のトークンの直前の文(1つ前の`;`から、その前の`;`・`{`・`}`の後まで)の、空白とコメントを除いたトークン。
     * 直前が文の終わり(`;`)でなければ空。
     *
     * @param list<PhpToken> $tokens
     *
     * @return list<PhpToken>
     */
    private static function previousStatement(array $tokens, int $index): array
    {
        $end = $index - 1;

        while ($end >= 0 && $tokens[$end]->isIgnorable()) {
            --$end;
        }

        if ($end < 0 || $tokens[$end]->text !== ';') {
            return [];
        }

        $statement = [];

        for ($current = $end - 1; $current >= 0 && !\in_array($tokens[$current]->text, [';', '{', '}'], true); --$current) {
            if (!$tokens[$current]->isIgnorable()) {
                array_unshift($statement, $tokens[$current]);
            }
        }

        return $statement;
    }
}
