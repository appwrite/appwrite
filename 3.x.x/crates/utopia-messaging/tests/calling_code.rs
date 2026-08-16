//! PHP `tests/Messaging/Adapter/SMS/GEOSMS/CallingCodeTest.php`.

use utopia_messaging::adapter::sms::geosms::CallingCode;

#[test]
fn from_phone_number() {
    assert_eq!(
        CallingCode::from_phone_number("+11234567890").as_deref(),
        Some(CallingCode::NORTH_AMERICA)
    );
    assert_eq!(
        CallingCode::from_phone_number("+911234567890").as_deref(),
        Some(CallingCode::INDIA)
    );
    assert_eq!(
        CallingCode::from_phone_number("9721234567890").as_deref(),
        Some(CallingCode::ISRAEL)
    );
    assert_eq!(
        CallingCode::from_phone_number("009711234567890").as_deref(),
        Some(CallingCode::UNITED_ARAB_EMIRATES)
    );
    assert_eq!(
        CallingCode::from_phone_number("011441234567890").as_deref(),
        Some(CallingCode::UNITED_KINGDOM)
    );
    assert_eq!(CallingCode::from_phone_number("2"), None);
}
