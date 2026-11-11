<?php

declare(strict_types=1);

use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\DirectoryListing;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\PathTraversalDetected;
use League\Flysystem\UnableToReadFile;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Adapters\ScopedFilesystemOperator;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\FilesystemOperationExecutor;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\ForkUpload;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadClock;
use SkyRaptor\LaravelVersionedStorage\Filesystem\Execution\UploadOperation;

function scopedUploadOperation(Closure $begin): UploadOperation
{
    return new UploadOperation(
        ['timeout' => 10, 'fork' => false],
        [],
        $begin,
        executor: new FilesystemOperationExecutor(
            Mockery::mock(ForkUpload::class),
            Mockery::mock(FilesystemManager::class),
        ),
        clock: new UploadClock,
    );
}

beforeEach(function (): void {
    $this->root = dirname(__DIR__, 2).'/.operator-'.bin2hex(random_bytes(8));
    $adapter = new LocalFilesystemAdapter($this->root);
    $this->physical = new Filesystem($adapter);
    $this->scoped = new ScopedFilesystemOperator($this->physical, 'reports/version');
});

afterEach(function (): void {
    Mockery::close();
    (new Illuminate\Filesystem\Filesystem)->deleteDirectory($this->root);
});

it('prefixes file existence checks and returns the original result', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('fileExists')->once()->with('reports/version/one')->andReturn(true);
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->fileExists('one'))->toBeTrue();
});

it('prefixes directory existence checks and returns the original result', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('directoryExists')->once()->with('reports/version/nested')->andReturn(true);
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->directoryExists('nested'))->toBeTrue();
});

it('prefixes combined existence checks and returns the original result', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('has')->once()->with('reports/version/one')->andReturn(true);
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->has('one'))->toBeTrue();
});

it('prefixes reads and returns the original contents', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('read')->once()->with('reports/version/one')->andReturn('contents');
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->read('one'))->toBe('contents');
});

it('prefixes modification time queries and returns the original timestamp', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('lastModified')->once()->with('reports/version/one')->andReturn(123);
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->lastModified('one'))->toBe(123);
});

it('prefixes size queries and returns the original byte count', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('fileSize')->once()->with('reports/version/one')->andReturn(8);
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->fileSize('one'))->toBe(8);
});

it('prefixes MIME type queries and returns the original type', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('mimeType')->once()->with('reports/version/one')->andReturn('text/plain');
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->mimeType('one'))->toBe('text/plain');
});

it('prefixes visibility queries and returns the original visibility', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('visibility')->once()->with('reports/version/one')->andReturn('private');
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->visibility('one'))->toBe('private');
});

it('prefixes writes and preserves contents and visibility options', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('write')->once()->with('reports/version/one', 'contents', ['visibility' => 'private'])->andReturnNull();
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->write('one', 'contents', ['visibility' => 'private']))->toBeNull();
});

it('prefixes visibility changes and preserves the requested visibility', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('setVisibility')->once()->with('reports/version/one', 'private')->andReturnNull();
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->setVisibility('one', 'private'))->toBeNull();
});

it('prefixes file deletions and preserves their void result', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('delete')->once()->with('reports/version/one')->andReturnNull();
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->delete('one'))->toBeNull();
});

it('prefixes directory deletions and preserves their void result', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('deleteDirectory')->once()->with('reports/version/nested')->andReturnNull();
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->deleteDirectory('nested'))->toBeNull();
});

it('prefixes directory creation and preserves directory visibility options', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('createDirectory')->once()->with('reports/version/nested', ['directory_visibility' => 'private'])->andReturnNull();
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->createDirectory('nested', ['directory_visibility' => 'private']))->toBeNull();
});

it('prefixes both move paths and preserves disabled visibility retention', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('move')->once()->with('reports/version/one', 'reports/version/two', ['retain_visibility' => false])->andReturnNull();
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->move('one', 'two', ['retain_visibility' => false]))->toBeNull();
});

it('prefixes both copy paths and preserves visibility options', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('copy')->once()->with('reports/version/one', 'reports/version/two', ['visibility' => 'private'])->andReturnNull();
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->copy('one', 'two', ['visibility' => 'private']))->toBeNull();
});

