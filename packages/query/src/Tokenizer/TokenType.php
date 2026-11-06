<?php

namespace Utopia\Query\Tokenizer;

enum TokenType
{
    case Keyword;
    case Identifier;
    case QuotedIdentifier;
    case Integer;
    case Float;
    case String;
    case Boolean;
    case Null;
    case Operator;
    case LeftParen;
    case RightParen;
    case Comma;
    case Semicolon;
    case Dot;
    case Star;
    case Placeholder;
    case NamedPlaceholder;
    case NumberedPlaceholder;
    case LineComment;
    case BlockComment;
    case Whitespace;
    case Eof;
}
