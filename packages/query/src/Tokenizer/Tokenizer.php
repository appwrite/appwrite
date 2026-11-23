<?php

namespace Utopia\Query\Tokenizer;

use Utopia\Query\Exception\ValidationException;

class Tokenizer
{
    private const KEYWORD_MAP = [
        'SELECT' => true, 'FROM' => true, 'WHERE' => true, 'AND' => true,
        'OR' => true, 'NOT' => true, 'JOIN' => true, 'LEFT' => true,
        'RIGHT' => true, 'INNER' => true, 'OUTER' => true, 'FULL' => true,
        'CROSS' => true, 'NATURAL' => true, 'ON' => true, 'AS' => true,
        'ORDER' => true, 'BY' => true, 'GROUP' => true, 'HAVING' => true,
        'LIMIT' => true, 'OFFSET' => true, 'ASC' => true, 'DESC' => true,
        'IN' => true, 'BETWEEN' => true, 'LIKE' => true, 'ILIKE' => true,
        'IS' => true, 'CASE' => true, 'WHEN' => true, 'THEN' => true,
        'ELSE' => true, 'END' => true, 'EXISTS' => true, 'DISTINCT' => true,
        'ALL' => true, 'UNION' => true, 'INTERSECT' => true, 'EXCEPT' => true,
        'WITH' => true, 'RECURSIVE' => true, 'SET' => true, 'INSERT' => true,
        'INTO' => true, 'VALUES' => true, 'UPDATE' => true, 'DELETE' => true,
        'CREATE' => true, 'ALTER' => true, 'DROP' => true, 'TABLE' => true,
        'INDEX' => true, 'VIEW' => true, 'OVER' => true, 'PARTITION' => true,
        'WINDOW' => true, 'ROWS' => true, 'RANGE' => true, 'UNBOUNDED' => true,
        'PRECEDING' => true, 'FOLLOWING' => true, 'CURRENT' => true,
        'ROW' => true, 'FETCH' => true, 'NEXT' => true, 'FIRST' => true,
        'LAST' => true, 'NULLS' => true, 'CAST' => true, 'FILTER' => true,
        'WITHIN' => true,
    ];

    /**
     * Single-character operator lookup table. Used by tryReadOperator to
     * avoid allocating a haystack array on every character.
     */
    private const SINGLE_OPERATORS = [
        '=' => true,
        '<' => true,
        '>' => true,
        '+' => true,
        '-' => true,
        '/' => true,
        '%' => true,
    ];

    private string $sql;

    private int $length;

    private int $pos;

    /**
     * @return Token[]
     */
    public function tokenize(string $sql): array
    {
        $this->sql = $sql;
        $this->length = strlen($sql);
        $this->pos = 0;

        $tokens = [];
        $quoteChar = $this->getIdentifierQuoteChar();

        while ($this->pos < $this->length) {
            $start = $this->pos;
            $char = $this->sql[$this->pos];

            $tokens[] = match (true) {
                $char === ' ' || $char === "\t" || $char === "\n" || $char === "\r" => $this->readWhitespace($start),
                $char === '-' => $this->readDashPrefix($start),
                $char === '/' => $this->readSlashPrefix($start),
                $char === '\'' => $this->readString($start),
                $char === $quoteChar => $this->readQuotedIdentifier($start, $quoteChar),
                $char === '"' => $this->readQuotedIdentifier($start, '"'),
                $char >= '0' && $char <= '9' => $this->readNumber($start),
                ($char >= 'a' && $char <= 'z') || ($char >= 'A' && $char <= 'Z') || $char === '_' => $this->readIdentifierOrKeyword($start),
                $char === '(' => $this->consumeSingleChar(TokenType::LeftParen, '(', $start),
                $char === ')' => $this->consumeSingleChar(TokenType::RightParen, ')', $start),
                $char === ',' => $this->consumeSingleChar(TokenType::Comma, ',', $start),
                $char === ';' => $this->consumeSingleChar(TokenType::Semicolon, ';', $start),
                $char === '.' => $this->readDot($start),
                $char === '*' => $this->consumeSingleChar(TokenType::Star, '*', $start),
                $char === '?' => $this->consumeSingleChar(TokenType::Placeholder, '?', $start),
                $char === ':' => $this->readColonPrefix($start),
                $char === '$' => $this->readDollarPrefix($start),
                default => $this->readOperatorOrUnknown($start, $char),
            };
        }

        $tokens[] = new Token(TokenType::Eof, '', $this->pos);

        return $tokens;
    }

    private function consumeSingleChar(TokenType $type, string $value, int $start): Token
    {
        $this->pos++;
        return new Token($type, $value, $start);
    }