it('prefixes checksum queries and preserves the algorithm and result', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('checksum')->once()->with('reports/version/one', ['checksum_algo' => 'sha256'])->andReturn('checksum');
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->checksum('one', ['checksum_algo' => 'sha256']))->toBe('checksum');
});

it('prefixes public URL queries and preserves empty options and the returned URL', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('publicUrl')->once()->with('reports/version/one', [])->andReturn('https://files.example.test/one');
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->publicUrl('one', []))->toBe('https://files.example.test/one');
});

it('keeps stream reads and writes on the original operator', function () {
    $stream = fopen('php://temp', 'w+b');
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('readStream')->once()->with('reports/version/one')->andReturn($stream);
    $operator->shouldReceive('writeStream')->once()->with('reports/version/two', $stream, ['visibility' => 'private']);
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    try {
        expect($scoped->readStream('one'))->toBe($stream);
        $scoped->writeStream('two', $stream, ['visibility' => 'private']);
    } finally {
        fclose($stream);
    }
});

it('returns directory listings relative to the snapshot', function () {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('listContents')->once()->with('reports/version/', true)
        ->andReturn(new DirectoryListing([
            new DirectoryAttributes('reports/version/nested'),
            new FileAttributes('reports/version/nested/one', 8),
        ]));
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');
    $listing = $scoped->listContents('', true)->toArray();

    expect($listing[0]->path())->toBe('nested')
        ->and($listing[1]->path())->toBe('nested/one')
        ->and($listing[1]->fileSize())->toBe(8);
});

it('keeps an unsupervised listing lazy until the caller iterates it', function () {
    $visited = false;
    $contents = (function () use (&$visited): Generator {
        $visited = true;
        yield new FileAttributes('reports/version/one', 8);
    })();
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('listContents')->once()->andReturn(new DirectoryListing($contents));
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    $listing = $scoped->listContents('');
    expect($visited)->toBeFalse();
    expect($listing->toArray()[0]->path())->toBe('one');
    expect($visited)->toBeTrue();
});

it('delegates temporary URL generation with its expiration and options', function () {
    $expiration = new DateTimeImmutable;
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('temporaryUrl')->once()->with('reports/version/one', $expiration, ['download' => true])
        ->andReturn('https://files.example.test/signed');
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect($scoped->temporaryUrl('one', $expiration, ['download' => true]))->toBe('https://files.example.test/signed');
});

it('rejects traversal in either copy path before invoking the original operator', function (string $source, string $destination) {
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldNotReceive('copy');
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version');

    expect(fn () => $scoped->copy($source, $destination))->toThrow(PathTraversalDetected::class);
})->with([['../outside', 'one'], ['one', '../outside']]);

it('writes real contents beneath the snapshot root', function () {
    $this->scoped->write('one', 'contents');

    expect($this->physical->read('reports/version/one'))->toBe('contents')
        ->and($this->physical->fileExists('one'))->toBeFalse();
});

it('reads only the selected snapshot', function () {
    $this->physical->write('reports/version/one', 'selected');
    $this->physical->write('reports/other/one', 'other');

    expect($this->scoped->read('one'))->toBe('selected');
});

it('does not expose files outside its scope through existence checks', function () {
    $this->physical->write('one', 'outside');

    expect($this->scoped->fileExists('one'))->toBeFalse()
        ->and($this->scoped->has('one'))->toBeFalse();
});

it('distinguishes existing scoped files from directories', function () {
    $this->scoped->write('nested/one', 'contents');

    expect($this->scoped->fileExists('nested/one'))->toBeTrue()
        ->and($this->scoped->directoryExists('nested'))->toBeTrue()
        ->and($this->scoped->has('nested'))->toBeTrue()
        ->and($this->scoped->directoryExists('missing'))->toBeFalse();
});

it('streams real file contents without changing caller ownership', function () {
    $this->physical->write('reports/version/one', 'stream contents');
    $stream = $this->scoped->readStream('one');

    try {
        expect(stream_get_contents($stream))->toBe('stream contents');
    } finally {
        fclose($stream);
    }
});

it('writes a real stream beneath its scope', function () {
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, 'stream contents');
    rewind($stream);

    try {
        $this->scoped->writeStream('one', $stream);
        expect(is_resource($stream))->toBeTrue()
            ->and($this->physical->read('reports/version/one'))->toBe('stream contents');
    } finally {
        fclose($stream);
    }
});

