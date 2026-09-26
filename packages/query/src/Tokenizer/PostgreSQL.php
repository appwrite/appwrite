<?php

namespace Utopia\Query\Tokenizer;

class PostgreSQL extends Tokenizer
{
    protected function getIdentifierQuoteChar(): string
    {
        return '"';
    }

    /**
     * @return Token[]
     */
    public function tokenize(string $sql): array
    {
        $tokens = parent::tokenize($sql);
        return $this->mergePostgresOperators($tokens);
    }

    /**
     * Merge adjacent operator tokens into PostgreSQL-specific multi-char operators.
     * Handles: @>, <@, <=>, <->, <#>, ?|, ?&
     *
     * @param Token[] $tokens
     * @return Token[]
     */
    private function mergePostgresOperators(array $tokens): array
    {
        $result = [];
        $count = count($tokens);
        $i = 0;

        while ($i < $count) {
            $token = $tokens[$i];

            if ($i + 2 < $count && $this->isOp($token) && $this->isOp($tokens[$i + 1]) && $this->isOp($tokens[$i + 2])) {
                $three = $token->value . $tokens[$i + 1]->value . $tokens[$i + 2]->value;
                if (in_array($three, ['<->', '<#>'], true)) {
                    $result[] = new Token(TokenType::Operator, $three, $token->position);
                    $i += 3;
                    continue;
                }
            }

            if ($i + 1 < $count && $this->isOp($token) && $this->isOp($tokens[$i + 1])) {
                $two = $token->value . $tokens[$i + 1]->value;
                if (in_array($two, ['@>', '<@', '<=>'], true)) {
                    $result[] = new Token(TokenType::Operator, $two, $token->position);
                    $i += 2;
                    continue;
                }
            }

            if ($i + 1 < $count && $token->type === TokenType::Placeholder && $token->value === '?') {
                $next = $tokens[$i + 1];
                if ($next->type === TokenType::Operator && ($next->value === '|' || $next->value === '&')) {
                    $result[] = new Token(TokenType::Operator, '?' . $next->value, $token->position);
                    $i += 2;
                    continue;
                }
            }

            $result[] = $token;
            $i++;
        }

        return $result;
    }

    private function isOp(Token $token): bool
    {
        return $token->type === TokenType::Operator || $token->type === TokenType::Star;
    }
}