    private function readDashPrefix(int $start): Token
    {
        if ($this->peek(1) === '-') {
            return $this->readLineComment($start);
        }

        return $this->readOperatorOrUnknown($start, '-');
    }

    private function readSlashPrefix(int $start): Token
    {
        if ($this->peek(1) === '*') {
            return $this->readBlockComment($start);
        }

        return $this->readOperatorOrUnknown($start, '/');
    }

    private function readDot(int $start): Token
    {
        $next = $this->peek(1);
        if ($next !== null && $this->isDigit($next)) {
            return $this->readNumber($start);
        }

        $this->pos++;
        return new Token(TokenType::Dot, '.', $start);
    }

    private function readColonPrefix(int $start): Token
    {
        $next = $this->peek(1);
        if ($next === ':') {
            $this->pos += 2;
            return new Token(TokenType::Operator, '::', $start);
        }
        if ($next !== null && $this->isIdentStart($next)) {
            return $this->readNamedPlaceholder($start);
        }

        $this->pos++;
        return new Token(TokenType::Operator, ':', $start);
    }

    private function readDollarPrefix(int $start): Token
    {
        $next = $this->peek(1);
        if ($next !== null && $this->isDigit($next)) {
            return $this->readNumberedPlaceholder($start);
        }

        $this->pos++;
        return new Token(TokenType::Operator, '$', $start);
    }

    private function readOperatorOrUnknown(int $start, string $char): Token
    {
        $op = $this->tryReadOperator($start);
        if ($op !== null) {
            return $op;
        }

        // Emit unknown characters as single-char operator tokens
        $this->pos++;
        return new Token(TokenType::Operator, $char, $start);
    }

    /**
     * @param Token[] $tokens
     * @return Token[]
     */
    public static function filter(array $tokens): array
    {
        $result = [];
        foreach ($tokens as $token) {
            if (
                $token->type !== TokenType::Whitespace
                && $token->type !== TokenType::LineComment
                && $token->type !== TokenType::BlockComment
            ) {
                $result[] = $token;
            }
        }
        return $result;
    }

    protected function getIdentifierQuoteChar(): string
    {
        return '`';
    }

    private function peek(int $offset): ?string
    {
        $idx = $this->pos + $offset;
        return $idx < $this->length ? $this->sql[$idx] : null;
    }

    private function isDigit(string $char): bool
    {
        return $char >= '0' && $char <= '9';
    }

    private function isIdentStart(string $char): bool
    {
        return ($char >= 'a' && $char <= 'z')
            || ($char >= 'A' && $char <= 'Z')
            || $char === '_';
    }

    private function isIdentChar(string $char): bool
    {
        return $this->isIdentStart($char) || $this->isDigit($char);
    }

    private function readWhitespace(int $start): Token
    {
        while ($this->pos < $this->length) {
            $c = $this->sql[$this->pos];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $this->pos++;
            } else {
                break;
            }
        }