it('deletes only the scoped file', function () {
    $this->physical->write('reports/version/one', 'selected');
    $this->physical->write('reports/other/one', 'other');

    $this->scoped->delete('one');

    expect($this->physical->fileExists('reports/version/one'))->toBeFalse()
        ->and($this->physical->read('reports/other/one'))->toBe('other');
});

it('deletes nested directories only in its snapshot', function () {
    $this->scoped->write('nested/one', 'selected');
    $this->physical->write('reports/other/nested/one', 'other');

    $this->scoped->deleteDirectory('nested');

    expect($this->scoped->directoryExists('nested'))->toBeFalse()
        ->and($this->physical->read('reports/other/nested/one'))->toBe('other');
});

it('creates an empty directory below the snapshot', function () {
    $this->scoped->createDirectory('nested/empty');

    expect($this->physical->directoryExists('reports/version/nested/empty'))->toBeTrue()
        ->and($this->physical->directoryExists('nested'))->toBeFalse();
});

it('moves both source and destination within the snapshot', function () {
    $this->scoped->write('one', 'contents');
    $this->scoped->move('one', 'nested/two');

    expect($this->scoped->fileExists('one'))->toBeFalse()
        ->and($this->physical->read('reports/version/nested/two'))->toBe('contents');
});

it('copies both source and destination within the snapshot', function () {
    $this->scoped->write('one', 'contents');
    $this->scoped->copy('one', 'nested/two');

    expect($this->scoped->read('one'))->toBe('contents')
        ->and($this->physical->read('reports/version/nested/two'))->toBe('contents');
});

it('returns real file size and MIME type', function () {
    $this->scoped->write('one.txt', 'plain text');

    expect($this->scoped->fileSize('one.txt'))->toBe(10)
        ->and($this->scoped->mimeType('one.txt'))->toBe('text/plain');
});

it('returns the physical file modification timestamp', function () {
    $this->scoped->write('one', 'contents');
    touch($this->root.'/reports/version/one', 1234567890);

    expect($this->scoped->lastModified('one'))->toBe(1234567890);
});

it('sets scoped visibility without changing a sibling version', function () {
    $this->scoped->write('one', 'contents', ['visibility' => 'private']);
    $this->physical->write('reports/other/one', 'other', ['visibility' => 'private']);
    $this->scoped->setVisibility('one', 'public');

    expect($this->scoped->visibility('one'))->toBe('public')
        ->and($this->physical->visibility('reports/other/one'))->toBe('private');
});

it('uses requested checksum options on the selected file', function () {
    $this->scoped->write('one', 'contents');

    expect($this->scoped->checksum('one', ['checksum_algo' => 'sha256']))->toBe(hash('sha256', 'contents'));
});

it('lists shallow contents without leaking the physical prefix', function () {
    $this->scoped->write('one', 'contents');
    $this->scoped->write('nested/two', 'contents');
    $this->physical->write('reports/other/outside', 'other');

    expect($this->scoped->listContents('')->sortByPath()->toArray())
        ->sequence(
            fn ($entry) => $entry->path()->toBe('nested')->isDir()->toBeTrue(),
            fn ($entry) => $entry->path()->toBe('one')->isFile()->toBeTrue(),
        );
});

it('lists recursive contents relative to the snapshot rather than the requested subdirectory', function () {
    $this->scoped->write('nested/deeper/one', 'contents');

    expect(array_map(fn ($entry) => $entry->path(), $this->scoped->listContents('nested', true)->sortByPath()->toArray()))
        ->toBe(['nested/deeper', 'nested/deeper/one']);
});

it('normalizes in-scope parent and dot segments before accessing physical files', function () {
    $this->scoped->write('nested/.././one', 'contents');

    expect($this->physical->read('reports/version/one'))->toBe('contents');
});

it('surfaces missing file errors from the physical operator', function () {
    expect(fn () => $this->scoped->read('missing'))->toThrow(UnableToReadFile::class);
});

it('treats a trailing slash in its prefix as a separator rather than another directory', function () {
    $scoped = new ScopedFilesystemOperator($this->physical, 'reports/version///');
    $scoped->write('one', 'contents');

    expect($this->physical->read('reports/version/one'))->toBe('contents');
});

