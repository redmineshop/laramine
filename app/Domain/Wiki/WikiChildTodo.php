<?php

namespace App\Domain\Wiki;

/**
 * What happens to child pages when a wiki page is deleted.
 */
enum WikiChildTodo: string
{
    case Nullify = 'nullify';
    case Destroy = 'destroy';
    case Reassign = 'reassign';
}
