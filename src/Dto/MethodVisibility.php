<?php

declare(strict_types=1);

namespace Lucasp\Loom\Dto;

enum MethodVisibility: string
{
    case PUBLIC = 'public';
    case PROTECTED = 'protected';
    case PRIVATE = 'private';
}
