//! SMS adapters: a dozen gateways, [`WhatsApp`] one-time passcodes,
//! [`Mock`] for a local request catcher, and [`GeoSms`], which routes each
//! recipient to an adapter by calling code ([`calling_code`]).
//!
//! PHP: `Utopia\Messaging\Adapter\SMS` and its subclasses.

mod bird;
pub mod calling_code;
mod fast2sms;
mod gateways;
mod geosms;
mod whatsapp;

pub use bird::{Bird, Category};
pub use fast2sms::Fast2Sms;
pub use gateways::{
    Clickatell, Infobip, Inforu, Mock, Msg91, Msg91Parameter, Plivo, Seven, Sinch, Telesign, Telnyx, TextMagic, Twilio,
    Vonage,
};
pub use geosms::GeoSms;
pub use whatsapp::{App, OtpType, WhatsApp, WhatsAppParameter};

use crate::message::{Message, Sms};
use crate::response::Response;

/// The kind of every SMS adapter.
///
/// PHP: `Adapter\SMS::TYPE`.
pub const TYPE: &str = "sms";

/// The text message inside a message an SMS adapter was given.
pub(crate) fn sms(message: &Message) -> &Sms {
    match message {
        Message::Sms(sms) => sms,
        _ => unreachable!("send() checks the message type"),
    }
}

/// `ltrim($number, '+')`.
pub(crate) fn digits(number: &str) -> &str {
    number.trim_start_matches('+')
}

/// Every recipient reached, or every recipient failed with `error`.
pub(crate) fn all(to: &[String], success: bool, error: &str) -> Response {
    let mut response = Response::new(TYPE);
    if success {
        response.set_delivered_to(to.len() as i64);
        for recipient in to {
            response.add_success(recipient);
        }
    } else {
        for recipient in to {
            response.add_failure(recipient, error);
        }
    }
    response
}

/// Implements [`Adapter`](crate::Adapter) for an SMS gateway struct with a
/// `base` field and a `send_sms(&self, &Sms)` method.
macro_rules! sms_adapter {
    ($type:ty, $name:expr, $max:expr) => {
        $crate::adapter::configure!($type, |_| $crate::http::Http::new($name));

        impl $crate::adapter::Adapter for $type {
            fn name(&self) -> &str {
                $name
            }

            fn kind(&self) -> &str {
                $crate::sms::TYPE
            }

            fn message_type(&self) -> $crate::message::MessageType {
                $crate::message::MessageType::Sms
            }

            fn max_messages_per_request(&self) -> i64 {
                $max
            }

            fn base(&self) -> &$crate::adapter::Base {
                &self.base
            }

            fn process<'a>(
                &'a self,
                message: &'a $crate::message::Message,
            ) -> $crate::http::BoxFuture<'a, Result<$crate::response::Sent, $crate::error::Error>> {
                Box::pin(
                    async move { self.send_sms($crate::sms::sms(message)).await.map($crate::response::Sent::Response) },
                )
            }
        }
    };
}

pub(crate) use sms_adapter;
