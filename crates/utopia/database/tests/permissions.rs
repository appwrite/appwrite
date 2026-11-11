//! PHP: `tests/unit/Validator/PermissionsTest.php` (utopia-php/database
//! 8.1.3).

mod common;

use common::{s, val};
use utopia_database::validator::permissions::{PERMISSIONS, Permissions};
use utopia_database::validator::roles::ROLES;
use utopia_database::value::{Array, Value};
use utopia_database::{Document, Permission, Role, id};

fn permissions() -> Permissions {
    Permissions::new(0, &PERMISSIONS)
}

fn list(items: &[String]) -> Value {
    Value::Array(Array::from_list(items.iter().map(|p| s(p)).collect()))
}

/// `$document['$permissions'] = $permissions; $document->getPermissions()`.
fn through_document(permissions: &[String]) -> Value {
    let mut document =
        Document::from_pairs([("$id", s(&id::unique().unwrap())), ("$collection", s(&id::unique().unwrap()))]).unwrap();
    document.as_array_mut().set("$permissions".into(), list(permissions));
    let normalized = document.permissions().unwrap();
    Value::Array(Array::from_list(normalized.iter().map(|p| s(p)).collect()))
}

fn any() -> Role {
    Role::any()
}

fn users(status: &str) -> Role {
    Role::users(status)
}

fn user(identifier: &str) -> Role {
    Role::user(id::custom(identifier), "")
}

fn team(identifier: &str, dimension: &str) -> Role {
    Role::team(id::custom(identifier), dimension)
}

#[test]
fn single_method_single_value() {
    let object = permissions();
    let methods: [fn(&Role) -> String; 4] =
        [Permission::create, Permission::read, Permission::update, Permission::delete];
    let dimensions = ["edit", "viewer", "edit", "edit"];
    for (method, dimension) in methods.into_iter().zip(dimensions) {
        for role in [
            any(),
            users(""),
            user("123abc"),
            team("123abc", ""),
            team("123abc", dimension),
            Role::guests(),
            users("verified"),
        ] {
            assert!(object.is_valid(&through_document(&[method(&role)])), "{}", method(&role));
        }
    }
    assert!(object.is_valid(&through_document(&[Permission::delete(&Role::label("vip"))])));
}

#[test]
fn multiple_method_single_value() {
    let object = permissions();
    for role in [
        any(),
        users(""),
        user("123abc"),
        team("123abc", ""),
        team("123abc", "viewer"),
        Role::guests(),
        users("verified"),
    ] {
        let set = [Permission::read(&role), Permission::create(&role), Permission::update(&role)];
        assert!(object.is_valid(&through_document(&set)));
    }
}

#[test]
fn multiple_method_multiple_values() {
    let object = permissions();
    assert!(object.is_valid(&through_document(&[
        Permission::read(&users("")),
        Permission::create(&user("123abc")),
        Permission::create(&team("123abc", "")),
        Permission::update(&user("123abc")),
        Permission::update(&team("123abc", "")),
        Permission::delete(&user("123abc")),
    ])));
    assert!(object.is_valid(&through_document(&[
        Permission::read(&user("123abc")),
        Permission::read(&team("123abc", "")),
        Permission::create(&user("123abc")),
        Permission::create(&team("123abc", "")),
        Permission::update(&user("123abc")),
        Permission::update(&team("123abc", "")),
        Permission::delete(&user("123abc")),
    ])));
    assert!(object.is_valid(&through_document(&[
        Permission::read(&any()),
        Permission::create(&Role::guests()),
        Permission::update(&team("123abc", "edit")),
        Permission::delete(&team("123abc", "edit")),
    ])));
}

const IDENTIFIER: &str = "identifier value is invalid: Parameter must contain at most 36 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char";
const DIMENSION: &str = "dimension value is invalid: Parameter must contain at most 81 chars. Valid chars are a-z, A-Z, 0-9, period, hyphen, and underscore. Can't start with a special char";

#[track_caller]
fn refused(object: &Permissions, value: Value, message: &str) {
    assert!(!object.is_valid(&value));
    assert_eq!(object.description(), message);
}

