use appwrite_exception::Exception;

#[test]
fn new_looks_up_default_code_and_message() {
    let err = Exception::new(Exception::USER_NOT_FOUND);
    assert_eq!(err.type_(), Exception::USER_NOT_FOUND);
    assert_eq!(err.code(), 404);
    assert_eq!(
        err.message(),
        "User with the requested ID could not be found."
    );
}

#[test]
fn unknown_type_falls_back_to_500() {
    let err = Exception::new("totally_unknown_type");
    assert_eq!(err.code(), 500);
    assert!(err.message().contains("totally_unknown_type"));
}

#[test]
fn with_message_overrides_default() {
    let err = Exception::with_message(Exception::USER_ALREADY_EXISTS, "custom message");
    assert_eq!(err.code(), 409);
    assert_eq!(err.message(), "custom message");
}

#[test]
fn with_code_overrides_default() {
    let err = Exception::new(Exception::GENERAL_UNKNOWN).with_code(503);
    assert_eq!(err.code(), 503);
}

#[test]
fn to_json_matches_error_model_shape() {
    let err = Exception::new(Exception::GENERAL_ARGUMENT_INVALID).with_version("1.2.3");
    let json = err.to_json();
    assert_eq!(json["type"], Exception::GENERAL_ARGUMENT_INVALID);
    assert_eq!(json["code"], 400);
    assert_eq!(json["version"], "1.2.3");
    assert_eq!(
        json["message"],
        "The request contains one or more invalid arguments. Please refer to the endpoint documentation."
    );
}

#[test]
fn is_publishable_defaults_to_code_threshold() {
    assert!(Exception::new(Exception::GENERAL_SERVER_ERROR).is_publishable());
    assert!(!Exception::new(Exception::USER_NOT_FOUND).is_publishable());
}

#[test]
fn is_publishable_respects_explicit_override() {
    // 501 would default to publishable (>= 500) but the catalog marks it false.
    assert!(!Exception::new(Exception::USER_AUTH_METHOD_UNSUPPORTED).is_publishable());
}

#[test]
fn display_and_error_impl() {
    let err = Exception::new(Exception::PROJECT_NOT_FOUND);
    let rendered = err.to_string();
    assert!(rendered.contains(Exception::PROJECT_NOT_FOUND));
    assert!(rendered.contains("404"));

    let boxed: Box<dyn std::error::Error> = Box::new(err);
    assert!(!boxed.to_string().is_empty());
}

#[test]
fn all_required_users_api_error_types_resolve() {
    let types = [
        Exception::GENERAL_UNKNOWN,
        Exception::GENERAL_UNAUTHORIZED_SCOPE,
        Exception::GENERAL_ACCESS_FORBIDDEN,
        Exception::GENERAL_ARGUMENT_INVALID,
        Exception::GENERAL_ROUTE_NOT_FOUND,
        Exception::GENERAL_SERVER_ERROR,
        Exception::GENERAL_RATE_LIMIT_EXCEEDED,
        Exception::USER_NOT_FOUND,
        Exception::USER_ALREADY_EXISTS,
        Exception::USER_EMAIL_ALREADY_EXISTS,
        Exception::USER_PHONE_ALREADY_EXISTS,
        Exception::USER_BLOCKED,
        Exception::USER_PASSWORD_PERSONAL_DATA,
        Exception::USER_EMAIL_DISPOSABLE,
        Exception::USER_EMAIL_NOT_CANONICAL,
        Exception::USER_EMAIL_FREE,
        Exception::USER_EMAIL_NOT_CORPORATE,
        Exception::USER_SESSION_NOT_FOUND,
        Exception::USER_IDENTITY_NOT_FOUND,
        Exception::USER_JWT_INVALID,
        Exception::USER_COUNT_EXCEEDED,
        Exception::USER_PASSWORD_RECENTLY_USED,
        Exception::USER_UNAUTHORIZED,
        Exception::USER_MISSING_ID,
        Exception::USER_TARGET_NOT_FOUND,
        Exception::PROJECT_NOT_FOUND,
        Exception::PROJECT_UNKNOWN,
    ];

    for type_ in types {
        let err = Exception::new(type_);
        assert_eq!(err.type_(), type_, "type_ mismatch for {type_}");
        assert!(err.code() >= 400, "unexpected default code for {type_}");
        assert!(
            !err.message().is_empty(),
            "missing default message for {type_}"
        );
    }
}

#[test]
fn serde_round_trip_uses_type_field_name() {
    let err = Exception::new(Exception::USER_NOT_FOUND);
    let json = serde_json::to_value(&err).unwrap();
    assert_eq!(json["type"], Exception::USER_NOT_FOUND);
    assert!(json.get("type_").is_none());

    let round_tripped: Exception = serde_json::from_value(json).unwrap();
    assert_eq!(round_tripped, err);
}
