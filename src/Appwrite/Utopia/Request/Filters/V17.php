<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Query;

class V17 extends Filter
{
    protected const CHAR_SINGLE_QUOTE = '\'';
    protected const CHAR_DOUBLE_QUOTE = '"';
    protected const CHAR_COMMA = ',';
    protected const CHAR_SPACE = ' ';
    protected const CHAR_BRACKET_START = '[';
    protected const CHAR_BRACKET_END = ']';
    protected const CHAR_PARENTHESES_START = '(';
    protected const CHAR_PARENTHESES_END = ')';
    protected const CHAR_BACKSLASH = '\\';

    // Convert 1.4 params to 1.5
    public function parse(array $content, string $model): array
    {
        switch ($model) {
            case 'account.updateRecovery':
                unset($content['passwordAgain']);
                break;
                // Queries
            case 'account.listIdentities':
            case 'account.listLogs':
            case 'databases.list':
            case 'databases.listLogs':
            case 'databases.listCollections':
            case 'databases.listCollectionLogs':
            case 'databases.listAttributes':
            case 'databases.listIndexes':
            case 'databases.listDocuments':
            case 'databases.getDocument':
            case 'databases.listDocumentLogs':
            case 'functions.list':
            case 'functions.listDeployments':
            case 'functions.listExecutions':
            case 'migrations.list':
            case 'projects.list':
            case 'proxy.listRules':
            case 'storage.listBuckets':
            case 'storage.listFiles':
            case 'teams.list':
            case 'teams.listMemberships':
            case 'teams.listLogs':
            case 'users.list':
            case 'users.listLogs':
            case 'users.listIdentities':
            case 'vcs.listInstallations':
                $content = $this->convertOldQueries($content);
                break;
        }
        return $content;
    }

    private function convertOldQueries(array $content): array
    {
        if (!isset($content['queries'])) {
            return $content;
        }

        $parsed = [];
        foreach ($content['queries'] as $query) {
            try {
                $query = $this->parseQuery($query);
                $parsed[] = json_encode(array_filter($query->toArray()));
            } catch (\Throwable $th) {
                throw new \Exception("Invalid query: {$query}", previous: $th);
            }
        }

        $content['queries'] = $parsed;

        return $content;
    }

