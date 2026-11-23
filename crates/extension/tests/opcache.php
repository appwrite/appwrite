<?php

// What opcache does with the extension's classes when it caches a script that extends them:
// it copies each PHP subclass, with the defaults and constants it inherits, into shared memory,
// and frees what it copied from as if PHP's allocator held it. A default or constant the
// extension built outside that allocator corrupts the heap there. run.sh runs this with
// opcache on (`opcache.enable_cli=1`), every library switched on, and PHP's allocator; it
// prints `ok` and exits 0, or crashes.

require __DIR__ . '/../../../vendor/autoload.php';

if (!(opcache_get_status(false)['opcache_enabled'] ?? false)) {
    fwrite(STDERR, 'opcache is not enabled for the CLI' . PHP_EOL);
    exit(1);
}

// A subclass of every class the extension registers that PHP code may extend (the ones whose
// library is switched off are PHP's, which costs nothing to check too; the validators and the
// password hashes are always the extension's). opcache links each one
// through its inheritance cache, which copies the class into shared memory.
if (class_exists(\Utopia\Validator\Validator::class)) {
    abstract class ExtendsUtopiaValidatorValidator extends \Utopia\Validator\Validator
    {
    }
}
if (class_exists(\Utopia\Validator\Boolean::class)) {
    class ExtendsUtopiaValidatorBoolean extends \Utopia\Validator\Boolean
    {
    }
}
if (class_exists(\Utopia\Validator\Integer::class)) {
    class ExtendsUtopiaValidatorInteger extends \Utopia\Validator\Integer
    {
    }
}
if (class_exists(\Utopia\Validator\FloatValidator::class)) {
    class ExtendsUtopiaValidatorFloatValidator extends \Utopia\Validator\FloatValidator
    {
    }
}
if (class_exists(\Utopia\Validator\Numeric::class)) {
    class ExtendsUtopiaValidatorNumeric extends \Utopia\Validator\Numeric
    {
    }
}
if (class_exists(\Utopia\Validator\Range::class)) {
    class ExtendsUtopiaValidatorRange extends \Utopia\Validator\Range
    {
    }
}
if (class_exists(\Utopia\Validator\Port::class)) {
    class ExtendsUtopiaValidatorPort extends \Utopia\Validator\Port
    {
    }
}
if (class_exists(\Utopia\Validator\HexColor::class)) {
    class ExtendsUtopiaValidatorHexColor extends \Utopia\Validator\HexColor
    {
    }
}
if (class_exists(\Utopia\Validator\Wildcard::class)) {
    class ExtendsUtopiaValidatorWildcard extends \Utopia\Validator\Wildcard
    {
    }
}
if (class_exists(\Utopia\Validator\Text::class)) {
    class ExtendsUtopiaValidatorText extends \Utopia\Validator\Text
    {
    }
}
if (class_exists(\Utopia\Validator\Identifier::class)) {
    class ExtendsUtopiaValidatorIdentifier extends \Utopia\Validator\Identifier
    {
    }
}
if (class_exists(\Utopia\Validator\WhiteList::class)) {
    class ExtendsUtopiaValidatorWhiteList extends \Utopia\Validator\WhiteList
    {
    }
}
if (class_exists(\Utopia\Validator\Contains::class)) {
    class ExtendsUtopiaValidatorContains extends \Utopia\Validator\Contains
    {
    }
}
if (class_exists(\Utopia\Validator\Label::class)) {
    class ExtendsUtopiaValidatorLabel extends \Utopia\Validator\Label
    {
    }
}
if (class_exists(\Utopia\Validator\Hostname::class)) {
    class ExtendsUtopiaValidatorHostname extends \Utopia\Validator\Hostname
    {
    }
}
if (class_exists(\Utopia\Validator\Domain::class)) {
    class ExtendsUtopiaValidatorDomain extends \Utopia\Validator\Domain
    {
    }
}
if (class_exists(\Utopia\Validator\Host::class)) {
    class ExtendsUtopiaValidatorHost extends \Utopia\Validator\Host
    {
    }
}
if (class_exists(\Utopia\Validator\IP::class)) {
    class ExtendsUtopiaValidatorIP extends \Utopia\Validator\IP
    {
    }
}
if (class_exists(\Utopia\Validator\URL::class)) {
    class ExtendsUtopiaValidatorURL extends \Utopia\Validator\URL
    {
    }
}
if (class_exists(\Utopia\Validator\Phone::class)) {
    class ExtendsUtopiaValidatorPhone extends \Utopia\Validator\Phone
    {
    }
}
if (class_exists(\Utopia\Validator\Nullable::class)) {
    class ExtendsUtopiaValidatorNullable extends \Utopia\Validator\Nullable
    {
    }
}
if (class_exists(\Utopia\Validator\ArrayList::class)) {
    class ExtendsUtopiaValidatorArrayList extends \Utopia\Validator\ArrayList
    {
    }
}
if (class_exists(\Utopia\Validator\AllOf::class)) {
    class ExtendsUtopiaValidatorAllOf extends \Utopia\Validator\AllOf
    {
    }
}
if (class_exists(\Utopia\Validator\AnyOf::class)) {
    class ExtendsUtopiaValidatorAnyOf extends \Utopia\Validator\AnyOf
    {
    }
}
if (class_exists(\Utopia\Validator\NoneOf::class)) {
    class ExtendsUtopiaValidatorNoneOf extends \Utopia\Validator\NoneOf
    {
    }
}
if (class_exists(\Utopia\Validator\Multiple::class)) {
    class ExtendsUtopiaValidatorMultiple extends \Utopia\Validator\Multiple
    {
    }
}
if (class_exists(\Utopia\Validator\Assoc::class)) {
    class ExtendsUtopiaValidatorAssoc extends \Utopia\Validator\Assoc
    {
    }
}
if (class_exists(\Utopia\Validator\JSON::class)) {
    class ExtendsUtopiaValidatorJSON extends \Utopia\Validator\JSON
    {
    }
}
if (class_exists(\Utopia\Validator\JSON\ArrayValidator::class)) {
    class ExtendsUtopiaValidatorJSONArrayValidator extends \Utopia\Validator\JSON\ArrayValidator
    {
    }
}
if (class_exists(\Utopia\Validator\JSON\ObjectValidator::class)) {
    class ExtendsUtopiaValidatorJSONObjectValidator extends \Utopia\Validator\JSON\ObjectValidator
    {
    }
}
if (class_exists(\Utopia\Validator\JSON\FCM::class)) {
    class ExtendsUtopiaValidatorJSONFCM extends \Utopia\Validator\JSON\FCM
    {
    }
}
if (class_exists(\Utopia\Validator\Globstar::class)) {
    class ExtendsUtopiaValidatorGlobstar extends \Utopia\Validator\Globstar
    {
    }
}
if (class_exists(\Utopia\Auth\Hash::class)) {
    abstract class ExtendsUtopiaAuthHash extends \Utopia\Auth\Hash
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\Argon2::class)) {
    class ExtendsUtopiaAuthHashesArgon2 extends \Utopia\Auth\Hashes\Argon2
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\Bcrypt::class)) {
    class ExtendsUtopiaAuthHashesBcrypt extends \Utopia\Auth\Hashes\Bcrypt
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\MD5::class)) {
    class ExtendsUtopiaAuthHashesMD5 extends \Utopia\Auth\Hashes\MD5
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\PHPass::class)) {
    class ExtendsUtopiaAuthHashesPHPass extends \Utopia\Auth\Hashes\PHPass
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\Plaintext::class)) {
    class ExtendsUtopiaAuthHashesPlaintext extends \Utopia\Auth\Hashes\Plaintext
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\Scrypt::class)) {
    class ExtendsUtopiaAuthHashesScrypt extends \Utopia\Auth\Hashes\Scrypt
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\ScryptModified::class)) {
    class ExtendsUtopiaAuthHashesScryptModified extends \Utopia\Auth\Hashes\ScryptModified
    {
    }
}
if (class_exists(\Utopia\Auth\Hashes\Sha::class)) {
    class ExtendsUtopiaAuthHashesSha extends \Utopia\Auth\Hashes\Sha
    {
    }
}
if (class_exists(\Utopia\DSN\DSN::class)) {
    class ExtendsUtopiaDSNDSN extends \Utopia\DSN\DSN
    {
    }
}
if (class_exists(\Utopia\Locale\Locale::class)) {
    class ExtendsUtopiaLocaleLocale extends \Utopia\Locale\Locale
    {
    }
}
if (class_exists(\Utopia\Domains\Domain::class)) {
    class ExtendsUtopiaDomainsDomain extends \Utopia\Domains\Domain
    {
    }
}
if (class_exists(\Utopia\Emails\Email::class)) {
    class ExtendsUtopiaEmailsEmail extends \Utopia\Emails\Email
    {
    }
}
if (interface_exists(\Utopia\Query\Classifier::class)) {
    interface ExtendsUtopiaQueryClassifier extends \Utopia\Query\Classifier
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\AnalyzeTable::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureAnalyzeTable extends \Utopia\Query\Schema\Feature\AnalyzeTable
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\ColumnComments::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureColumnComments extends \Utopia\Query\Schema\Feature\ColumnComments
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\CreatePartition::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureCreatePartition extends \Utopia\Query\Schema\Feature\CreatePartition
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\Databases::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureDatabases extends \Utopia\Query\Schema\Feature\Databases
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\DropPartition::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureDropPartition extends \Utopia\Query\Schema\Feature\DropPartition
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\ForeignKeys::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureForeignKeys extends \Utopia\Query\Schema\Feature\ForeignKeys
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\MaterializedViews::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureMaterializedViews extends \Utopia\Query\Schema\Feature\MaterializedViews
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\Partitioning::class)) {
    interface ExtendsUtopiaQuerySchemaFeaturePartitioning extends \Utopia\Query\Schema\Feature\Partitioning
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\Procedures::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureProcedures extends \Utopia\Query\Schema\Feature\Procedures
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\RenameIndex::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureRenameIndex extends \Utopia\Query\Schema\Feature\RenameIndex
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\ReplaceView::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureReplaceView extends \Utopia\Query\Schema\Feature\ReplaceView
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\Sequences::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureSequences extends \Utopia\Query\Schema\Feature\Sequences
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\TableComments::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureTableComments extends \Utopia\Query\Schema\Feature\TableComments
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\Triggers::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureTriggers extends \Utopia\Query\Schema\Feature\Triggers
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\Types::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureTypes extends \Utopia\Query\Schema\Feature\Types
    {
    }
}
if (interface_exists(\Utopia\Query\Schema\Feature\Views::class)) {
    interface ExtendsUtopiaQuerySchemaFeatureViews extends \Utopia\Query\Schema\Feature\Views
    {
    }
}
if (class_exists(\Utopia\Query\Classifier\SQL::class)) {
    abstract class ExtendsUtopiaQueryClassifierSQL extends \Utopia\Query\Classifier\SQL
    {
    }
}
if (class_exists(\Utopia\Query\Classifier\MySQL::class)) {
    class ExtendsUtopiaQueryClassifierMySQL extends \Utopia\Query\Classifier\MySQL
    {
    }
}
if (class_exists(\Utopia\Query\Classifier\PostgreSQL::class)) {
    class ExtendsUtopiaQueryClassifierPostgreSQL extends \Utopia\Query\Classifier\PostgreSQL
    {
    }
}
if (class_exists(\Utopia\Query\Classifier\MongoDB::class)) {
    class ExtendsUtopiaQueryClassifierMongoDB extends \Utopia\Query\Classifier\MongoDB
    {
    }
}
if (class_exists(\Utopia\Query\NativeQuery::class)) {
    class ExtendsUtopiaQueryNativeQuery extends \Utopia\Query\NativeQuery
    {
    }
}
if (class_exists(\Utopia\Query\Tokenizer\Tokenizer::class)) {
    class ExtendsUtopiaQueryTokenizerTokenizer extends \Utopia\Query\Tokenizer\Tokenizer
    {
    }
}
if (class_exists(\Utopia\Query\Tokenizer\MySQL::class)) {
    class ExtendsUtopiaQueryTokenizerMySQL extends \Utopia\Query\Tokenizer\MySQL
    {
    }
}
if (class_exists(\Utopia\Query\Tokenizer\MariaDB::class)) {
    class ExtendsUtopiaQueryTokenizerMariaDB extends \Utopia\Query\Tokenizer\MariaDB
    {
    }
}
if (class_exists(\Utopia\Query\Tokenizer\PostgreSQL::class)) {
    class ExtendsUtopiaQueryTokenizerPostgreSQL extends \Utopia\Query\Tokenizer\PostgreSQL
    {
    }
}
if (class_exists(\Utopia\Query\Tokenizer\SQLite::class)) {
    class ExtendsUtopiaQueryTokenizerSQLite extends \Utopia\Query\Tokenizer\SQLite
    {
    }
}
if (class_exists(\Utopia\Query\Tokenizer\ClickHouse::class)) {
    class ExtendsUtopiaQueryTokenizerClickHouse extends \Utopia\Query\Tokenizer\ClickHouse
    {
    }
}
if (class_exists(\Utopia\Query\AST\Parser::class)) {
    class ExtendsUtopiaQueryASTParser extends \Utopia\Query\AST\Parser
    {
    }
}
if (class_exists(\Utopia\Query\AST\Serializer::class)) {
    class ExtendsUtopiaQueryASTSerializer extends \Utopia\Query\AST\Serializer
    {
    }
}
if (class_exists(\Utopia\Query\AST\Serializer\MySQL::class)) {
    class ExtendsUtopiaQueryASTSerializerMySQL extends \Utopia\Query\AST\Serializer\MySQL
    {
    }
}
if (class_exists(\Utopia\Query\AST\Serializer\MariaDB::class)) {
    class ExtendsUtopiaQueryASTSerializerMariaDB extends \Utopia\Query\AST\Serializer\MariaDB
    {
    }
}
if (class_exists(\Utopia\Query\AST\Serializer\PostgreSQL::class)) {
    class ExtendsUtopiaQueryASTSerializerPostgreSQL extends \Utopia\Query\AST\Serializer\PostgreSQL
    {
    }
}
if (class_exists(\Utopia\Query\AST\Serializer\SQLite::class)) {
    class ExtendsUtopiaQueryASTSerializerSQLite extends \Utopia\Query\AST\Serializer\SQLite
    {
    }
}
if (class_exists(\Utopia\Query\AST\Serializer\ClickHouse::class)) {
    class ExtendsUtopiaQueryASTSerializerClickHouse extends \Utopia\Query\AST\Serializer\ClickHouse
    {
    }
}
if (class_exists(\Utopia\Query\Schema::class)) {
    abstract class ExtendsUtopiaQuerySchema extends \Utopia\Query\Schema
    {
    }
}
if (class_exists(\Utopia\Query\Schema\SQL::class)) {
    abstract class ExtendsUtopiaQuerySchemaSQL extends \Utopia\Query\Schema\SQL
    {
    }
}
if (class_exists(\Utopia\Query\Schema\MySQL::class)) {
    class ExtendsUtopiaQuerySchemaMySQL extends \Utopia\Query\Schema\MySQL
    {
    }
}
if (class_exists(\Utopia\Query\Schema\PostgreSQL::class)) {
    class ExtendsUtopiaQuerySchemaPostgreSQL extends \Utopia\Query\Schema\PostgreSQL
    {
    }
}
if (class_exists(\Utopia\Query\Schema\SQLite::class)) {
    class ExtendsUtopiaQuerySchemaSQLite extends \Utopia\Query\Schema\SQLite
    {
    }
}
if (class_exists(\Utopia\Query\Schema\ClickHouse::class)) {
    class ExtendsUtopiaQuerySchemaClickHouse extends \Utopia\Query\Schema\ClickHouse
    {
    }
}
if (class_exists(\Utopia\Query\Schema\MongoDB::class)) {
    class ExtendsUtopiaQuerySchemaMongoDB extends \Utopia\Query\Schema\MongoDB
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Aggregates::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureAggregates extends \Utopia\Query\Builder\Feature\Aggregates
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\BitwiseAggregates::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureBitwiseAggregates extends \Utopia\Query\Builder\Feature\BitwiseAggregates
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\CTEs::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureCTEs extends \Utopia\Query\Builder\Feature\CTEs
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\ClickHouse\ApproximateAggregates::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureClickHouseApproximateAggregates extends \Utopia\Query\Builder\Feature\ClickHouse\ApproximateAggregates
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\ClickHouse\ArrayJoins::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureClickHouseArrayJoins extends \Utopia\Query\Builder\Feature\ClickHouse\ArrayJoins
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\ClickHouse\AsofJoins::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureClickHouseAsofJoins extends \Utopia\Query\Builder\Feature\ClickHouse\AsofJoins
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\ClickHouse\LimitBy::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureClickHouseLimitBy extends \Utopia\Query\Builder\Feature\ClickHouse\LimitBy
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\ClickHouse\WithFill::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureClickHouseWithFill extends \Utopia\Query\Builder\Feature\ClickHouse\WithFill
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\ConditionalAggregates::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureConditionalAggregates extends \Utopia\Query\Builder\Feature\ConditionalAggregates
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\CrossJoins::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureCrossJoins extends \Utopia\Query\Builder\Feature\CrossJoins
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Cube::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureCube extends \Utopia\Query\Builder\Feature\Cube
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Deletes::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureDeletes extends \Utopia\Query\Builder\Feature\Deletes
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\FullOuterJoins::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureFullOuterJoins extends \Utopia\Query\Builder\Feature\FullOuterJoins
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\FullTextSearch::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureFullTextSearch extends \Utopia\Query\Builder\Feature\FullTextSearch
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Hints::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureHints extends \Utopia\Query\Builder\Feature\Hints
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Hooks::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureHooks extends \Utopia\Query\Builder\Feature\Hooks
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\InsertOrIgnore::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureInsertOrIgnore extends \Utopia\Query\Builder\Feature\InsertOrIgnore
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Inserts::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureInserts extends \Utopia\Query\Builder\Feature\Inserts
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Joins::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureJoins extends \Utopia\Query\Builder\Feature\Joins
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Json::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureJson extends \Utopia\Query\Builder\Feature\Json
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\LateralJoins::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureLateralJoins extends \Utopia\Query\Builder\Feature\LateralJoins
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Locking::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureLocking extends \Utopia\Query\Builder\Feature\Locking
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\MariaDB\Returning::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureMariaDBReturning extends \Utopia\Query\Builder\Feature\MariaDB\Returning
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\MongoDB\ArrayPushModifiers::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureMongoDBArrayPushModifiers extends \Utopia\Query\Builder\Feature\MongoDB\ArrayPushModifiers
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\MongoDB\AtlasSearch::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureMongoDBAtlasSearch extends \Utopia\Query\Builder\Feature\MongoDB\AtlasSearch
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\MongoDB\ConditionalArrayUpdates::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureMongoDBConditionalArrayUpdates extends \Utopia\Query\Builder\Feature\MongoDB\ConditionalArrayUpdates
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\MongoDB\FieldUpdates::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureMongoDBFieldUpdates extends \Utopia\Query\Builder\Feature\MongoDB\FieldUpdates
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\MongoDB\PipelineStages::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureMongoDBPipelineStages extends \Utopia\Query\Builder\Feature\MongoDB\PipelineStages
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\NegatedFullTextSearch::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureNegatedFullTextSearch extends \Utopia\Query\Builder\Feature\NegatedFullTextSearch
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\PostgreSQL\AggregateFilter::class)) {
    interface ExtendsUtopiaQueryBuilderFeaturePostgreSQLAggregateFilter extends \Utopia\Query\Builder\Feature\PostgreSQL\AggregateFilter
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\PostgreSQL\DistinctOn::class)) {
    interface ExtendsUtopiaQueryBuilderFeaturePostgreSQLDistinctOn extends \Utopia\Query\Builder\Feature\PostgreSQL\DistinctOn
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\PostgreSQL\LockingOf::class)) {
    interface ExtendsUtopiaQueryBuilderFeaturePostgreSQLLockingOf extends \Utopia\Query\Builder\Feature\PostgreSQL\LockingOf
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\PostgreSQL\Merge::class)) {
    interface ExtendsUtopiaQueryBuilderFeaturePostgreSQLMerge extends \Utopia\Query\Builder\Feature\PostgreSQL\Merge
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\PostgreSQL\OrderedSetAggregates::class)) {
    interface ExtendsUtopiaQueryBuilderFeaturePostgreSQLOrderedSetAggregates extends \Utopia\Query\Builder\Feature\PostgreSQL\OrderedSetAggregates
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\PostgreSQL\Returning::class)) {
    interface ExtendsUtopiaQueryBuilderFeaturePostgreSQLReturning extends \Utopia\Query\Builder\Feature\PostgreSQL\Returning
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\PostgreSQL\VectorSearch::class)) {
    interface ExtendsUtopiaQueryBuilderFeaturePostgreSQLVectorSearch extends \Utopia\Query\Builder\Feature\PostgreSQL\VectorSearch
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\RawSql::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureRawSql extends \Utopia\Query\Builder\Feature\RawSql
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Rollup::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureRollup extends \Utopia\Query\Builder\Feature\Rollup
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Selects::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureSelects extends \Utopia\Query\Builder\Feature\Selects
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Sequences::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureSequences extends \Utopia\Query\Builder\Feature\Sequences
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Spatial::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureSpatial extends \Utopia\Query\Builder\Feature\Spatial
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\StatisticalAggregates::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureStatisticalAggregates extends \Utopia\Query\Builder\Feature\StatisticalAggregates
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\StringAggregates::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureStringAggregates extends \Utopia\Query\Builder\Feature\StringAggregates
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\TableSampling::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureTableSampling extends \Utopia\Query\Builder\Feature\TableSampling
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Totals::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureTotals extends \Utopia\Query\Builder\Feature\Totals
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Transactions::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureTransactions extends \Utopia\Query\Builder\Feature\Transactions
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Unions::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureUnions extends \Utopia\Query\Builder\Feature\Unions
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Updates::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureUpdates extends \Utopia\Query\Builder\Feature\Updates
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Upsert::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureUpsert extends \Utopia\Query\Builder\Feature\Upsert
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\UpsertSelect::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureUpsertSelect extends \Utopia\Query\Builder\Feature\UpsertSelect
    {
    }
}
if (interface_exists(\Utopia\Query\Builder\Feature\Windows::class)) {
    interface ExtendsUtopiaQueryBuilderFeatureWindows extends \Utopia\Query\Builder\Feature\Windows
    {
    }
}
if (interface_exists(\Utopia\Query\Compiler::class)) {
    interface ExtendsUtopiaQueryCompiler extends \Utopia\Query\Compiler
    {
    }
}
if (class_exists(\Utopia\Query\Builder::class)) {
    abstract class ExtendsUtopiaQueryBuilder extends \Utopia\Query\Builder
    {
    }
}
if (class_exists(\Utopia\Query\Builder\SQL::class)) {
    abstract class ExtendsUtopiaQueryBuilderSQL extends \Utopia\Query\Builder\SQL
    {
    }
}
if (class_exists(\Utopia\Query\Builder\MySQL::class)) {
    class ExtendsUtopiaQueryBuilderMySQL extends \Utopia\Query\Builder\MySQL
    {
    }
}
if (class_exists(\Utopia\Query\Builder\MariaDB::class)) {
    class ExtendsUtopiaQueryBuilderMariaDB extends \Utopia\Query\Builder\MariaDB
    {
    }
}
if (class_exists(\Utopia\Query\Builder\PostgreSQL::class)) {
    class ExtendsUtopiaQueryBuilderPostgreSQL extends \Utopia\Query\Builder\PostgreSQL
    {
    }
}
if (class_exists(\Utopia\Query\Builder\SQLite::class)) {
    class ExtendsUtopiaQueryBuilderSQLite extends \Utopia\Query\Builder\SQLite
    {
    }
}
if (class_exists(\Utopia\Query\Builder\ClickHouse::class)) {
    class ExtendsUtopiaQueryBuilderClickHouse extends \Utopia\Query\Builder\ClickHouse
    {
    }
}
if (class_exists(\Utopia\Query\Builder\MongoDB::class)) {
    class ExtendsUtopiaQueryBuilderMongoDB extends \Utopia\Query\Builder\MongoDB
    {
    }
}

