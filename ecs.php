<?php

declare(strict_types=1);

use craft\ecs\SetList;
use PhpCsFixer\Fixer\CastNotation\CastSpacesFixer;
use PhpCsFixer\Fixer\Operator\BinaryOperatorSpacesFixer;
use PhpCsFixer\Fixer\Operator\NotOperatorWithSuccessorSpaceFixer;
use PhpCsFixer\Fixer\Whitespace\BlankLineBeforeStatementFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return static function(ECSConfig $ecsConfig): void {
    $ecsConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
        __FILE__,
    ]);

    // Base: craftcms/ecs CRAFT_CMS_4 (PSR-12 + Craft tweaks).
    $ecsConfig->import(SetList::CRAFT_CMS_4);

    // House deviations on top of the preset:

    // `! $foo` — space after logical-not.
    $ecsConfig->rule(NotOperatorWithSuccessorSpaceFixer::class);

    // Blank line before these statements when they follow another.
    $ecsConfig->ruleWithConfiguration(BlankLineBeforeStatementFixer::class, [
        'statements' => [
            'break',
            'continue',
            'do',
            'for',
            'foreach',
            'if',
            'return',
            'switch',
            'throw',
            'try',
            'while',
        ],
    ]);

    // Keep `=>` alignment in arrays (preset collapses it to a single space).
    $ecsConfig->ruleWithConfiguration(BinaryOperatorSpacesFixer::class, [
        'operators' => [
            '=>' => 'align_single_space_minimal',
        ],
    ]);

    // `(int) $foo` — space after cast (preset enforces none).
    $ecsConfig->ruleWithConfiguration(CastSpacesFixer::class, [
        'space' => 'single',
    ]);
};
