<?php

namespace Appwrite\Utopia\Request\Filters;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Request\Filter;
use Utopia\Database\Query;

class V22 extends Filter
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
                $content = $this->convertQueries($content);
                break;
        }
        return $content;
    }

    private function convertQueries(array $content): array
    {
        var_dump('convertQueries');

        if (!isset($content['queries'])) {
            return $content;
        }

        $queries = [];

        /** @var Query $query */

        foreach ($content['queries'] as $query) {
            try {
                var_dump($query);

                if ($query->getMethod() === 'select') {
                    $selects = $query->getValue();

                    foreach ($selects as $select) {
                        $queries[] = Query::select($select);
                    }
                }
                else {
                    $queries[] = $query;
                }

            } catch (\Throwable $th) {
                throw new Exception(Exception::GENERAL_QUERY_INVALID, $th->getMessage());
            }
        }

        $content['queries'] = $queries;

        return $content;
    }
}
