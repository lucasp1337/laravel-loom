<?php

declare(strict_types=1);

use Lucasp\Loom\Support\Ast\Literal;

it('reads string literals', function () {
    expect(Literal::string(parseExpr("'abc'")))->toBe('abc')
        ->and(Literal::string(parseExpr("''")))->toBe('')
        ->and(Literal::string(parseExpr('42')))->toBeNull()
        ->and(Literal::string(parseExpr('$x')))->toBeNull()
        ->and(Literal::string(null))->toBeNull();
});

it('reads signed int literals', function () {
    expect(Literal::int(parseExpr('7')))->toBe(7)
        ->and(Literal::int(parseExpr('-7')))->toBe(-7)
        ->and(Literal::int(parseExpr("'7'")))->toBeNull()
        ->and(Literal::int(parseExpr('1.5')))->toBeNull();
});

it('reads a scalar as string or int', function () {
    expect(Literal::scalar(parseExpr("'x'")))->toBe('x')
        ->and(Literal::scalar(parseExpr('-3')))->toBe(-3)
        ->and(Literal::scalar(parseExpr('true')))->toBeNull();
});

it('reads true and false constants case-insensitively', function () {
    expect(Literal::bool(parseExpr('true')))->toBeTrue()
        ->and(Literal::bool(parseExpr('FALSE')))->toBeFalse()
        ->and(Literal::bool(parseExpr('null')))->toBeNull()
        ->and(Literal::bool(parseExpr('1')))->toBeNull();
});