if (class_exists(\Utopia\Detector\Detection::class)) {
    abstract class ExtendsUtopiaDetectorDetection extends \Utopia\Detector\Detection
    {
    }
}
if (class_exists(\Utopia\Detector\Detector::class)) {
    abstract class ExtendsUtopiaDetectorDetector extends \Utopia\Detector\Detector
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework::class)) {
    abstract class ExtendsUtopiaDetectorDetectionFramework extends \Utopia\Detector\Detection\Framework
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\JS::class)) {
    abstract class ExtendsUtopiaDetectorDetectionFrameworkJS extends \Utopia\Detector\Detection\Framework\JS
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Packager::class)) {
    abstract class ExtendsUtopiaDetectorDetectionPackager extends \Utopia\Detector\Detection\Packager
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Rendering::class)) {
    abstract class ExtendsUtopiaDetectorDetectionRendering extends \Utopia\Detector\Detection\Rendering
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime::class)) {
    abstract class ExtendsUtopiaDetectorDetectionRuntime extends \Utopia\Detector\Detection\Runtime
    {
    }
}
if (class_exists(\Utopia\Detector\Detector\Strategy::class)) {
    class ExtendsUtopiaDetectorDetectorStrategy extends \Utopia\Detector\Detector\Strategy
    {
    }
}
if (class_exists(\Utopia\Detector\Detector\Framework::class)) {
    class ExtendsUtopiaDetectorDetectorFramework extends \Utopia\Detector\Detector\Framework
    {
    }
}
if (class_exists(\Utopia\Detector\Detector\Packager::class)) {
    class ExtendsUtopiaDetectorDetectorPackager extends \Utopia\Detector\Detector\Packager
    {
    }
}
if (class_exists(\Utopia\Detector\Detector\Rendering::class)) {
    class ExtendsUtopiaDetectorDetectorRendering extends \Utopia\Detector\Detector\Rendering
    {
    }
}
if (class_exists(\Utopia\Detector\Detector\Runtime::class)) {
    class ExtendsUtopiaDetectorDetectorRuntime extends \Utopia\Detector\Detector\Runtime
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\Analog::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkAnalog extends \Utopia\Detector\Detection\Framework\Analog
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\Angular::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkAngular extends \Utopia\Detector\Detection\Framework\Angular
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\Astro::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkAstro extends \Utopia\Detector\Detection\Framework\Astro
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\Flutter::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkFlutter extends \Utopia\Detector\Detection\Framework\Flutter
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\Jaspr::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkJaspr extends \Utopia\Detector\Detection\Framework\Jaspr
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\Lynx::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkLynx extends \Utopia\Detector\Detection\Framework\Lynx
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\NextJs::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkNextJs extends \Utopia\Detector\Detection\Framework\NextJs
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\Nuxt::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkNuxt extends \Utopia\Detector\Detection\Framework\Nuxt
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\React::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkReact extends \Utopia\Detector\Detection\Framework\React
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\ReactNative::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkReactNative extends \Utopia\Detector\Detection\Framework\ReactNative
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\Remix::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkRemix extends \Utopia\Detector\Detection\Framework\Remix
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\Svelte::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkSvelte extends \Utopia\Detector\Detection\Framework\Svelte
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\SvelteKit::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkSvelteKit extends \Utopia\Detector\Detection\Framework\SvelteKit
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\TanStackStart::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkTanStackStart extends \Utopia\Detector\Detection\Framework\TanStackStart
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Framework\Vue::class)) {
    class ExtendsUtopiaDetectorDetectionFrameworkVue extends \Utopia\Detector\Detection\Framework\Vue
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Packager\NPM::class)) {
    class ExtendsUtopiaDetectorDetectionPackagerNPM extends \Utopia\Detector\Detection\Packager\NPM
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Packager\PNPM::class)) {
    class ExtendsUtopiaDetectorDetectionPackagerPNPM extends \Utopia\Detector\Detection\Packager\PNPM
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Packager\Yarn::class)) {
    class ExtendsUtopiaDetectorDetectionPackagerYarn extends \Utopia\Detector\Detection\Packager\Yarn
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Rendering\SSR::class)) {
    class ExtendsUtopiaDetectorDetectionRenderingSSR extends \Utopia\Detector\Detection\Rendering\SSR
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Rendering\XStatic::class)) {
    class ExtendsUtopiaDetectorDetectionRenderingXStatic extends \Utopia\Detector\Detection\Rendering\XStatic
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\Bun::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimeBun extends \Utopia\Detector\Detection\Runtime\Bun
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\CPP::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimeCPP extends \Utopia\Detector\Detection\Runtime\CPP
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\Dart::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimeDart extends \Utopia\Detector\Detection\Runtime\Dart
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\Deno::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimeDeno extends \Utopia\Detector\Detection\Runtime\Deno
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\Dotnet::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimeDotnet extends \Utopia\Detector\Detection\Runtime\Dotnet
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\Java::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimeJava extends \Utopia\Detector\Detection\Runtime\Java
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\Node::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimeNode extends \Utopia\Detector\Detection\Runtime\Node
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\PHP::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimePHP extends \Utopia\Detector\Detection\Runtime\PHP
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\Python::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimePython extends \Utopia\Detector\Detection\Runtime\Python
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\Ruby::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimeRuby extends \Utopia\Detector\Detection\Runtime\Ruby
    {
    }
}
if (class_exists(\Utopia\Detector\Detection\Runtime\Swift::class)) {
    class ExtendsUtopiaDetectorDetectionRuntimeSwift extends \Utopia\Detector\Detection\Runtime\Swift
    {
    }
}

