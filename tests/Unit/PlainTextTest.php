<?php

declare(strict_types=1);

namespace Dock\AltGenerator\Tests\Unit;

use Dock\AltGenerator\Text\PlainText;
use PHPUnit\Framework\TestCase;

final class PlainTextTest extends TestCase
{
    public function testStripsMarkupAndCollapsesWhitespace(): void
    {
        self::assertSame('A red bicycle against a wall', PlainText::clean("<b>A red</b>\n bicycle\t against <i>a</i> wall<br/> ", 200));
    }

    public function testRemovesControlAndInvisibleCharacters(): void
    {
        // RLO/PDF reverse the display ("Trojan Source"), zero-width space and joiner, BOM, NUL, bell, C1.
        $text = "Cat\u{202E}god\u{202C} on\u{200B} a\u{200D} sofa\u{FEFF}\0\x07\u{0085}";

        self::assertSame('Catgod on a sofa', PlainText::clean($text, 200));
    }

    public function testSeparatorsBecomeSpaces(): void
    {
        self::assertSame('one two', PlainText::clean("one\u{2028}two", 200));
    }

    public function testDropsInvalidUtf8ButKeepsTheRest(): void
    {
        self::assertSame('Zółw na plaży', PlainText::clean("Z\xC3\xB3\xC5\x82w na pla\xFF\xFE\xC5\xBCy", 200));
    }

    public function testTruncatesOnCharactersNotBytes(): void
    {
        self::assertSame('Źdźbło', PlainText::clean('Źdźbło trawy', 6));
        self::assertSame('', PlainText::clean('anything', 0));
    }
}