        return new Token(TokenType::Whitespace, substr($this->sql, $start, $this->pos - $start), $start);
    }

    private function readLineComment(int $start): Token
    {
        $this->pos += 2;
        while ($this->pos < $this->length && $this->sql[$this->pos] !== "\n") {
            $this->pos++;
        }

        return new Token(TokenType::LineComment, substr($this->sql, $start, $this->pos - $start), $start);
    }

    private function readBlockComment(int $start): Token
    {
        $this->pos += 2;
        $terminated = false;
        while ($this->pos < $this->length - 1) {
            if ($this->sql[$this->pos] === '*' && $this->sql[$this->pos + 1] === '/') {
                $this->pos += 2;
                $terminated = true;
                break;
            }
            $this->pos++;
        }

        if (!$terminated) {
            $this->pos = $this->length;
        }

        return new Token(TokenType::BlockComment, substr($this->sql, $start, $this->pos - $start), $start);
    }

    private function readString(int $start): Token
    {
        $this->pos++;
        $terminated = false;
        while ($this->pos < $this->length) {
            $char = $this->sql[$this->pos];
            if ($char === '\\') {
                // Backslash escape: skip next character. Reject trailing backslash at EOF.
                if ($this->pos + 1 >= $this->length) {
                    throw new ValidationException('Unterminated string literal');
                }
                $this->pos += 2;
                continue;
            }
            if ($char === '\'') {
                $this->pos++;
                // Check for escaped quote ''
                if ($this->pos < $this->length && $this->sql[$this->pos] === '\'') {
                    $this->pos++;
                    continue;
                }
                $terminated = true;
                break;
            }
            $this->pos++;
        }

        if (!$terminated) {
            throw new ValidationException('Unterminated string literal');
        }

        return new Token(TokenType::String, substr($this->sql, $start, $this->pos - $start), $start);
    }

    private function readQuotedIdentifier(int $start, string $quote): Token
    {
        $this->pos++;
        $terminated = false;
        while ($this->pos < $this->length) {
            if ($this->sql[$this->pos] === $quote) {
                $this->pos++;
                // Check for escaped quote (doubled)
                if ($this->pos < $this->length && $this->sql[$this->pos] === $quote) {
                    $this->pos++;
                    continue;
                }
                $terminated = true;
                break;
            }
            $this->pos++;
        }

        if (!$terminated) {
            throw new ValidationException('Unterminated quoted identifier');
        }

        return new Token(TokenType::QuotedIdentifier, substr($this->sql, $start, $this->pos - $start), $start);
    }

    private function readNumber(int $start): Token
    {
        $isFloat = false;

        // Handle dot-prefixed floats like .5
        if ($this->pos < $this->length && $this->sql[$this->pos] === '.') {
            $isFloat = true;
            $this->pos++;
        }

        while ($this->pos < $this->length && $this->isDigit($this->sql[$this->pos])) {
            $this->pos++;
        }

        if (!$isFloat && $this->pos < $this->length && $this->sql[$this->pos] === '.') {
            $next = $this->peek(1);
            if ($next !== null && $this->isDigit($next)) {
                $isFloat = true;
                $this->pos++;
                while ($this->pos < $this->length && $this->isDigit($this->sql[$this->pos])) {
                    $this->pos++;
                }
            }
        }

        // Handle scientific notation (e.g. 1.5e10, 1e-3, 2.5E+8)
        if ($this->pos < $this->length) {
            $c = $this->sql[$this->pos];
            if ($c === 'e' || $c === 'E') {
                $nextIdx = $this->pos + 1;
                if ($nextIdx < $this->length && ($this->sql[$nextIdx] === '+' || $this->sql[$nextIdx] === '-')) {
                    $nextIdx++;
                }
                if ($nextIdx < $this->length && $this->isDigit($this->sql[$nextIdx])) {
                    $isFloat = true;
                    $this->pos = $nextIdx;
                    while ($this->pos < $this->length && $this->isDigit($this->sql[$this->pos])) {
                        $this->pos++;
                    }
                }
            }
        }

        $value = substr($this->sql, $start, $this->pos - $start);
        $type = $isFloat ? TokenType::Float : TokenType::Integer;

        return new Token($type, $value, $start);
    }

    private function readIdentifierOrKeyword(int $start): Token
    {
        while ($this->pos < $this->length && $this->isIdentChar($this->sql[$this->pos])) {
            $this->pos++;
        }

        $value = substr($this->sql, $start, $this->pos - $start);
        $upper = strtoupper($value);

        if ($upper === 'NULL') {
            return new Token(TokenType::Null, $upper, $start);
        }

        if ($upper === 'TRUE' || $upper === 'FALSE') {
            return new Token(TokenType::Boolean, $upper, $start);
        }

        if (isset(self::KEYWORD_MAP[$upper])) {
            return new Token(TokenType::Keyword, $upper, $start);
        }

        return new Token(TokenType::Identifier, $value, $start);
    }

    private function readNamedPlaceholder(int $start): Token
    {
        $this->pos++;
        while ($this->pos < $this->length && $this->isIdentChar($this->sql[$this->pos])) {
            $this->pos++;
        }

        return new Token(TokenType::NamedPlaceholder, substr($this->sql, $start, $this->pos - $start), $start);
    }

    private function readNumberedPlaceholder(int $start): Token
    {
        $this->pos++;
        while ($this->pos < $this->length && $this->isDigit($this->sql[$this->pos])) {
            $this->pos++;
        }

        return new Token(TokenType::NumberedPlaceholder, substr($this->sql, $start, $this->pos - $start), $start);
    }

    private function tryReadOperator(int $start): ?Token
    {
        $char = $this->sql[$this->pos];
        $next = $this->peek(1);

        $twoChar = match (true) {
            $char === '<' && $next === '=' => '<=',
            $char === '>' && $next === '=' => '>=',
            $char === '!' && $next === '=' => '!=',
            $char === '<' && $next === '>' => '<>',
            $char === '|' && $next === '|' => '||',
            default => null,
        };

        if ($twoChar !== null) {
            $this->pos += 2;
            return new Token(TokenType::Operator, $twoChar, $start);
        }

        if (isset(self::SINGLE_OPERATORS[$char])) {
            $this->pos++;
            return new Token(TokenType::Operator, $char, $start);
        }

        return null;
    }
}