    // 1.4 query parser
    public function parseQuery(string $filter): Query
    {
        // Init empty vars we fill later
        $method = '';
        $params = [];

        // Separate method from filter
        $paramsStart = mb_strpos($filter, '(');

        if ($paramsStart === false) {
            throw new \Exception('Invalid query');
        }

        $method = mb_substr($filter, 0, $paramsStart);

        // Separate params from filter
        $paramsEnd = \strlen($filter) - 1; // -1 to ignore )
        $parametersStart = $paramsStart + 1; // +1 to ignore (

        // Check for deprecated query syntax
        if (\str_contains($method, '.')) {
            throw new \Exception('Invalid query method');
        }

        $currentParam = ""; // We build param here before pushing when it's ended
        $currentArrayParam = []; // We build array param here before pushing when it's ended

        $stack = []; // State for stack of parentheses
        $stackCount = 0; // Length of stack array. Kept as variable to improve performance
        $stringStackState = null; // State for string support


        // Loop thorough all characters
        for ($i = $parametersStart; $i < $paramsEnd; $i++) {
            $char = $filter[$i];

            $isStringStack = $stringStackState !== null;
            $isArrayStack = !$isStringStack && $stackCount > 0;

            if ($char === static::CHAR_BACKSLASH) {
                if (!(static::isSpecialChar($filter[$i + 1]))) {
                    static::appendSymbol($isStringStack, $filter[$i], $i, $filter, $currentParam);
                }

                static::appendSymbol($isStringStack, $filter[$i + 1], $i, $filter, $currentParam);
                $i++;

                continue;
            }

            // String support + escaping support
            if (
                (self::isQuote($char)) && // Must be string indicator
                ($filter[$i - 1] !== static::CHAR_BACKSLASH || $filter[$i - 2] === static::CHAR_BACKSLASH) // Must not be escaped;
            ) {
                if ($isStringStack) {
                    // Dont mix-up string symbols. Only allow the same as on start
                    if ($char === $stringStackState) {
                        // End of string
                        $stringStackState = null;
                    }

                    // Either way, add symbol to builder
                    static::appendSymbol($isStringStack, $char, $i, $filter, $currentParam);
                } else {
                    // Start of string
                    $stringStackState = $char;
                    static::appendSymbol($isStringStack, $char, $i, $filter, $currentParam);
                }

                continue;
            }

            // Array support
            if (!($isStringStack)) {
                if ($char === static::CHAR_BRACKET_START) {
                    // Start of array
                    $stack[] = $char;
                    $stackCount++;
                    continue;
                } elseif ($char === static::CHAR_BRACKET_END) {
                    // End of array
                    \array_pop($stack);
                    $stackCount--;

                    if (strlen($currentParam)) {
                        $currentArrayParam[] = $currentParam;
                    }

                    $params[] = $currentArrayParam;
                    $currentArrayParam = [];
                    $currentParam = "";

                    continue;
                } elseif ($char === static::CHAR_COMMA) { // Params separation support
                    // If in array stack, dont merge yet, just mark it in array param builder
                    if ($isArrayStack) {
                        $currentArrayParam[] = $currentParam;
                        $currentParam = "";
                    } else {
                        // Append from parap builder. Either value, or array
                        if (empty($currentArrayParam)) {
                            if (strlen($currentParam)) {
                                $params[] = $currentParam;
                            }

                            $currentParam = "";
                        }
                    }
                    continue;
                }
            }

            // Value, not relevant to syntax
            static::appendSymbol($isStringStack, $char, $i, $filter, $currentParam);
        }

        if (strlen($currentParam)) {
            $params[] = $currentParam;
            $currentParam = "";
        }

        $parsedParams = [];

        foreach ($params as $param) {
            // If array, parse each child separatelly
            if (\is_array($param)) {
                foreach ($param as $element) {
                    $arr[] = self::parseValue($element);
                }

                $parsedParams[] = $arr ?? [];
            } else {
                $parsedParams[] = self::parseValue($param);
            }
        }

        switch ($method) {
            case Query::TYPE_EQUAL:
            case Query::TYPE_NOT_EQUAL:
            case Query::TYPE_LESSER:
            case Query::TYPE_LESSER_EQUAL:
            case Query::TYPE_GREATER:
            case Query::TYPE_GREATER_EQUAL:
            case Query::TYPE_CONTAINS:
            case Query::TYPE_SEARCH:
            case Query::TYPE_IS_NULL:
            case Query::TYPE_IS_NOT_NULL:
            case Query::TYPE_STARTS_WITH:
            case Query::TYPE_ENDS_WITH:
                $attribute = $parsedParams[0] ?? '';
                if (count($parsedParams) < 2) {
                    return new Query($method, $attribute);
                }
                return new Query($method, $attribute, \is_array($parsedParams[1]) ? $parsedParams[1] : [$parsedParams[1]]);

            case Query::TYPE_BETWEEN:
                return new Query($method, $parsedParams[0], [$parsedParams[1], $parsedParams[2]]);
            case Query::TYPE_SELECT:
                return new Query($method, values: $parsedParams[0]);
            case Query::TYPE_ORDER_ASC:
            case Query::TYPE_ORDER_DESC:
                return new Query($method, $parsedParams[0] ?? '');

            case Query::TYPE_LIMIT:
            case Query::TYPE_OFFSET:
            case Query::TYPE_CURSOR_AFTER:
            case Query::TYPE_CURSOR_BEFORE:
                if (count($parsedParams) > 0) {
                    return new Query($method, values: [$parsedParams[0]]);
                }
                return new Query($method);

            default:
                return new Query($method);
        }
    }

    /**
     * Parses value.
     *
     * @param string $value
     * @return mixed
     */
    private function parseValue(string $value): mixed
    {
        $value = \trim($value);

        if ($value === 'false') { // Boolean value
            return false;
        } elseif ($value === 'true') {
            return true;
        } elseif ($value === 'null') { // Null value
            return null;
        } elseif (\is_numeric($value)) { // Numeric value
            // Cast to number
            return $value + 0;
        } elseif (\str_starts_with($value, static::CHAR_DOUBLE_QUOTE) || \str_starts_with($value, static::CHAR_SINGLE_QUOTE)) { // String param
            $value = \substr($value, 1, -1); // Remove '' or ""
            return $value;
        }

        // Unknown format
        return $value;
    }

    /**
     * Utility method to only append symbol if relevant.
     *
     * @param bool $isStringStack
     * @param string $char
     * @param int $index
     * @param string $filter
     * @param string $currentParam
     * @return void
     */
    private function appendSymbol(bool $isStringStack, string $char, int $index, string $filter, string &$currentParam): void
    {
        // Ignore spaces and commas outside of string
        $canBeIgnored = false;

        if ($char === static::CHAR_SPACE) {
            $canBeIgnored = true;
        } elseif ($char === static::CHAR_COMMA) {
            $canBeIgnored = true;
        }

        if ($canBeIgnored) {
            if ($isStringStack) {
                $currentParam .= $char;
            }
        } else {
            $currentParam .= $char;
        }
    }

    private function isQuote(string $char): bool
    {
        if ($char === self::CHAR_SINGLE_QUOTE) {
            return true;
        } elseif ($char === self::CHAR_DOUBLE_QUOTE) {
            return true;
        }

        return false;
    }

    private function isSpecialChar(string $char): bool
    {
        if ($char === static::CHAR_COMMA) {
            return true;
        } elseif ($char === static::CHAR_BRACKET_END) {
            return true;
        } elseif ($char === static::CHAR_BRACKET_START) {
            return true;
        } elseif ($char === static::CHAR_DOUBLE_QUOTE) {
            return true;
        } elseif ($char === static::CHAR_SINGLE_QUOTE) {
            return true;
        }

        return false;
    }
}
