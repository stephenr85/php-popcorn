<?php

use Rushing\Popcorn\Discovery\AttributedClassScanner;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Classes\Annotated;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Classes\Plain;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Classes\ProseWithClassSubstring;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\Classes\SubAnnotated;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\ScanMarker;
use Rushing\Popcorn\Tests\Unit\Discovery\Fixtures\ScanSubMarker;

$path = __DIR__.'/Fixtures/classes';

it('finds only classes carrying the attribute under a scanned path', function () use ($path) {
    $found = (new AttributedClassScanner)->scan([$path], ScanMarker::class);

    // Annotated + SubAnnotated (instanceof default) match; Plain is ignored, not an error.
    expect($found)->toContain(Annotated::class)
        ->and($found)->toContain(SubAnnotated::class)
        ->and($found)->not->toContain(Plain::class);
});

it('honours the exact-match mode when instanceof is false', function () use ($path) {
    $found = (new AttributedClassScanner)->scan([$path], ScanMarker::class, instanceof: false);

    // SubAnnotated carries #[ScanSubMarker], not #[ScanMarker] exactly — excluded when instanceof=false.
    expect($found)->toContain(Annotated::class)
        ->and($found)->not->toContain(SubAnnotated::class);
});

it('matches preset subclass attributes by their own class', function () use ($path) {
    $found = (new AttributedClassScanner)->scan([$path], ScanSubMarker::class);

    expect($found)->toBe([SubAnnotated::class]);
});

it('skips non-existent paths and returns empty when none exist', function () {
    expect((new AttributedClassScanner)->scan(['/no/such/dir'], ScanMarker::class))->toBe([]);
});

it('derives a class-string from a file', function () use ($path) {
    $class = (new AttributedClassScanner)->classNameFromFile($path.'/Annotated.php');

    expect($class)->toBe(Annotated::class);
});

it('is not fooled by "class" appearing inside prose or a ::class fetch before the real declaration', function () use ($path) {
    $class = (new AttributedClassScanner)->classNameFromFile($path.'/ProseWithClassSubstring.php');

    expect($class)->toBe(ProseWithClassSubstring::class);
});

it('finds a class whose docblock/const contain false-positive "class" substrings when scanning', function () use ($path) {
    $found = (new AttributedClassScanner)->scan([$path], ScanMarker::class);

    expect($found)->toContain(ProseWithClassSubstring::class);
});

it('enumerates every loadable class under a path, unfiltered', function () use ($path) {
    $found = (new AttributedClassScanner)->classesIn([$path]);

    // Attribute-free enumeration: `Plain` comes back alongside the annotated ones, because the
    // keep/drop test belongs to callers whose filter is not an attribute at all.
    expect($found)->toContain(Annotated::class)
        ->and($found)->toContain(SubAnnotated::class)
        ->and($found)->toContain(Plain::class)
        ->and($found)->toContain(ProseWithClassSubstring::class);
});

it('returns empty from classesIn when no path exists', function () {
    expect((new AttributedClassScanner)->classesIn(['/no/such/dir']))->toBe([]);
});

it('accepts an individual FILE path, not only a directory', function () use ($path) {
    // The class docblock has always said "files or directories"; only directories worked, because
    // `Finder::in()` raises DirectoryNotFoundException on a plain file path. A caller needing
    // "this directory MINUS one subtree" can only express it as the sibling dirs plus the loose
    // files, so a file path has to be a first-class argument rather than a fatal.
    $found = (new AttributedClassScanner)->scan([$path.'/Annotated.php'], ScanMarker::class);

    expect($found)->toBe([Annotated::class]);
});

it('mixes file and directory paths in one scan without double-counting', function () use ($path) {
    $found = (new AttributedClassScanner)->classesIn([$path, $path.'/Annotated.php']);

    expect(array_count_values($found)[Annotated::class])->toBe(1)
        ->and($found)->toContain(Plain::class);
});

it('ignores a non-php file handed to it directly', function () {
    expect((new AttributedClassScanner)->classesIn([__FILE__.'.nope']))->toBe([]);
});
