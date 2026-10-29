<?php

declare(strict_types=1);

namespace TMSWP\SettingsPage\Tests\Support;

use TMSWP\Core\Kernel\Discovery;
use TMSWP\Core\Kernel\DiscoveryFile;
use TMSWP\Core\Kernel\ExtensionInterface;
use TMSWP\Core\Kernel\Kernel;
use TMSWP\Core\Kernel\KernelConfig;
use TMSWP\Core\Log\LogChannel;
use TMSWP\SettingsPage\SettingsPages;
use TMSWP\Testing\MemoryMetaStorage;
use TMSWP\Testing\MemoryOptionStorage;
use TMSWP\Testing\RecordingLogger;
use TMSWP\Testing\StorageExtension;

/**
 * ユニットテストで、一時ディレクトリのアプリにSettingsPagesを登録したKernel(テーマ形態)をbootする。オプションは
 * メモリに保存し(MemoryOptionStorage)、ログはRecordingLoggerに記録する。
 *
 * 使うテストはUnitTestCaseを継承し、setUpで`$GLOBALS['wp_version']`(7.0以上)と`$GLOBALS['wp_actions']`を置き、
 * tearDownでKernel::reset()を呼ぶ。
 */
trait BootsSettingsPagesKernel
{
    /**
     * @param array<string, string> $appFiles appDirからの相対パス => 内容。{namespace}はアプリの名前空間に置き換える
     * @param list<ExtensionInterface>|null $extensions nullなら`new SettingsPages()`だけ(ストレージの差し替えは常に足す)
     * @param bool $debug falseなら本番のboot(探索結果ファイルを先に作る)
     *
     * @return array{kernel: Kernel, namespace: string, options: MemoryOptionStorage, logger: RecordingLogger}
     */
    private function bootSettingsPagesKernel(string $suffix, array $appFiles = [], ?array $extensions = null, bool $debug = true): array
    {
        $root = $this->createTempDirectory('settings-page-' . strtolower($suffix));
        $namespace = 'Example\Tests\SettingsPage' . $suffix . uniqid();
        self::assertTrue(mkdir($root . '/app'));

        foreach ($appFiles as $path => $contents) {
            $file = $root . '/app/' . $path;

            if (!is_dir(\dirname($file))) {
                self::assertTrue(mkdir(\dirname($file), 0777, true));
            }

            self::assertNotFalse(file_put_contents($file, str_replace('{namespace}', $namespace, $contents)));
        }

        spl_autoload_register(static function (string $class) use ($namespace, $root): void {
            $prefix = $namespace . '\\';

            if (str_starts_with($class, $prefix) && is_file($file = $root . '/app/' . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php')) {
                require $file;
            }
        });

        $options = new MemoryOptionStorage();
        $logger = new RecordingLogger();
        $config = KernelConfig::theme(
            $root . '/app',
            $namespace,
            $debug,
            [new StorageExtension(new MemoryMetaStorage(), $options), ...($extensions ?? [new SettingsPages()])],
            static fn(): array => ['test' => new LogChannel($logger)],
        );

        if (!$debug) {
            (new DiscoveryFile($config))->write((new Discovery($config, new DiscoveryFile($config)))->scan());
        }

        return ['kernel' => Kernel::boot($config), 'namespace' => $namespace, 'options' => $options, 'logger' => $logger];
    }
}
