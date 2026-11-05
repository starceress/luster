<?php

declare(strict_types=1);

namespace Luster\Tests\Unit;

use Luster\Import\CodeIdentity;
use PHPUnit\Framework\TestCase;

/**
 * Which stored code a code being imported is, row by row through the plan's table.
 * The first row that fits decides.
 */
final class CodeIdentityTest extends TestCase
{
    private const FILE = 'code.png';

    private static function long(string $ending = ''): string
    {
        return str_repeat('a', 155) . $ending . '.png';
    }

    private static function row(int $id, ?string $name, ?string $src = null): array
    {
        return ['id' => $id, 'name' => $name, 'src' => $src];
    }

    public function testNamesAreCappedInCharacters(): void
    {
        self::assertSame(self::FILE, CodeIdentity::cappedName(self::FILE));
        self::assertSame(str_repeat('a', 150), CodeIdentity::cappedName(self::long()));
        self::assertSame(str_repeat('é', 150), CodeIdentity::cappedName(str_repeat('é', 160)));
    }

    public function testAFilenameOver255CharactersCantBeTracked(): void
    {
        $tooLong = str_repeat('a', 256);
        self::assertStringContainsString('longer than 255', (string) CodeIdentity::cantTrack($tooLong, true));
        self::assertStringContainsString('longer than 255', (string) CodeIdentity::cantTrack($tooLong, false));
        self::assertNull(CodeIdentity::cantTrack(str_repeat('a', 255), true));
        self::assertNull(CodeIdentity::cantTrack(str_repeat('é', 255), true), 'Characters, not bytes');
    }

    public function testWithoutTheColumnANameOver150CharactersCantBeTracked(): void
    {
        self::assertStringContainsString('Migration 104', (string) CodeIdentity::cantTrack(str_repeat('a', 151), false));
        self::assertNull(CodeIdentity::cantTrack(str_repeat('a', 150), false));
        self::assertNull(CodeIdentity::cantTrack(str_repeat('a', 151), true));
    }

    public function testWithoutTheColumnItIsTheExactNameMatch(): void
    {
        self::assertSame(CodeIdentity::INSERT, CodeIdentity::decide(self::FILE, false, false, [])['action']);

        $found = CodeIdentity::decide(self::FILE, false, false, [self::row(7, 'code.png'), self::row(8, 'code.png')]);
        self::assertSame([CodeIdentity::EXISTING, 7, false], [$found['action'], $found['id'], $found['tag']], 'The first one, as today, with nothing to tag');

        self::assertSame(CodeIdentity::INSERT, CodeIdentity::decide(self::FILE, false, false, [self::row(7, 'Code.png')])['action'], 'Byte for byte');
        self::assertSame(CodeIdentity::EXISTING, CodeIdentity::decide(self::FILE, false, true, [self::row(7, 'code.png')])['action'], 'Collisions only mean anything with the column');
    }

    public function testOneRowTaggedWithThisFileIsThatRowWhateverItsNameSays(): void
    {
        foreach ([false, true] as $collides) {
            $found = CodeIdentity::decide(self::FILE, true, $collides, [
                self::row(1, 'code.png'),
                self::row(2, 'Renamed', 'code.png'),
            ]);
            self::assertSame([CodeIdentity::EXISTING, 2, false], [$found['action'], $found['id'], $found['tag']]);
        }
    }

    public function testATagIsComparedByteForByte(): void
    {
        self::assertSame(CodeIdentity::INSERT, CodeIdentity::decide(self::FILE, true, false, [self::row(1, 'x', 'Code.png')])['action']);
    }

    public function testMoreThanOneRowTaggedWithThisFileCantBeToldApart(): void
    {
        $found = CodeIdentity::decide(self::FILE, true, false, [self::row(1, 'a', 'code.png'), self::row(2, 'b', 'code.png')]);

        self::assertSame(CodeIdentity::SKIP, $found['action']);
        self::assertStringContainsString("can't be told apart", (string) $found['reason']);
    }

    public function testAnUntaggedRowWithTheCappedNameAndACollisionIsLeftAlone(): void
    {
        $found = CodeIdentity::decide(self::long('1'), true, true, [self::row(1, CodeIdentity::cappedName(self::long()))]);

        self::assertSame(CodeIdentity::SKIP, $found['action']);
        self::assertStringContainsString('first 150 characters', (string) $found['reason']);
    }

    public function testMoreThanOneUntaggedRowCantBeToldApart(): void
    {
        $found = CodeIdentity::decide(self::FILE, true, false, [self::row(1, 'code.png'), self::row(2, 'code.png')]);

        self::assertSame(CodeIdentity::SKIP, $found['action']);
        self::assertStringContainsString("can't be told apart", (string) $found['reason']);
    }

    public function testOneUntaggedRowWithNoCollisionIsAdopted(): void
    {
        $found = CodeIdentity::decide(self::long(), true, false, [self::row(4, CodeIdentity::cappedName(self::long()))]);

        self::assertSame([CodeIdentity::EXISTING, 4, true], [$found['action'], $found['id'], $found['tag']]);
    }

    public function testAnUntaggedRowIsComparedByteForByte(): void
    {
        self::assertSame(CodeIdentity::INSERT, CodeIdentity::decide(self::FILE, true, false, [self::row(1, 'Code.png')])['action']);
    }

    public function testNothingFoundIsAnInsert(): void
    {
        self::assertSame(CodeIdentity::INSERT, CodeIdentity::decide(self::FILE, true, false, [])['action']);
        self::assertSame(CodeIdentity::INSERT, CodeIdentity::decide(self::long('1'), true, true, [])['action']);
        self::assertSame(
            CodeIdentity::INSERT,
            CodeIdentity::decide(self::long('1'), true, true, [self::row(1, CodeIdentity::cappedName(self::long()), self::long('2'))])['action'],
            "Another file's row is nothing to do with this one"
        );
        self::assertSame(CodeIdentity::INSERT, CodeIdentity::decide(self::FILE, true, false, [self::row(1, null), self::row(2, '')])['action']);
    }

    public function testFilesCollideWhenTheyShareTheirCappedName(): void
    {
        self::assertSame(
            [self::long('1') => true, self::long('2') => true],
            CodeIdentity::colliding([self::long('1'), self::long('2'), self::long('1'), 'other.png'])
        );
        self::assertSame([], CodeIdentity::colliding([self::long('1'), self::long('1')]), 'One file listed twice is one file');
        self::assertSame([], CodeIdentity::colliding(['Code.png', 'code.png']), 'These differ within the first 150 characters');
        self::assertSame(
            [str_repeat('a', 150) => true, self::long() => true],
            CodeIdentity::colliding([str_repeat('a', 150), self::long()]),
            'A name of exactly 150 characters collides with a longer one'
        );
    }

    public function testRowsCanBeUpdatedToMatchAWrite(): void
    {
        $rows = CodeIdentity::afterInsert([], 5, self::long(), true);
        self::assertSame([['id' => 5, 'name' => CodeIdentity::cappedName(self::long()), 'src' => self::long()]], $rows);
        self::assertSame([['id' => 5, 'name' => 'x', 'src' => null]], CodeIdentity::afterInsert([], 5, 'x', false));

        $tagged = CodeIdentity::afterTagging([self::row(1, 'a'), self::row(2, 'b')], 2, 'b.png');
        self::assertSame([self::row(1, 'a'), self::row(2, 'b', 'b.png')], $tagged);
    }
}
