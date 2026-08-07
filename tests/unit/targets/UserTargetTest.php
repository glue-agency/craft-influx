<?php

namespace GlueAgency\Influx\Tests\unit\targets;

use Codeception\Test\Unit;
use craft\elements\User;
use GlueAgency\Influx\models\FieldMapping;
use GlueAgency\Influx\models\Link;
use GlueAgency\Influx\services\AssetUploadService;
use GlueAgency\Influx\sync\item\RemoteItem;
use GlueAgency\Influx\sync\SyncContext;
use GlueAgency\Influx\targets\UserTarget;
use GlueAgency\Influx\Tests\unit\Support\FakeLink;

/**
 * UserTarget: the two capability refusals, and the four handles it reconciles
 * itself because no element save can carry them.
 *
 * The reconciliation itself mostly calls the Users service, which needs a booted
 * Craft — so what's pinned here is the DECIDING: which handles are claimed, when
 * a photo is worth re-downloading, and which activation policy applies to whom.
 * The service calls are behind `protected` methods a subclass records instead of
 * performing.
 */
class UserTargetTest extends Unit
{
    public function testUsersAreGlobalAndNeverSwept(): void
    {
        // A user link always runs once against a single endpoint, and "every user
        // this link owns" would be every user in the system.
        $this->assertFalse(UserTarget::supportsMultiSite());
        $this->assertFalse(UserTarget::supportsSweeping());
        $this->assertSame([], UserTarget::criteriaKeys());
        $this->assertSame([], UserTarget::criteriaSchema());
    }

    public function testUsersCanBeCreated(): void
    {
        $this->assertTrue(UserTarget::supportsCreating());
    }

    public function testTheFourOutOfBandHandlesAreClaimed(): void
    {
        // Claimed = the mapping applier must not treat them as attributes; each is
        // reconciled after the commit instead.
        $target = new UserTarget();
        $link = $this->link();

        foreach (['groups', 'photo', 'suspended', 'activation'] as $handle) {
            $this->assertTrue($target->ownsAttribute($link, $handle), "{$handle} should be target-owned");
        }

        // newPassword is an ordinary attribute the element save hashes and persists.
        $this->assertFalse($target->ownsAttribute($link, 'newPassword'));
        $this->assertFalse($target->ownsAttribute($link, 'email'));
    }

    public function testAPhotoIsSkippedWhenTheCurrentFileAlreadyHasThatName(): void
    {
        // Otherwise every nightly sync re-downloads and re-crops every avatar.
        $target = $this->target();
        $user = $this->user(photoFilename: 'jane.jpg');

        $target->reconcile($this->context(), $user, new RemoteItem(['avatar' => 'https://example.test/u/jane.jpg']));

        $this->assertSame(0, $target->downloads);
        $this->assertSame([], $target->photosSaved);
    }

    public function testAChangedPhotoUrlIsFetchedAndSaved(): void
    {
        $target = $this->target();
        $user = $this->user(photoFilename: 'jane.jpg');

        $target->reconcile($this->context(), $user, new RemoteItem(['avatar' => 'https://example.test/u/jane-2024.jpg']));

        $this->assertSame(1, $target->downloads);
        $this->assertSame(['jane-2024.jpg'], $target->photosSaved);
    }

    public function testClearingThePhotoDeletesIt(): void
    {
        // The feed is authoritative: an addressed-but-empty photo means "no photo".
        $target = $this->target();
        $user = $this->user(photoFilename: 'jane.jpg');

        $target->reconcile($this->context(), $user, new RemoteItem(['avatar' => null]));

        $this->assertSame(1, $target->photosDeleted);
        $this->assertSame(0, $target->downloads);
    }

    public function testAnUnmappedPhotoIsLeftEntirelyAlone(): void
    {
        $target = $this->target();
        $user = $this->user(photoFilename: 'jane.jpg');

        $target->reconcile($this->context(mappings: []), $user, new RemoteItem([]));

        $this->assertSame(0, $target->photosDeleted);
        $this->assertSame(0, $target->downloads);
    }

    public function testActivationOnlyAppliesToANewPendingUser(): void
    {
        $context = $this->context(mappings: [
            'activation' => ['options' => ['activation' => 'activate']],
        ]);

        $target = $this->target();
        $target->reconcile($context, $this->user(pending: true), new RemoteItem([]), isNew: true);
        $this->assertSame(1, $target->activations);

        // An existing user isn't re-activated on every sync.
        $target = $this->target();
        $target->reconcile($context, $this->user(pending: true), new RemoteItem([]), isNew: false);
        $this->assertSame(0, $target->activations);

        // Nor is one Craft doesn't consider pending.
        $target = $this->target();
        $target->reconcile($context, $this->user(pending: false), new RemoteItem([]), isNew: true);
        $this->assertSame(0, $target->activations);
    }

    public function testTheActivationEmailIsTheOtherWayIn(): void
    {
        $target = $this->target();
        $target->reconcile(
            $this->context(mappings: ['activation' => ['options' => ['activation' => 'email']]]),
            $this->user(pending: true),
            new RemoteItem([]),
            isNew: true,
        );

        $this->assertSame(0, $target->activations);
        $this->assertSame(1, $target->activationEmails);
    }

