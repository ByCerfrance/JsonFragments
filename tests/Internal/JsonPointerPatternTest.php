<?php

declare(strict_types=1);

namespace ByCerfrance\JsonFragments\Tests\Internal;

use ByCerfrance\JsonFragments\Internal\JsonPointer;
use ByCerfrance\JsonFragments\Internal\JsonPointerPattern;
use ByCerfrance\JsonFragments\JsonFragment;
use ByCerfrance\JsonFragments\JsonReference;
use ByCerfrance\JsonFragments\Resolver\JsonReferenceResolverInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonPointerPattern::class)]
#[UsesClass(JsonPointer::class)]
#[UsesClass(JsonFragment::class)]
#[UsesClass(JsonReference::class)]
final class JsonPointerPatternTest extends TestCase
{
    public function testMultipleWildcardsAcrossObjectsAndLists(): void
    {
        $input = (object)['items' => [
            (object)['detail' => [(object)['test' => null], (object)['test' => 42]]],
            (object)['detail' => (object)['a/b~c' => (object)['test' => false]]],
        ]];
        $before = json_encode($input);

        self::assertSame([
            '/items/0/detail/0/test',
            '/items/0/detail/1/test',
            '/items/1/detail/a~1b~0c/test',
        ], JsonPointerPattern::expand($input, '/items/*/detail/*/test'));
        self::assertSame($before, json_encode($input));
    }

    public function testTerminalWildcardIncludesNullAndLiteralStarKeys(): void
    {
        self::assertSame(
            ['/items/0', '/items/1'],
            JsonPointerPattern::expand(['items' => [null, 42]], '/items/*'),
        );
        self::assertSame(
            ['/items/*', '/items/'],
            JsonPointerPattern::expand(['items' => ['*' => null, '' => []]], '/items/*'),
        );
    }

    public function testMissingBranchesAndScalarsAreIgnored(): void
    {
        $input = ['items' => [null, 42, [], ['detail' => []], ['detail' => [['other' => true]]]]];

        self::assertSame([], JsonPointerPattern::expand($input, '/items/*/detail/*/test'));
        self::assertSame([], JsonPointerPattern::expand($input, '/missing/*'));
        self::assertSame([], JsonPointerPattern::expand((object)[], '/*'));
    }

    public function testExactPointersRootAndEscapes(): void
    {
        self::assertSame([''], JsonPointerPattern::expand(null, ''));
        self::assertSame(['/'], JsonPointerPattern::expand((object)['' => null], '/'));
        self::assertSame(
            ['/a~1b/~0'],
            JsonPointerPattern::expand(['a/b' => ['~' => null]], '/a~1b/~0'),
        );
        self::assertSame(['/0'], JsonPointerPattern::expand([null], '/0'));
        self::assertSame([], JsonPointerPattern::expand([null], '/1'));
    }

    public function testReferencesAndFragmentsAreTerminalWithoutResolution(): void
    {
        $resolver = $this->createMock(JsonReferenceResolverInterface::class);
        $resolver->method('supports')->willReturn(true);
        $resolver->expects(self::never())->method('resolve');
        $reference = new JsonReference('jsonfragment://example.json');
        $fragment = new JsonFragment($reference, $resolver);
        $input = ['items' => [$reference, $fragment]];

        self::assertSame(['/items/0', '/items/1'], JsonPointerPattern::expand($input, '/items/*'));
        self::assertSame([], JsonPointerPattern::expand($input, '/items/*/*'));
        self::assertFalse($fragment->isLoaded());
    }

    public function testInvalidPointerIsRejectedEvenWithoutMatches(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonPointerPattern::expand([], '/missing/~2');
    }

    public function testInvalidListIndexIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonPointerPattern::expand(['items' => [[42]]], '/items/*/00');
    }
}
