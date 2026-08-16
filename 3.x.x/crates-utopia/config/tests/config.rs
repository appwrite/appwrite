use serde_json::{json, Map, Value};
use std::path::PathBuf;
use tempfile::NamedTempFile;
use utopia_config::{
    resolve_value, Config, DotenvParser, EnvironmentSource, FieldSpec, FileSource, JsonParser,
    KeySpec, LoadError, NoneParser, ParseError, Parser, PhpParser, ResolvedValue, SourceContent,
    VariableSource, YamlParser,
};
use utopia_validators::{Boolean, Nullable, Text};

fn resources_dir() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/resources")
}

#[test]
fn file_source_json() {
    let source = FileSource::new(resources_dir().join("config.json"));
    let data = Config::load_map(&source, &JsonParser).unwrap();
    assert_eq!(data["jsonKey"], json!("customValue"));
}

#[test]
fn file_source_missing_file() {
    let source = FileSource::new(resources_dir().join("non-existing.json"));
    let err = Config::load_map(&source, &JsonParser).unwrap_err();
    assert_eq!(err, LoadError::NullContents);
}

#[test]
fn variable_source_with_none_parser() {
    let mut map = Map::new();
    map.insert("phpKey".into(), json!("aValue"));
    map.insert("ENV_KEY".into(), json!("aValue"));
    let source = VariableSource::from_map(map);
    let data = Config::load_map(&source, &NoneParser).unwrap();
    assert_eq!(data["phpKey"], json!("aValue"));
    assert_eq!(data["ENV_KEY"], json!("aValue"));
}

#[test]
fn variable_source_dotenv() {
    let source = VariableSource::from_text("ENV_KEY=aValue");
    let data = Config::load_map(&source, &DotenvParser).unwrap();
    assert_eq!(data["ENV_KEY"], json!("aValue"));
}

#[test]
fn environment_source_with_prefix() {
    let source = EnvironmentSource::with_prefix("PATH");
    let data = Config::load_map(&source, &NoneParser).unwrap();
    for key in data.keys() {
        assert!(key.starts_with("PATH"));
    }
    if cfg!(unix) {
        assert!(data.contains_key("PATH"));
    }
}

#[test]
fn adapter_json() {
    let source = FileSource::new(resources_dir().join("config.json"));
    let data = Config::load_map(&source, &JsonParser).unwrap();
    assert_eq!(data["jsonKey"], json!("customValue"));
}

#[test]
fn adapter_yaml() {
    let source = FileSource::new(resources_dir().join("config.yaml"));
    let data = Config::load_map(&source, &YamlParser).unwrap();
    assert_eq!(data["yaml-key"], json!("customValue"));
}

#[test]
fn adapter_yml() {
    let source = FileSource::new(resources_dir().join("config.yml"));
    let data = Config::load_map(&source, &YamlParser).unwrap();
    assert_eq!(data["yml_key"], json!("customValue"));
}

#[test]
fn adapter_dotenv() {
    let source = FileSource::new(resources_dir().join("config.env"));
    let data = Config::load_map(&source, &DotenvParser).unwrap();
    assert_eq!(data["ENV_KEY"], json!("customValue"));
}

#[test]
fn json_parser_basic_types() {
    let json = r#"{
      "string": "hello world",
      "unicode_string": "ä你こحب🌍",
      "integer": 42,
      "float": 2.5,
      "negative": -50,
      "boolean_true": true,
      "boolean_false": false,
      "null_value": null
    }"#;
    let data = JsonParser
        .parse(&SourceContent::Text(json.into()), &[])
        .unwrap();
    assert_eq!(data["string"], json!("hello world"));
    assert_eq!(data["unicode_string"], json!("ä你こحب🌍"));
    assert_eq!(data["integer"], json!(42));
    assert_eq!(data["float"], json!(2.5));
    assert_eq!(data["negative"], json!(-50));
    assert_eq!(data["boolean_true"], json!(true));
    assert_eq!(data["boolean_false"], json!(false));
    assert_eq!(data["null_value"], Value::Null);
}

