<?php

declare(strict_types=1);

namespace Lucasp\Loom\Ui;

/**
 * How a table cell is styled and aligned.
 */
enum ColumnRole: string
{
    case NAME = 'name';
    case TEXT = 'text';
    case NUMBER = 'number';
    case FILE = 'file';
}