    public function testTheDefaultLeavesANewUserPending(): void
    {
        // Explicitly a choice, not an oversight: the operator has to pick a way in.
        $target = $this->target();
        $target->reconcile(
            $this->context(mappings: ['activation' => ['options' => []]]),
            $this->user(pending: true),
            new RemoteItem([]),
            isNew: true,
        );

        $this->assertSame(0, $target->activations);
        $this->assertSame(0, $target->activationEmails);
    }

    public function testSuspensionOnlyMovesWhenTheStateActuallyDiffers(): void
    {
        // Both service calls fire events and destroy the user's sessions, so an
        // already-correct state must not be rewritten.
        $context = $this->context(mappings: ['suspended' => ['node' => 'blocked']]);

        $target = $this->target();
        $target->reconcile($context, $this->user(suspended: false), new RemoteItem(['blocked' => 'yes']));
        $this->assertSame([true], $target->suspensions);

        $target = $this->target();
        $target->reconcile($context, $this->user(suspended: true), new RemoteItem(['blocked' => 'yes']));
        $this->assertSame([], $target->suspensions);

        $target = $this->target();
        $target->reconcile($context, $this->user(suspended: true), new RemoteItem(['blocked' => 'no']));
        $this->assertSame([false], $target->suspensions);
    }

    // -- fixtures -------------------------------------------------------------

    protected function link(array $mappings = []): Link
    {
        return FakeLink::make([
            'elementType' => User::class,
            'match'       => ['attribute' => 'email'],
            'mappings'    => $mappings,
        ]);
    }

    /**
     * A run whose link maps the photo by default — the mapping set most of these
     * cases care about.
     */
    protected function context(?array $mappings = null): SyncContext
    {
        return new SyncContext(
            link: $this->link($mappings ?? ['photo' => ['node' => 'avatar']]),
            target: new UserTarget(),
        );
    }

    protected function user(?string $photoFilename = null, bool $pending = false, bool $suspended = false): User
    {
        $user = new class() extends User {
            public ?string $photoFilenameStub = null;

            public function __construct()
            {
                // Skip User::init()'s Craft dependencies.
            }

            public function getPhoto(): ?\craft\elements\Asset
            {
                if ($this->photoFilenameStub === null) {
                    return null;
                }

                $asset = new class() extends \craft\elements\Asset {
                    public function __construct()
                    {
                    }
                };
                $asset->setFilename($this->photoFilenameStub);

                return $asset;
            }
        };

        $user->id = 5;
        $user->photoFilenameStub = $photoFilename;
        $user->photoId = $photoFilename === null ? null : 99;
        $user->pending = $pending;
        $user->suspended = $suspended;

        return $user;
    }

    /**
     * A target recording what it would have asked the Users service to do, over a
     * stub download service. `reconcile()` is a public shim onto `afterCommit()`
     * so the cases read as one call.
     */
    protected function target(): object
    {
        return new class() extends UserTarget {
            public int $downloads = 0;

            /** @var list<string> */
            public array $photosSaved = [];

            public int $photosDeleted = 0;

            public int $activations = 0;

            public int $activationEmails = 0;

            /** @var list<bool> */
            public array $suspensions = [];

            public function reconcile(SyncContext $context, User $user, RemoteItem $item, bool $isNew = false): void
            {
                $this->afterCommit($context, $user, $item, $isNew);
            }

            // Groups need the run memo and the UserGroups service; the other three
            // are what this spec is about.
            protected function reconcileGroups(SyncContext $context, User $user, ?FieldMapping $mapping, bool $isNew): void
            {
            }

            protected function savePhoto(User $user, string $tempPath, string $filename): void
            {
                $this->photosSaved[] = $filename;
            }

            protected function deletePhoto(User $user): void
            {
                $this->photosDeleted++;
            }

            protected function setSuspended(User $user, bool $suspended): void
            {
                $this->suspensions[] = $suspended;
            }

            protected function activate(User $user): void
            {
                $this->activations++;
            }

            protected function sendActivationEmail(User $user): void
            {
                $this->activationEmails++;
            }

            protected function uploads(): AssetUploadService
            {
                $owner = $this;

                return new class($owner) extends AssetUploadService {
                    protected object $owner;

                    public function __construct(object $owner)
                    {
                        // Skip AssetUploadService::init()'s Guzzle client.
                        $this->owner = $owner;
                    }

                    public function downloadToTemp(string $url): string
                    {
                        $this->owner->downloads++;

                        return '/tmp/influx-' . $this->filenameFor($url);
                    }

                    /**
                     * The real one runs Craft's own name sanitiser, which needs a
                     * booted app; the basename is the part this spec is about.
                     */
                    public function filenameFor(string $url): string
                    {
                        return basename(parse_url($url, PHP_URL_PATH) ?: '');
                    }
                };
            }
        };
    }
}
