<?php

namespace GlueAgency\Influx\Tests\unit\fields;

use Codeception\Test\Unit;
use craft\base\ElementInterface;
use GlueAgency\Influx\fields\Assets;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\sync\FieldContext;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;
use RuntimeException;

/**
 * Behaviour spec for the URL-mode asset match
 * ({@see Assets::matchExistingByUrl()}).
 *
 * The contract the docblock promises: among the same-filename candidates, the
 * one whose `getUrl()` equals the remote URL wins; when none does, the first
 * candidate stands as a best-effort match so a CDN/host change doesn't force a
 * re-download. The preference used to be unreachable — the lookup had already
 * collapsed to a single row, so both branches returned the same asset.
 *
 * A volume that exposes no URLs makes `getUrl()` throw; that must skip the one
 * candidate, not abandon the rest.
 */
class AssetUrlMatchTest extends Unit
{
    public function testTheCandidateWhoseUrlMatchesExactlyIsPreferred(): void
    {
        $first = $this->candidate(1, 'https://cdn.example.com/old/photo.jpg');
        $second = $this->candidate(2, 'https://cdn.example.com/2024/photo.jpg');

        $strategy = $this->strategy([$first, $second]);

        $this->assertSame($second, $strategy->match($this->context(), 'https://cdn.example.com/2024/photo.jpg'));
        $this->assertSame('photo.jpg', $strategy->queriedFilename);
    }

    public function testTheFirstCandidateStandsWhenNoUrlMatches(): void
    {
        $first = $this->candidate(1, 'https://old-host.test/photo.jpg');
        $second = $this->candidate(2, 'https://other-host.test/photo.jpg');

        $strategy = $this->strategy([$first, $second]);

        $this->assertSame($first, $strategy->match($this->context(), 'https://cdn.example.com/2024/photo.jpg'));
    }

    public function testACandidateWithoutAUrlDoesNotAbandonTheRest(): void
    {
        $urlless = $this->createMock(ElementInterface::class);
        $urlless->method('getUrl')->willThrowException(new RuntimeException('Volume has no public URLs.'));
        $match = $this->candidate(2, 'https://cdn.example.com/2024/photo.jpg');

        $strategy = $this->strategy([$urlless, $match]);

        $this->assertSame($match, $strategy->match($this->context(), 'https://cdn.example.com/2024/photo.jpg'));
    }

    public function testNoCandidatesMeansNoMatch(): void
    {
        $strategy = $this->strategy([]);

        $this->assertNull($strategy->match($this->context(), 'https://cdn.example.com/2024/photo.jpg'));
    }

    public function testAUrlWithoutAFilenameIsNeverLookedUp(): void
    {
        $strategy = $this->strategy([$this->candidate(1, 'https://cdn.example.com/photo.jpg')]);

        $this->assertNull($strategy->match($this->context(), 'https://cdn.example.com/'));
        $this->assertNull($strategy->queriedFilename);
    }

    /**
     * An Assets strategy with the candidate query stubbed out, recording the
     * filename it was asked for.
     *
     * @param list<ElementInterface> $candidates
     */
    protected function strategy(array $candidates): Assets
    {
        return new class($candidates) extends Assets {
            /** @var list<ElementInterface> */
            public array $candidates = [];

            public ?string $queriedFilename = null;

            public function __construct(array $candidates)
            {
                $this->candidates = $candidates;
            }

            public function match(FieldContext $context, string $url): ?ElementInterface
            {
                return $this->matchExistingByUrl($context, $url);
            }

            protected function candidatesByFilename(FieldContext $context, string $filename): array
            {
                $this->queriedFilename = $filename;

                return $this->candidates;
            }
        };
    }

    protected function candidate(int $id, string $url): ElementInterface
    {
        $asset = $this->createMock(ElementInterface::class);
        $asset->method('getUrl')->willReturn($url);
        $asset->id = $id;

        return $asset;
    }

    protected function context(): FieldContext
    {
        return new FieldContext(
            craftField: null,
            handle: 'images',
            mapping: FieldMapping::fromConfig('images', ['node' => 'images', 'options' => ['mode' => 'url']]),
            item: new RemoteItem([]),
            link: FakeLink::make(),
            element: $this->createMock(ElementInterface::class),
        );
    }
}
