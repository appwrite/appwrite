<?php

/**
 * Config schemas the cases load, copied from packages/config/tests, plus
 * FuzzConfig. crates/tools/compat/src/libs/config.rs describes the same
 * schemas for Rust.
 */

namespace Tests\Compat\Config;

use Utopia\Config\Attribute\ConfigKey;
use Utopia\Config\Attribute\Key;
use Utopia\Validator\AnyOf;
use Utopia\Validator\Boolean;
use Utopia\Validator\Nullable;
use Utopia\Validator\Text;
use Utopia\Validator\WhiteList;
use Utopia\Validator\Wildcard;

class TestConfig
{
    #[Key('phpKey', new Text(1024, 0), required: false)]
    public ?string $phpKey = null;

    #[Key('jsonKey', new Text(1024, 0), required: false)]
    public ?string $jsonKey = null;

    #[Key('yaml-key', new Text(1024, 0), required: false)]
    public ?string $yamlKey = null;

    #[Key('yml_key', new Text(1024, 0), required: false)]
    public ?string $ymlKey = null;

    #[Key('ENV_KEY', new Text(1024, 0), required: false)]
    public ?string $envKey = null;
}

class TestGroupConfig
{
    #[ConfigKey]
    public TestConfig $config1;

    #[ConfigKey]
    public TestConfig $config2;

    #[Key('rootKey', new Text(1024, 0), required: true)]
    public string $rootKey;
}

class TestEnvConfig
{
    #[Key('_UTOPIA_KEY1', new Text(8, 0), required: true)]
    public string $key1;

    #[Key('_UTOPIA_KEY2', new Text(8, 0), required: true)]
    public string $key2;
}

class TestConfigRequired
{
    #[Key('key', new Text(8, 0), required: true)]
    public string $key;
}

class TestNestedValueConfig
{
    #[Key('db.host', new Text(1024), required: true)]
    public string $dbHost;

    #[Key('db.config.tls', new Boolean(), required: true)]
    public bool $tls;
}

class TestNullableConfig
{
    #[Key('name', new Nullable(new Text(1024)), required: true)]
    public ?string $name = null;
}

class TestNestedNullableConfig
{
    #[Key('db.name', new Nullable(new Text(1024)), required: true)]
    public ?string $dbName = null;
}

class TestOptionalNullableConfig
{
    #[Key('name', new Nullable(new Text(1024)), required: false)]
    public ?string $name = 'unset';
}

class TestTypeMismatchConfig
{
    #[Key('PORT', new Text(1024, 0), required: true)]
    public int $port;
}

class TestOptionalConfig
{
    #[Key('host', new Text(1024, 0), required: true)]
    public string $host;

    #[Key('mode', new Text(1024, 0), required: false)]
    public string $mode = 'auto';

    #[Key('port', new Nullable(new Text(1024, 0)), required: false)]
    public ?string $port;

    #[ConfigKey(required: false)]
    public ?TestConfig $tls;
}

class TestOptionalWithoutDefaultConfig
{
    #[Key('name', new Text(1024, 0), required: false)]
    public string $name;
}

class TestValueConvertorConfig
{
    #[Key('KEY1', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key1;

    #[Key('KEY2', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key2;

    #[Key('KEY3', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key3;

    #[Key('KEY4', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key4;

    #[Key('KEY5', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key5;

    #[Key('KEY6', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key6;

    #[Key('KEY7', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key7;

    #[Key('KEY8', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key8;

    #[Key('KEY9', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key9;

    #[Key('KEY10', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key10;

    #[Key('KEY11', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key11;

    #[Key('KEY12', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key12;

    #[Key('KEY13', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key13;

    #[Key('KEY14', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key14;

    #[Key('KEY15', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key15;

    #[Key('KEY16', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key16;

    #[Key('KEY17', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key17;

    #[Key('KEY18', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key18;

    #[Key('KEY19', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key19;

    #[Key('KEY20', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key20;

    #[Key('KEY21', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key21;

    #[Key('KEY22', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key22;

    #[Key('KEY23', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key23;

    #[Key('KEY24', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public bool $key24;

    #[Key('KEY25', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public string $key25;

    #[Key('KEY26', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public string $key26;

    #[Key('KEY27', new AnyOf([new WhiteList([true, false]), new Text(1024)]), required: true)]
    public string $key27;
}

/** Every property type, read from optional keys, for fuzzing. */
class FuzzConfig
{
    #[Key('str', new Wildcard(), required: false)]
    public string $str = 'd';

    #[Key('int', new Wildcard(), required: false)]
    public ?int $int;

    #[Key('float', new Wildcard(), required: false)]
    public float $float = 0.0;

    #[Key('bool', new Wildcard(), required: false)]
    public bool $bool = false;

    #[Key('list', new Wildcard(), required: false)]
    public array $list = [];

    #[Key('db.text', new Text(8, 0), required: false)]
    public ?string $text = null;

    #[Key('FLAG', new Nullable(new Boolean(true)), required: false)]
    public ?bool $flag = null;

    #[Key('mixed', new Wildcard(), required: false)]
    public mixed $mixed = null;

    #[ConfigKey('nested', required: false)]
    public ?TestConfig $nested = null;
}
