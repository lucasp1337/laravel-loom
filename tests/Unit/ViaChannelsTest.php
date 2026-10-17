<?php

declare(strict_types=1);

use Lucasp\Loom\Scanners\Visitors\NotificationClassVisitor;

/** @return array{0: list<string>, 1: bool} channels and dynamic flag of the class's via() */
function viaOf(string $body): array
{
    $source = "<?php\nnamespace App\\Notifications;\nclass N { public function via(object \$n): array {\n$body\n} }";
    $record = runVisitor(new NotificationClassVisitor, $source)->getClasses()[0];

    return [$record->channels, $record->channelsDynamic];
}

it('reads a local array with literal appends as exact when unconditional', function () {
    expect(viaOf("\$c = ['mail']; \$c[] = 'Slack'; return \$c;"))->toBe([['mail', 'slack'], false]);
});

it('reads a local array with branch appends as a conditional union', function () {
    expect(viaOf("\$c = ['mail']; if (\$n->wantsSlack()) { \$c[] = 'slack'; } return \$c;"))->toBe([['mail', 'slack'], true]);
});

it('accepts return of an assignment', function () {
    expect(viaOf("return \$c = ['mail', 'database'];"))->toBe([['mail', 'database'], false]);
});

it('unions several returns', function () {
    expect(viaOf("if (\$n->x) { return ['mail']; } return ['database'];"))->toBe([['mail', 'database'], true]);
});

it('stays dynamic for a non-literal append, a call result or another mutation', function () {
    expect(viaOf("\$c = ['mail']; \$c[] = \$n->channel(); return \$c;"))->toBe([[], true])
        ->and(viaOf("\$c = \$n->channels(); \$c[] = 'mail'; return \$c;"))->toBe([[], true])
        ->and(viaOf("\$c = ['mail']; \$c = array_merge(\$c, \$n->more()); return \$c;"))->toBe([[], true])
        ->and(viaOf('return $n->channels();'))->toBe([[], true]);
});
