<?php

namespace App\Domain\Queries;

/**
 * How a core issue column is compared once its operator has been accepted.
 */
enum IssueFilterKind: string
{
    case Status = 'status';
    case List = 'list';
    case Optional = 'optional';
    case Text = 'text';
    case Date = 'date';
    case DateTime = 'datetime';
    case Integer = 'integer';
    case Float = 'float';
    case Bool = 'bool';
    case Parent = 'parent';
    case Child = 'child';
    case Relation = 'relation';
    case Association = 'association';
    case Subproject = 'subproject';
}
