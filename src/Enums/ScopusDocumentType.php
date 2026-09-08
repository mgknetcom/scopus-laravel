<?php

namespace Mgknetcom\Scopus\Enums;

enum ScopusDocumentType: string
{
    case Article = 'article';
    case ConferencePaper = 'conference_paper';
    case Book = 'book';
    case BookChapter = 'book_chapter';
    case Other = 'other';

    public static function fromDescription(?string $description): self
    {
        return match (mb_strtolower(trim((string) $description))) {
            'article', 'business article' => self::Article,
            'conference paper' => self::ConferencePaper,
            'book' => self::Book,
            'book chapter', 'chapter' => self::BookChapter,
            default => self::Other,
        };
    }
}
