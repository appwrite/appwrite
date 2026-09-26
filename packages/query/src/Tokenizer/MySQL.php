<?php

namespace Utopia\Query\Tokenizer;

class MySQL extends Tokenizer
{
    /**
     * @return Token[]
     */
    public function tokenize(string $sql): array
    {
        $sql = $this->replaceHashComments($sql);
        return parent::tokenize($sql);
    }

    /**
     * Replace MySQL-specific # line comments with standard -- line comments
     * so the base tokenizer handles them correctly.
     */
    private function replaceHashComments(string $sql): string
    {
        $result = '';
        $len = strlen($sql);
        $i = 0;

        while ($i < $len) {
            $char = $sql[$i];

            if ($char === '\'') {
                $result .= $char;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    if ($c === '\\') {
                        $result .= $c;
                        $i++;
                        if ($i < $len) {
                            $result .= $sql[$i];
                            $i++;
                        }
                        continue;
                    }
                    if ($c === '\'') {
                        $result .= $c;
                        $i++;
                        if ($i < $len && $sql[$i] === '\'') {
                            $result .= $sql[$i];
                            $i++;
                            continue;
                        }
                        break;
                    }
                    $result .= $c;
                    $i++;
                }
                continue;
            }

            if ($char === '`') {
                $result .= $char;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    if ($c === '`') {
                        $result .= $c;
                        $i++;
                        if ($i < $len && $sql[$i] === '`') {
                            $result .= $sql[$i];
                            $i++;
                            continue;
                        }
                        break;
                    }
                    $result .= $c;
                    $i++;
                }
                continue;
            }

            if ($char === '"') {
                $result .= $char;
                $i++;
                while ($i < $len) {
                    $c = $sql[$i];
                    if ($c === '\\') {
                        $result .= $c;
                        $i++;
                        if ($i < $len) {
                            $result .= $sql[$i];
                            $i++;
                        }
                        continue;
                    }
                    if ($c === '"') {
                        $result .= $c;
                        $i++;
                        if ($i < $len && $sql[$i] === '"') {
                            $result .= $sql[$i];
                            $i++;
                            continue;
                        }
                        break;
                    }
                    $result .= $c;
                    $i++;
                }
                continue;
            }

            if ($char === '#') {
                $result .= '-- ';
                $i++;
                continue;
            }

            $result .= $char;
            $i++;
        }

        return $result;
    }
}
