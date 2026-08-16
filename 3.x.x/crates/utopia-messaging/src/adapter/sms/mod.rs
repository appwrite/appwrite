//! PHP `Utopia\Messaging\Adapter\SMS` and providers.

pub mod clickatell;
pub mod fast2sms;
pub mod geosms;
pub mod infobip;
pub mod inforu;
pub mod mock;
pub mod msg91;
pub mod plivo;
pub mod seven;
pub mod sinch;
pub mod telesign;
pub mod telnyx;
pub mod textmagic;
pub mod twilio;
pub mod vonage;

pub use clickatell::Clickatell;
pub use fast2sms::Fast2SMS;
pub use geosms::GEOSMS;
pub use infobip::Infobip;
pub use inforu::Inforu;
pub use mock::Mock;
pub use msg91::Msg91;
pub use plivo::Plivo;
pub use seven::Seven;
pub use sinch::Sinch;
pub use telesign::Telesign;
pub use telnyx::Telnyx;
pub use textmagic::TextMagic;
pub use twilio::Twilio;
pub use vonage::Vonage;

use crate::http::HttpResult;
use crate::messages::SMS;
use crate::response::{Response, ResponseData};

/// PHP `Adapter\SMS` (`TYPE = 'sms'`).
pub const TYPE: &str = "sms";

fn sms_response_from_status(
    message: &SMS,
    result: &HttpResult,
    ok: impl Fn(&HttpResult) -> bool,
    error_for: impl Fn(&HttpResult) -> String,
) -> ResponseData {
    let mut response = Response::new(TYPE);
    if ok(result) {
        response.set_delivered_to(message.get_to().len() as i64);
        for to in message.get_to() {
            response.add_result(to, "");
        }
    } else {
        let error = error_for(result);
        for to in message.get_to() {
            response.add_result(to, error.clone());
        }
    }
    response.to_array()
}

fn status_2xx(result: &HttpResult) -> bool {
    (200..300).contains(&result.status_code)
}

fn unknown_error(_result: &HttpResult) -> String {
    "Unknown error.".into()
}
