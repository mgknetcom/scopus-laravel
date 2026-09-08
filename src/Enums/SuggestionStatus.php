<?php

namespace Mgknetcom\Scopus\Enums;

enum SuggestionStatus: string
{
    case Pending = 'pending';
    case Imported = 'imported';
    case Ignored = 'ignored';
    case Matched = 'matched';
    case Failed = 'failed';
}