$extended = [];
foreach ((new ReflectionExtension('utopia'))->getClassNames() as $name) {
    $class = new ReflectionClass($name);
    // An enum the extension registers is not marked final, and still cannot be extended.
    if (!$class->isFinal() && !$class->isEnum() && $class->getName() === $name) {
        $extended[$name] = 'Extends' . str_replace('\\', '', $name);
    }
}
foreach ($extended as $name => $child) {
    if (!class_exists($child, false) && !interface_exists($child, false)) {
        fwrite(STDERR, $name . ' has no subclass here; add one' . PHP_EOL);
        exit(1);
    }
}

/** The inherited defaults and constants of $child, used as PHP code uses them. */
function use_inherited(string $child): array
{
    $class = new ReflectionClass($child);
    $seen = [];
    foreach ($class->getDefaultProperties() as $name => $value) {
        if (is_array($value)) {
            $value[] = 'appended';
        }
        $seen[$name] = get_debug_type($value);
    }
    foreach ($class->getReflectionConstants() as $constant) {
        if ($constant->isPrivate()) {
            continue;
        }
        $value = constant($child . '::' . $constant->getName());
        if (is_array($value)) {
            $value[] = 'appended';
            sort($value);
        }
        $seen[$constant->getName()] = get_debug_type($value);
    }

    return $seen;
}

$seen = 0;
foreach ($extended as $child) {
    $seen += count(use_inherited($child));
}

echo 'ok ', count($extended), ' classes, ', $seen, ' inherited members', PHP_EOL;
