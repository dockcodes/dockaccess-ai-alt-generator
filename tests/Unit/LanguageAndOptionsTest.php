<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Unit;

use Dock\AltGenerator\Exception\ConfigurationException;
use Dock\AltGenerator\GenerateOptions;
use Dock\AltGenerator\Language;
use Dock\AltGenerator\Tone;
use PHPUnit\Framework\TestCase;

final class LanguageAndOptionsTest extends TestCase
{
    /**
     * @dataProvider locales
     */
    public function testMapsCmsLocalesOntoGeneratorCodes(string $locale, ?string $code): void
    {
        self::assertSame($code, Language::tryNormalize($locale));
    }

    /**
     * @return iterable<array{string, ?string}>
     */
    public function locales(): iterable
    {
        yield ['pl_PL', 'pl'];
        yield ['en-GB', 'en'];
        yield ['EN', 'en'];
        yield ['uk', 'uk'];
        yield ['zh-TW', 'zh-Hant'];
        yield ['zh_CN', 'zh-Hans'];
        yield ['zh-Hant-HK', 'zh-Hant'];
        yield ['nb_NO', 'no'];
        yield ['fil-PH', 'fil'];
        yield ['csb', 'csb'];
        yield ['xx-YY', null];
        yield ['', null];
        yield ['pl&key=x', null];
        yield ["pl\n", 'pl'];
    }

    public function testFallsBackForUnknownLocales(): void
    {
        self::assertSame('en', Language::normalize('tlh'));
        self::assertSame('pl', Language::normalize('tlh', 'pl'));
    }

    public function testOptionsBuildTheQueryWithoutTheKey(): void
    {
        $options = GenerateOptions::create()
            ->withLanguage('pl_PL')
            ->withTone(Tone::DESCRIPTIVE)
            ->withVariants(3)
            ->withContext("<i>Sklep</i> z ceramiką\u{202E}")
            ->withSetRef('site-token-1')
            ->withSetUuid('A1B2C3D4-0000-4000-8000-000000000001');

        self::assertSame([
            'lang'     => 'pl',
            'tone'     => 'descriptive',
            'variants' => '3',
            'context'  => 'Sklep z ceramiką',
            'set_ref'  => 'site-token-1',
            'set_uuid' => 'a1b2c3d4-0000-4000-8000-000000000001',
        ], $options->toQuery());
    }

    public function testContextIsCutNotRefused(): void
    {
        self::assertSame(500, mb_strlen(GenerateOptions::create()->withContext(str_repeat('ą', 900))->context()));
    }

    public function testOptionsAreImmutable(): void
    {
        $base = GenerateOptions::create();
        $base->withLanguage('de');

        self::assertSame('en', $base->language());
    }

    /**
     * @dataProvider invalidOptions
     */
    public function testRefusesInvalidOptions(callable $build): void
    {
        $this->expectException(ConfigurationException::class);

        $build(GenerateOptions::create());
    }

    /**
     * @return iterable<string, array{callable}>
     */
    public function invalidOptions(): iterable
    {
        yield 'language' => [static function (GenerateOptions $o) { return $o->withLanguage('klingon'); }];
        yield 'tone' => [static function (GenerateOptions $o) { return $o->withTone('sarcastic'); }];
        yield 'variants 0' => [static function (GenerateOptions $o) { return $o->withVariants(0); }];
        yield 'variants 4' => [static function (GenerateOptions $o) { return $o->withVariants(4); }];
        yield 'empty ref' => [static function (GenerateOptions $o) { return $o->withSetRef('  '); }];
        yield 'ref with newline' => [static function (GenerateOptions $o) { return $o->withSetRef("a\nb"); }];
        yield 'long ref' => [static function (GenerateOptions $o) { return $o->withSetRef(str_repeat('a', 256)); }];
        yield 'uuid' => [static function (GenerateOptions $o) { return $o->withSetUuid('../../etc/passwd'); }];
    }
}