#[test]
fn invalid_permissions() {
    let object = permissions();
    let array_of_strings = "Permissions must be an array of strings.";
    refused(&object, s(&Permission::create(&any())), array_of_strings);
    refused(&object, Value::Bool(false), array_of_strings);
    refused(&object, Value::Float(1.5), array_of_strings);

    // Permissions must be strings.
    let of_type_string = "Every permission must be of type string.";
    refused(&object, val("[0, 1.5]"), of_type_string);
    refused(&object, val("[false, []]"), of_type_string);
    refused(&object, val(r#"[["a"]]"#), of_type_string);

    // No wildcard.
    refused(&object, val(r#"["*"]"#), "Wildcard permission \"*\" has been replaced. Use \"any\" instead.");

    // No "role:" prefix.
    let prefix =
        "Permissions using the \"role:\" prefix have been replaced. Use \"users\", \"guests\", or \"any\" instead.";
    for p in ["read(\"role:all\")", "create(\"role:guest\")", "update(\"role:member\")"] {
        refused(&object, list(&[p.to_owned()]), prefix);
    }

    // A known permission.
    for p in ["user1234", "user::1234", "user:123:4"] {
        refused(
            &object,
            list(&[p.to_owned()]),
            &format!("Permission \"{p}\" is not allowed. Must be one of: create, read, update, delete, write."),
        );
    }

    // A role with a value.
    for role in ["member", "user", "team"] {
        refused(&object, list(&[format!("read(\"{role}:\")")]), &format!("Role \"{role}\" must have an ID value."));
    }

    // A known role.
    let roles = ROLES.join(", ");
    for (p, role) in [("read(\"anyy\")", "anyy"), ("read(\"gguest\")", "gguest"), ("read(\"memer:123abc\")", "memer")] {
        refused(&object, list(&[p.to_owned()]), &format!("Role \"{role}\" is not allowed. Must be one of: {roles}."));
    }

    // Identifiers are keys: no leading special chars.
    refused(&object, list(&[Permission::read(&Role::user("_1234", ""))]), &format!("Role \"user\" {IDENTIFIER}"));
    refused(&object, list(&[Permission::read(&Role::team("-1234", ""))]), &format!("Role \"team\" {IDENTIFIER}"));
    refused(&object, list(&[Permission::read(&Role::member(".1234"))]), &format!("Role \"member\" {IDENTIFIER}"));

    // No unsupported special characters.
    for identifier in ["12$4", "12&4", "ab(124"] {
        refused(
            &object,
            list(&[Permission::read(&Role::user(identifier, ""))]),
            &format!("Role \"user\" {IDENTIFIER}"),
        );
    }

    // At most 36 chars.
    assert!(object.is_valid(&list(&[Permission::read(&user(&"a".repeat(36)))])));
    refused(&object, list(&[Permission::read(&user(&"a".repeat(256)))]), &format!("Role \"user\" {IDENTIFIER}"));

    // A role of member, team, user, ...
    for (p, role) in [
        ("update(\"memmber:1234\")", "memmber"),
        ("update(\"tteam:1234\")", "tteam"),
        ("update(\"userr:1234\")", "userr"),
    ] {
        refused(&object, list(&[p.to_owned()]), &format!("Role \"{role}\" is not allowed. Must be one of: {roles}."));
    }

    // Team permissions.
    refused(&object, list(&[Permission::read(&team("_abcd", ""))]), &format!("Role \"team\" {IDENTIFIER}"));
    refused(&object, list(&[Permission::read(&team("abcd/", ""))]), "Dimension must not be empty");
    refused(&object, list(&[Permission::read(&team("", "abcd"))]), "Role \"team\" must have an ID value.");
    refused(&object, list(&[Permission::read(&team("abcd", "/efgh"))]), "Only one dimension can be provided");
    refused(&object, list(&[Permission::read(&team("abcd", "e/fgh"))]), "Only one dimension can be provided");
    refused(&object, list(&[Permission::read(&team("ab&cd3", "efgh"))]), &format!("Role \"team\" {IDENTIFIER}"));
    refused(&object, list(&[Permission::read(&team(&"a".repeat(37), "efgh"))]), &format!("Role \"team\" {IDENTIFIER}"));
    refused(&object, list(&[Permission::read(&team("abcd", "ef*gh"))]), &format!("Role \"team\" {DIMENSION}"));
    refused(&object, list(&[Permission::read(&team("abcd", &"a".repeat(82)))]), &format!("Role \"team\" {DIMENSION}"));

    // At most `length` permissions.
    let object = Permissions::new(100, &PERMISSIONS);
    let mut many = vec![Permission::read(&any()); 100];
    assert!(object.is_valid(&list(&many)));
    many.push(Permission::read(&any()));
    refused(&object, list(&many), "You can only provide up to 100 permissions.");
}

/// `getPermissions()` returns a list without duplicates.
#[test]
fn duplicate_methods() {
    let validator = permissions();
    let user_id = id::unique().unwrap();
    let u = Role::user(user_id.clone(), "");
    let document = Document::new(
        match val(r#"{"$id": "a", "$collection": "b", "title": "This is a test.", "list": ["one"],
            "children": [{"$id": "x", "name": "x"}, {"$id": "y", "name": "y"}, {"$id": "z", "name": "z"}]}"#)
        {
            Value::Array(mut a) => {
                a.set(
                    "$permissions".into(),
                    list(&[
                        Permission::read(&any()),
                        Permission::read(&u),
                        Permission::read(&u),
                        Permission::write(&u),
                        Permission::update(&u),
                        Permission::delete(&u),
                    ]),
                );
                a
            }
            _ => unreachable!(),
        },
    )
    .unwrap();
    let permissions = document.permissions().unwrap();
    assert!(validator.is_valid(&list(&permissions)));
    assert_eq!(permissions.len(), 5);
    assert_eq!(
        permissions,
        [
            "read(\"any\")".to_owned(),
            format!("read(\"user:{user_id}\")"),
            format!("write(\"user:{user_id}\")"),
            format!("update(\"user:{user_id}\")"),
            format!("delete(\"user:{user_id}\")"),
        ]
    );
}

#[test]
fn custom_type_rejected_as_in_7_4() {
    let object = permissions();
    refused(
        &object,
        list(&["execute(\"any\")".to_owned()]),
        "Permission \"execute(\"any\")\" is not allowed. Must be one of: create, read, update, delete, write.",
    );
}