it('consumes supervised listings before acknowledging the operation', function () {
    $visited = false;
    $acknowledged = false;
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('listContents')->once()->with('reports/version/', false)->andReturn(new DirectoryListing(
        (function () use (&$visited): Generator {
            $visited = true;
            yield new FileAttributes('reports/version/one', 8);
        })(),
    ));
    $operation = scopedUploadOperation(function ($id, $mutation) use (&$visited, &$acknowledged): Closure {
        expect($mutation)->toBe('');

        return function () use (&$visited, &$acknowledged): void {
            expect($visited)->toBeTrue();
            $acknowledged = true;
        };
    });
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version', $operation);

    $listing = $scoped->listContents('');

    expect($acknowledged)->toBeTrue()
        ->and($listing->toArray()[0]->path())->toBe('one');
});

it('keeps supervised listing iteration failures inside operation health', function () {
    $failure = new RuntimeException('listing interrupted');
    $operator = Mockery::mock(FilesystemOperator::class);
    $operator->shouldReceive('listContents')->once()->andReturn(new DirectoryListing(
        (function () use ($failure): Generator {
            yield new FileAttributes('reports/version/one', 8);

            throw $failure;
        })(),
    ));
    $acknowledged = false;
    $operation = scopedUploadOperation(function () use (&$acknowledged): Closure {
        return function () use (&$acknowledged): void {
            $acknowledged = true;
        };
    });
    $scoped = new ScopedFilesystemOperator($operator, 'reports/version', $operation);

    expect(fn () => $scoped->listContents(''))->toThrow(RuntimeException::class, 'listing interrupted')
        ->and($acknowledged)->toBeFalse();
    expect(fn () => $operation->assertHealthy())->toThrow(fn (RuntimeException $exception) => expect($exception->getPrevious())->toBe($failure));
});

it('does not allow retained callback operators to write after their operation closes', function () {
    $operation = scopedUploadOperation(fn () => throw new LogicException('No journal should begin'));
    $scoped = new ScopedFilesystemOperator($this->physical, 'reports/version', $operation);
    $operation->close();

    expect(fn () => $scoped->write('one', 'late'))->toThrow(RuntimeException::class, 'callback has already finished')
        ->and($this->physical->fileExists('reports/version/one'))->toBeFalse();
});

it('passes supervised stream reads through their own operation boundary', function () {
    $this->physical->write('reports/version/one', 'stream contents');
    $acknowledged = false;
    $operation = scopedUploadOperation(function () use (&$acknowledged): Closure {
        return function () use (&$acknowledged): void {
            $acknowledged = true;
        };
    });
    $scoped = new ScopedFilesystemOperator($this->physical, 'reports/version', $operation);
    $stream = $scoped->readStream('one');

    try {
        expect($acknowledged)->toBeTrue()
            ->and(stream_get_contents($stream))->toBe('stream contents');
    } finally {
        fclose($stream);
    }
});

it('rejects escaping paths at every filesystem entry point', function (string $method, array $arguments) {
    expect(fn () => $this->scoped->{$method}(...$arguments))->toThrow(PathTraversalDetected::class);
    expect($this->physical->listContents('', true)->toArray())->toBe([]);
})->with([
    'file existence' => ['fileExists', ['../outside']],
    'directory existence' => ['directoryExists', ['../outside']],
    'either existence' => ['has', ['../outside']],
    'read' => ['read', ['../outside']],
    'stream read' => ['readStream', ['../outside']],
    'listing' => ['listContents', ['../outside']],
    'modification time' => ['lastModified', ['../outside']],
    'size' => ['fileSize', ['../outside']],
    'MIME type' => ['mimeType', ['../outside']],
    'visibility' => ['visibility', ['../outside']],
    'write' => ['write', ['../outside', 'contents']],
    'visibility change' => ['setVisibility', ['../outside', 'public']],
    'file deletion' => ['delete', ['../outside']],
    'directory deletion' => ['deleteDirectory', ['../outside']],
    'directory creation' => ['createDirectory', ['../outside']],
    'move source' => ['move', ['../outside', 'one']],
    'move destination' => ['move', ['one', '../outside']],
    'checksum' => ['checksum', ['../outside']],
    'public URL' => ['publicUrl', ['../outside']],
    'temporary URL' => ['temporaryUrl', ['../outside', new DateTimeImmutable]],
]);
