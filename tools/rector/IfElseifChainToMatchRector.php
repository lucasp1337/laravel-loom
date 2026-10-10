<?php

declare(strict_types=1);

namespace Lucasp\Loom\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Match_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\MatchArm;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Rewrites an `if / elseif / else` chain that tests ONE subject against literals
 * into a `match`.
 *
 * Converted shape (all of it must hold):
 * - at least one `elseif` and a final `else` (a `match` without a default throws);
 * - every condition is `$subject === <literal>` (either operand order) or an `||`
 *   of such comparisons, always against the same subject;
 * - the subject is a plain variable or `$this->property` (evaluated once in a
 *   `match`, so it must be free of side effects);
 * - a literal is a scalar, `true`/`false`/`null`, or `Class::CONST` / `Class::class`;
 * - every branch is a single `return <expr>;` or a single `$target = <expr>;` to the
 *   same plain variable, with no comments attached.
 *
 * Skipped: loose `==` (a `match` compares strictly), method calls, array
 * literals, repeated literals, mixed return and assign branches, branches
 * with more than one statement.
 */
final class IfElseifChainToMatchRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Convert an if/elseif/else chain over one subject into match', [
            new CodeSample(
                <<<'CODE_SAMPLE'
if ($kind === 'a') {
    $label = 'first';
} elseif ($kind === 'b' || $kind === 'c') {
    $label = 'other';
} else {
    $label = 'none';
}
CODE_SAMPLE,
                <<<'CODE_SAMPLE'
$label = match ($kind) {
    'a' => 'first',
    'b', 'c' => 'other',
    default => 'none',
};
CODE_SAMPLE
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [If_::class];
    }

    /**
     * @param  If_  $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->elseifs === [] || ! $node->else instanceof Stmt\Else_) {
            return null;
        }

        /** @var list<array{0: Expr, 1: list<Stmt>}> $branches condition + body, in source order */
        $branches = [[$node->cond, $node->stmts]];
        foreach ($node->elseifs as $elseif) {
            $branches[] = [$elseif->cond, $elseif->stmts];
        }

        $subject = null;
        $arms = [];
        $seen = [];
        $bodies = [];

        foreach ($branches as [$cond, $stmts]) {
            $literals = $this->literalsOf($cond, $subject);
            if ($literals === null) {
                return null;
            }

            foreach ($literals as $literal) {
                $key = $this->print($literal);
                if (isset($seen[$key])) {
                    return null; // repeated literal: keep the chain, a match would hide the dead arm
                }
                $seen[$key] = true;
            }

            $bodies[] = $stmts;
            $arms[] = $literals;
        }
        $bodies[] = $node->else->stmts;

        $shape = $this->uniformShape($bodies);
        if ($shape === null) {
            return null;
        }

        [$kind, $target, $exprs] = $shape;
        if ($subject === null) {
            return null;
        }

        $matchArms = [];
        foreach ($arms as $index => $conds) {
            $matchArms[] = new MatchArm($conds, $exprs[$index]);
        }
        $matchArms[] = new MatchArm(null, $exprs[count($arms)]);

        $match = new Match_($subject, $matchArms);

        // Return branches become `return match`; assign branches become `$target = match`.
        $result = $kind === 'return'
            ? new Return_($match)
            : new Expression(new Assign($target ?? new Variable('unreachable'), $match));

        $this->mirrorComments($result, $node);

        return $result;
    }

    /**
     * Literal operands of `$subject === lit [|| $subject === lit ...]`, or null
     * when the condition is any other shape or tests a different subject.
     *
     * @return list<Expr>|null
     */
    private function literalsOf(Expr $cond, ?Expr &$subject): ?array
    {
        if ($cond instanceof BooleanOr) {
            $left = $this->literalsOf($cond->left, $subject);
            $right = $left === null ? null : $this->literalsOf($cond->right, $subject);

            return $left === null || $right === null ? null : [...$left, ...$right];
        }

        if (! $cond instanceof Identical) {
            return null;
        }

        // Either operand order: `$x === 'a'` or `'a' === $x`.
        foreach ([[$cond->left, $cond->right], [$cond->right, $cond->left]] as [$candidate, $literal]) {
            if (! $this->isPureSubject($candidate) || ! $this->isLiteral($literal)) {
                continue;
            }
            if ($subject !== null && ! $this->nodeComparator->areNodesEqual($subject, $candidate)) {
                return null;
            }
            $subject = $candidate;

            return [$literal];
        }

        return null;
    }

    /**
     * @param  list<list<Stmt>>  $bodies
     * @return array{0: 'return'|'assign', 1: Expr|null, 2: list<Expr>}|null
     */
    private function uniformShape(array $bodies): ?array
    {
        $kind = null;
        $target = null;
        $exprs = [];

        foreach ($bodies as $stmts) {
            // One uncommented statement per branch: a comment would be lost in the rewrite.
            if (count($stmts) !== 1 || $stmts[0]->getComments() !== []) {
                return null;
            }

            $branch = $this->branchOf($stmts[0]);
            if ($branch === null) {
                return null;
            }
            [$branchKind, $branchTarget, $expr] = $branch;

            // Mixed return and assign branches have no single match form.
            if ($kind !== null && $kind !== $branchKind) {
                return null;
            }
            // Every assign branch must write the same variable.
            if ($target !== null && $branchTarget !== null && ! $this->nodeComparator->areNodesEqual($target, $branchTarget)) {
                return null;
            }

            $kind = $branchKind;
            $target ??= $branchTarget;
            $exprs[] = $expr;
        }

        return $kind === null ? null : [$kind, $target, $exprs];
    }

    /**
     * @return array{0: 'return'|'assign', 1: Variable|null, 2: Expr}|null
     */
    private function branchOf(Stmt $stmt): ?array
    {
        // `return <expr>;`
        if ($stmt instanceof Return_ && $stmt->expr instanceof Expr) {
            return ['return', null, $stmt->expr];
        }

        // `$variable = <expr>;`
        if ($stmt instanceof Expression && $stmt->expr instanceof Assign && $stmt->expr->var instanceof Variable) {
            return ['assign', $stmt->expr->var, $stmt->expr->expr];
        }

        return null;
    }

    /** A variable or `$this->name`: safe to evaluate once instead of per condition. */
    private function isPureSubject(Expr $expr): bool
    {
        if ($expr instanceof Variable) {
            return is_string($expr->name);
        }

        return $expr instanceof PropertyFetch
            && $expr->var instanceof Variable
            && $expr->var->name === 'this'
            && $expr->name instanceof Identifier;
    }

    private function isLiteral(Expr $expr): bool
    {
        if ($expr instanceof Scalar\String_ || $expr instanceof Scalar\Int_ || $expr instanceof Scalar\Float_) {
            return true;
        }

        if ($expr instanceof ConstFetch) {
            return in_array($expr->name->toLowerString(), ['true', 'false', 'null'], true);
        }

        return $expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && $expr->name instanceof Identifier;
    }

    private function print(Expr $expr): string
    {
        return $this->nodeComparator->printWithoutComments($expr);
    }
}
