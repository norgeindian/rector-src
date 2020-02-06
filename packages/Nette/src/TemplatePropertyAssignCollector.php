<?php

declare(strict_types=1);

namespace Rector\Nette;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\Core\PhpParser\Node\Resolver\NameResolver;
use Rector\Core\PhpParser\NodeTraverser\CallableNodeTraverser;
use Rector\Nette\ValueObject\MagicTemplatePropertyCalls;

final class TemplatePropertyAssignCollector
{
    /**
     * @var Expr[]
     */
    private $templateVariables = [];

    /**
     * @var Node[]
     */
    private $nodesToRemove = [];

    /**
     * @var Expr|null
     */
    private $templateFileExpr;

    /**
     * @var CallableNodeTraverser
     */
    private $callableNodeTraverser;

    /**
     * @var NameResolver
     */
    private $nameResolver;

    public function __construct(CallableNodeTraverser $callableNodeTraverser, NameResolver $nameResolver)
    {
        $this->callableNodeTraverser = $callableNodeTraverser;
        $this->nameResolver = $nameResolver;
    }

    public function collectTemplateFileNameVariablesAndNodesToRemove(
        ClassMethod $classMethod
    ): MagicTemplatePropertyCalls {
        $this->templateFileExpr = null;
        $this->templateVariables = [];
        $this->nodesToRemove = [];

        $this->callableNodeTraverser->traverseNodesWithCallable(
            (array) $classMethod->stmts,
            function (Node $node): void {
                if ($node instanceof MethodCall) {
                    $this->collectTemplateFileExpr($node);
                }

                if ($node instanceof Assign) {
                    $this->collectVariableFromAssign($node);
                }
            }
        );

        return new MagicTemplatePropertyCalls($this->templateFileExpr, $this->templateVariables, $this->nodesToRemove);
    }

    private function collectTemplateFileExpr(MethodCall $methodCall): void
    {
        if ($this->nameResolver->isName($methodCall->name, 'render')) {
            if (isset($methodCall->args[0])) {
                $this->templateFileExpr = $methodCall->args[0]->value;
            }

            $this->nodesToRemove[] = $methodCall;
        }

        if ($this->nameResolver->isName($methodCall->name, 'setFile')) {
            $this->templateFileExpr = $methodCall->args[0]->value;
            $this->nodesToRemove[] = $methodCall;
        }
    }

    private function collectVariableFromAssign(Assign $assign): void
    {
        // $this->template = x
        if ($assign->var instanceof PropertyFetch) {
            if (! $this->nameResolver->isName($assign->var->var, 'template')) {
                return;
            }

            $variableName = $this->nameResolver->getName($assign->var);
            $this->templateVariables[$variableName] = $assign->expr;

            $this->nodesToRemove[] = $assign;
        }

        // $x = $this->template
        if ($assign->var instanceof Variable && $this->isTemplatePropertyFetch($assign->expr)) {
            $this->nodesToRemove[] = $assign;
        }
    }

    /**
     * Looks for:
     * $this->template
     */
    private function isTemplatePropertyFetch(Expr $expr): bool
    {
        if (! $expr instanceof PropertyFetch) {
            return false;
        }

        if (! $expr->var instanceof Variable) {
            return false;
        }

        if (! $this->nameResolver->isName($expr->var, 'this')) {
            return false;
        }

        return $this->nameResolver->isName($expr->name, 'template');
    }
}
