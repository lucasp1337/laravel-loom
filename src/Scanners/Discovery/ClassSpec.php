<?php

declare(strict_types=1);

namespace Lucasp\Loom\Scanners\Discovery;

use Lucasp\Loom\Scanners\Visitors\ClassRecordVisitor;
use Lucasp\Loom\Scanners\Visitors\CollectingVisitor;
use Lucasp\Loom\Support\ClassHierarchyResolver;
use Lucasp\Loom\Support\PrimitiveDirectory;

/**
 * What distinguishes one class-based primitive (event, job, mailable,
 * notification) for {@see ClassPrimitiveDiscovery}: where its classes live,
 * which visitor reads them, how dispatch sites seed more of them, and how a
 * located class becomes an index entry.
 *
 * @template TRecord of object
 * @template TLocation of object
 * @template TEntry of object
 *
 * @internal
 */
interface ClassSpec
{
    /** Convention directory walked inside every scan directory. */
    public function directory(): PrimitiveDirectory;

    /**
     * A fresh visitor collecting this primitive's class records.
     *
     * @return ClassRecordVisitor<TRecord>
     */
    public function classVisitor(): ClassRecordVisitor;

    /** @param  TRecord  $record */
    public function fqcnOf(object $record): string;

    /**
     * @param  TRecord  $record
     * @return TLocation
     */
    public function locationOf(object $record, string $relativeFile, ClassHierarchyResolver $resolver): object;

    /**
     * Fresh visitors for one dispatch-site walk of a single file.
     *
     * @return list<CollectingVisitor>
     */
    public function seedVisitors(): array;

    /**
     * Targets this primitive takes from the visitors after the file walk.
     *
     * @param  list<CollectingVisitor>  $visitors  the instances from {@see seedVisitors()}
     * @return list<DispatchSeed>
     */
    public function seedsFrom(array $visitors): array;

    /**
     * Whether a located class reached only through an ambiguous dispatch form
     * belongs to this primitive. `X::dispatch()` could name an event or a job,
     * so each spec says what proves its own: a class under its directory, or
     * for jobs a queued class.
     *
     * @param  TLocation  $location
     * @param  bool  $underDirectory  the class file sits under {@see directory()}
     */
    public function admitsAmbiguous(object $location, bool $underDirectory): bool;

    /**
     * Whether a class found by walking the convention directory is this
     * primitive. Mailables and notifications ask for evidence (a base class
     * or a defining method) so helper classes in the same directory are not
     * listed. A dispatch site proves its target, so seeds skip this.
     *
     * @param  TRecord  $record
     */
    public function belongsByConvention(object $record, ClassHierarchyResolver $resolver): bool;

    /**
     * Whether an ambiguous seed that cannot be located is reported by this
     * primitive. Exactly one spec says yes, so the target is listed once.
     */
    public function reportsUnlocatableAmbiguous(): bool;

    /**
     * @param  TLocation  $location
     * @return TEntry
     */
    public function entryOf(string $fqcn, object $location): object;
}