#[test]
fn json_parser_rejects_scalar_and_list() {
    for scalar in ["\"foo\"", "123", "true", "null"] {
        let err = JsonParser
            .parse(&SourceContent::Text(scalar.into()), &[])
            .unwrap_err();
        assert_eq!(err, ParseError::NotJsonObject, "scalar: {scalar}");
    }

    let err = JsonParser
        .parse(&SourceContent::Text(r#"["secret", "other"]"#.into()), &[])
        .unwrap_err();
    assert_eq!(err, ParseError::NotJsonObject);
}

#[test]
fn json_parser_edge_cases() {
    assert!(JsonParser
        .parse(&SourceContent::Text(String::new()), &[])
        .unwrap()
        .is_empty());
    assert!(JsonParser
        .parse(&SourceContent::Text("{}".into()), &[])
        .unwrap()
        .is_empty());
    assert!(JsonParser
        .parse(&SourceContent::Text("[]".into()), &[])
        .unwrap()
        .is_empty());
}

#[test]
fn yaml_parser_basic_types() {
    let yaml = r"
string: hello world
unicode_string: ä你こحب🌍
integer: 42
float: 2.5
negative: -50
boolean_true: true
boolean_false: false
null_value: null
";
    let data = YamlParser
        .parse(&SourceContent::Text(yaml.into()), &[])
        .unwrap();
    assert_eq!(data["string"], json!("hello world"));
    assert_eq!(data["integer"], json!(42));
    assert_eq!(data["float"], json!(2.5));
    assert_eq!(data["boolean_true"], json!(true));
    assert_eq!(data["null_value"], Value::Null);
}

#[test]
fn dotenv_basic_types_and_comments() {
    let dotenv = r"
STRING=hello world
UNICODE_STRING=ä你こحب🌍
INTEGER=42
NULL_VALUE=null
HOST=127.0.0.1
PORT=3306 # A comment
# Another comment

PASSWORD=secret
";
    let data = DotenvParser
        .parse(&SourceContent::Text(dotenv.into()), &[])
        .unwrap();
    assert_eq!(data["STRING"], json!("hello world"));
    assert_eq!(data["INTEGER"], json!("42"));
    assert_eq!(data["NULL_VALUE"], Value::Null);
    assert_eq!(data["HOST"], json!("127.0.0.1"));
    assert_eq!(data["PORT"], json!("3306"));
    assert_eq!(data["PASSWORD"], json!("secret"));
}

#[test]
fn dotenv_quoted_values_and_escapes() {
    let dotenv = concat!(
        "PASSWORD=\"abc#123\"\n",
        "TOKEN='x#y#z'\n",
        "URL=\"https://example.com/path#fragment\"\n",
        "PLAIN=value # trailing comment\n",
        "KEY=\"abc\\\"#def\"\n",
        "PATH=\"C:\\tmp\"\n",
        "ESCAPED=\"a\\\\b\"\n",
    );
    let data = DotenvParser
        .parse(&SourceContent::Text(dotenv.into()), &[])
        .unwrap();
    assert_eq!(data["PASSWORD"], json!("abc#123"));
    assert_eq!(data["TOKEN"], json!("x#y#z"));
    assert_eq!(data["URL"], json!("https://example.com/path#fragment"));
    assert_eq!(data["PLAIN"], json!("value"));
    assert_eq!(data["KEY"], json!("abc\"#def"));
    assert_eq!(data["PATH"], json!(r"C:\tmp"));
    assert_eq!(data["ESCAPED"], json!("a\\b"));
}

#[test]
fn dotenv_invalid_lines() {
    assert_eq!(
        DotenvParser
            .parse(&SourceContent::Text("=b".into()), &[])
            .unwrap_err(),
        ParseError::InvalidDotenv
    );
    assert_eq!(
        DotenvParser
            .parse(
                &SourceContent::Text("HOST=127.0.0.1\nDATABASE_PASSWORD".into()),
                &[]
            )
            .unwrap_err(),
        ParseError::InvalidDotenv
    );
    assert_eq!(
        DotenvParser
            .parse(&SourceContent::Text(r#"KEY="prod"oops"#.into()), &[])
            .unwrap_err(),
        ParseError::InvalidDotenv
    );
    assert_eq!(
        DotenvParser
            .parse(&SourceContent::Text(r#"KEY="abc"#.into()), &[])
            .unwrap_err(),
        ParseError::InvalidDotenv
    );
}

#[test]
fn dotenv_bool_coercion_with_key_specs() {
    let dotenv = r"
KEY1=1
KEY2=on
KEY8=0
KEY12=false
KEY15=11
KEY25=1
KEY27=null
";
    let keys = vec![
        KeySpec::new("KEY1", Boolean::new()).required(true),
        KeySpec::new("KEY2", Boolean::new()).required(true),
        KeySpec::new("KEY8", Boolean::new()).required(true),
        KeySpec::new("KEY12", Boolean::new()).required(true),
        KeySpec::new("KEY15", Boolean::new()).required(true),
        KeySpec::new("KEY25", Text::new(1024)).required(true),
        KeySpec::new("KEY27", Text::new(1024)).required(true),
    ];
    let data = DotenvParser
        .parse(&SourceContent::Text(dotenv.into()), &keys)
        .unwrap();
    assert_eq!(data["KEY1"], json!(true));
    assert_eq!(data["KEY2"], json!(true));
    assert_eq!(data["KEY8"], json!(false));
    assert_eq!(data["KEY12"], json!(false));
    assert_eq!(data["KEY15"], json!("11"));
    assert_eq!(data["KEY25"], json!("1"));
    assert_eq!(data["KEY27"], Value::Null);
}

#[test]
fn none_parser_requires_map() {
    assert_eq!(
        NoneParser
            .parse(&SourceContent::Text("hello".into()), &[])
            .unwrap_err(),
        ParseError::ContentsNotMap
    );
    let map = Map::new();
    assert_eq!(
        NoneParser
            .parse(&SourceContent::Map(map.clone()), &[])
            .unwrap(),
        map
    );
}

#[test]
fn nested_values_with_dot_notation() {
    let jsons = [
        r#"{"db.host": "docker.internal", "db.config.tls": true}"#,
        r#"{"db": {"host": "docker.internal", "config": {"tls": true}}}"#,
        r#"{"db": {"host": "docker.internal", "config.tls": true}}"#,
        r#"{"db.host": "docker.internal", "db": {"config": {"tls": true}}}"#,
    ];

    for json_text in jsons {
        let data = Config::load_map(&VariableSource::from_text(json_text), &JsonParser).unwrap();
        assert_eq!(
            resolve_value(&data, "db.host"),
            ResolvedValue::Found(json!("docker.internal"))
        );
        assert_eq!(
            resolve_value(&data, "db.config.tls"),
            ResolvedValue::Found(json!(true))
        );
    }
}

#[test]
fn load_with_required_and_validation() {
    let keys = vec![KeySpec::new("key", Text::new(8)).required(true)];
    let err = Config::load_with(
        &VariableSource::from_text("SOME_OTHER_KEY=value"),
        &DotenvParser,
        &keys,
    )
    .unwrap_err();
    assert_eq!(err, LoadError::MissingRequired("key".into()));

    let err = Config::load_with(
        &VariableSource::from_text("key=too_long_value_that_will_not_get_accepted"),
        &DotenvParser,
        &keys,
    )
    .unwrap_err();
    assert!(matches!(err, LoadError::InvalidValue { .. }));
}

#[test]
fn load_with_nullable_required_present_null() {
    let keys = vec![KeySpec::new("name", Nullable::new(Text::new(1024))).required(true)];
    let loaded = Config::load_with(
        &VariableSource::from_text(r#"{"name": null}"#),
        &JsonParser,
        &keys,
    )
    .unwrap();
    assert_eq!(loaded["name"], Value::Null);

    let keys = vec![KeySpec::new("db.name", Nullable::new(Text::new(1024))).required(true)];
    let loaded = Config::load_with(
        &VariableSource::from_text(r#"{"db": {"name": null}}"#),
        &JsonParser,
        &keys,
    )
    .unwrap();
    assert_eq!(loaded["db.name"], Value::Null);
}

#[test]
fn load_with_optional_missing_key_is_omitted() {
    let keys = vec![KeySpec::new("name", Text::new(1024)).required(false)];
    let loaded = Config::load_with(&VariableSource::from_text("{}"), &JsonParser, &keys).unwrap();
    assert!(!loaded.contains_key("name"));
}

#[test]
fn file_source_round_trip_via_tempfile() {
    let file = NamedTempFile::new().unwrap();
    std::fs::write(file.path(), r#"{"tempKey":"tempValue"}"#).unwrap();
    let source = FileSource::new(file.path());
    let data = Config::load_map(&source, &JsonParser).unwrap();
    assert_eq!(data["tempKey"], json!("tempValue"));
}

fn test_config_fields() -> Vec<FieldSpec> {
    vec![
        FieldSpec::Key(KeySpec::new("phpKey", Text::new(1024)).required(false)),
        FieldSpec::Key(KeySpec::new("jsonKey", Text::new(1024)).required(false)),
        FieldSpec::Key(KeySpec::new("yaml-key", Text::new(1024)).required(false)),
        FieldSpec::Key(KeySpec::new("yml_key", Text::new(1024)).required(false)),
        FieldSpec::Key(KeySpec::new("ENV_KEY", Text::new(1024)).required(false)),
    ]
}

fn test_group_fields() -> Vec<FieldSpec> {
    vec![
        FieldSpec::nested_required("config1", test_config_fields()),
        FieldSpec::nested_required("config2", test_config_fields()),
        FieldSpec::Key(KeySpec::new("rootKey", Text::new(1024)).required(true)),
    ]
}

#[test]
fn adapter_php() {
    let source = FileSource::new(resources_dir().join("config.php"));
    let data = Config::load_map(&source, &PhpParser).unwrap();
    assert_eq!(data["phpKey"], json!("customValue"));
}

#[test]
fn php_parser_basic_types() {
    let php = r#"<?php
        return [
            "string" => "hello world",
            "integer" => 42,
            "negative" => -50,
            "boolean_true" => true,
            "null_value" => null,
        ];
    "#;
    let data = PhpParser
        .parse(&SourceContent::Text(php.into()), &[])
        .unwrap();
    assert_eq!(data["string"], json!("hello world"));
    assert_eq!(data["integer"], json!(42));
    assert_eq!(data["negative"], json!(-50));
    assert_eq!(data["boolean_true"], json!(true));
    assert_eq!(data["null_value"], Value::Null);
}

#[test]
fn php_parser_rejects_invalid_input() {
    assert!(matches!(
        PhpParser.parse(&SourceContent::Text("return [];".into()), &[]),
        Err(ParseError::InvalidPhp(_))
    ));
}

#[test]
fn subconfigs_via_load_struct() {
    let mut config1 = Map::new();
    config1.insert("ENV_KEY".into(), json!("envValue"));
    let mut config2 = Map::new();
    config2.insert("yml_key".into(), json!("ymlValue"));

    let mut root = Map::new();
    root.insert("config1".into(), Value::Object(config1));
    root.insert("config2".into(), Value::Object(config2));
    root.insert("rootKey".into(), json!("rootValue"));

    let loaded = Config::load_struct(
        &VariableSource::from_map(root),
        &NoneParser,
        &test_group_fields(),
    )
    .unwrap();

    assert_eq!(loaded["rootKey"], json!("rootValue"));
    assert_eq!(loaded["config1"]["ENV_KEY"], json!("envValue"));
    assert_eq!(loaded["config2"]["yml_key"], json!("ymlValue"));
}
