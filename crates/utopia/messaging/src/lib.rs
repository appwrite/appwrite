//! Email, SMS, push and chat messages, sent through provider adapters.
//!
//! A message ([`Email`], [`Sms`], [`Push`], [`Discord`]) is validated when
//! built. An [`Adapter`] sends one kind of message through one provider:
//! [`Adapter::send`] checks the message type and the recipient limit,
//! processes it, and records how many recipients were reached on the
//! `messaging.send` counter of the adapter's telemetry. A provider's
//! answer, refusals included, comes back as a [`Response`] with a result
//! per recipient; only input no provider can deliver is an [`Error`].
//!
//! ```no_run
//! use utopia_messaging::sms::Twilio;
//! use utopia_messaging::{Adapter, Message, Sms};
//!
//! # async fn run() -> Result<(), utopia_messaging::Error> {
//! let twilio = Twilio::new("AC123", "auth-token").with_from(Some("+15550000000".into()));
//! let message = Message::from(Sms::new(["+15551234567"], "Your code is 123456"));
//! let sent = twilio.send(&message).await?;
//! let response = sent.response().expect("one adapter, one response");
//! println!("delivered to {}", response.delivered_to());
//! # Ok(())
//! # }
//! ```
//!
//! ```php
//! use Utopia\Messaging\Adapter\SMS\Twilio;
//! use Utopia\Messaging\Messages\SMS;
//!
//! $twilio = new Twilio('AC123', 'auth-token', '+15550000000');
//! $response = $twilio->send(new SMS(['+15551234567'], 'Your code is 123456'));
//! echo 'delivered to ', $response['deliveredTo'], "\n";
//! ```
//!
//! Requests go through a [`Requester`]: by default [`Http`], which encodes
//! the body as the headers say and sends it through `utopia_client` (and
//! HTTP/2 for APNs), only to public addresses. [`Configure`] swaps the
//! transport (`with_transport`, PHP's `$clientFactory`) or the requester
//! itself (`with_requester`, PHP's overridden `request()`).
//!
//! PHP: `utopia-php/messaging`.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Utopia\Messaging\Adapter` (`send()`, `setTelemetry()`, `getName()`, `getType()`, `getMessageType()`, `getMaxMessagesPerRequest()`) | [`Adapter`] ([`Adapter::send`], [`Adapter::set_telemetry`], [`Adapter::name`], [`Adapter::kind`], [`Adapter::message_type`], [`Adapter::max_messages_per_request`]) |
//! | the constructor's `$telemetry` and `$clientFactory`, `destinations()` | [`Configure::with_telemetry`], [`Configure::with_transport`], [`Http::with_destinations`] |
//! | `request()`, `requestMulti()`, `buildRequest()`, `buildResult()` | [`Requester`], [`Http`], [`Call`], [`Multi`], [`Reply`] |
//! | `Adapter\Email`, `Adapter\SMS`, `Adapter\Push` | [`email`], [`sms`], [`push`] (their `TYPE`) |
//! | `Adapter\Email::formatAddress()` | [`email::format_address`] |
//! | `Adapter\Email\SMTP` (`disconnect()`), `Mock` | [`email::Smtp`] ([`email::Smtp::disconnect`], [`email::Smtp::hosts`]), [`email::Mock`] |
//! | `Adapter\Email\SMTP\NoHostAnswered` | [`email::NoHostAnswered`] |
//! | `Adapter\Email\SES` (`sign()`) | [`email::Ses`] ([`email::Ses::sign`]) |
//! | `Adapter\Email\Mailgun`, `Sendgrid`, `Resend` | [`email::Mailgun`], [`email::Sendgrid`], [`email::Resend`] |
//! | `Adapter\Email\Mime` (`message()`, `addresses()`, `attachments()`, `size()`) | [`email::mime`] |
//! | `Adapter\SMS\Twilio`, `Vonage`, `Telnyx`, `Plivo`, `Sinch`, `Seven`, `Clickatell`, `Infobip`, `Inforu`, `TextMagic`, `Telesign`, `Msg91`, `Fast2SMS`, `Mock` | [`sms::Twilio`], [`sms::Vonage`], [`sms::Telnyx`], [`sms::Plivo`], [`sms::Sinch`], [`sms::Seven`], [`sms::Clickatell`], [`sms::Infobip`], [`sms::Inforu`], [`sms::TextMagic`], [`sms::Telesign`], [`sms::Msg91`], [`sms::Fast2Sms`], [`sms::Mock`] |
//! | `Adapter\SMS\Mock::getEndpoint()`, `setEndpoint()` | [`sms::Mock::endpoint`], [`sms::Mock::set_endpoint`] |
//! | `Adapter\SMS\Msg91\MetadataParameter` | [`sms::Msg91Parameter`] |
//! | `Adapter\SMS\Bird` (`hostFor()`), `Bird\Category` | [`sms::Bird`] ([`sms::Bird::host_for`]), [`sms::Category`] |
//! | `Adapter\SMS\GEOSMS` (`setLocal()`), `GEOSMS\CallingCode` (`fromPhoneNumber()`) | [`sms::GeoSms`] ([`sms::GeoSms::set_local`]), [`sms::calling_code`] ([`sms::calling_code::from_phone_number`]) |
//! | `Adapter\SMS\WhatsApp` (`upsertTemplate()`, `getTemplate()`, `deleteTemplate()`) | [`sms::WhatsApp`] ([`sms::WhatsApp::upsert_template`], [`sms::WhatsApp::template`], [`sms::WhatsApp::delete_template`]) |
//! | `WhatsApp\App` (`toArray()`), `WhatsApp\OtpType` (`requiresApps()`), `WhatsApp\MetadataParameter` | [`sms::App`] ([`sms::App::to_array`]), [`sms::OtpType`] ([`sms::OtpType::requires_apps`]), [`sms::WhatsAppParameter`] |
//! | `Adapter\Push\APNS`, `FCM` (`getError()`) | [`push::Apns`], [`push::Fcm`] ([`push::error`]) |
//! | `Adapter\Chat\Discord` | [`chat::DiscordWebhook`] |
//! | `Helpers\JWT::encode()` | [`jwt::encode`] |
//! | `Message` (`setOrigin()`, `getOrigin()`) | [`Message`] |
//! | `Messages\Email` and its getters | [`Email`], built by [`EmailBuilder`]; recipients are [`Recipient`]s, read back as [`Mailbox`]es |
//! | `Messages\Email\Attachment` | [`Attachment`] |
//! | `Messages\SMS`, `Messages\Push`, `Messages\Discord` | [`Sms`], [`Push`] (built by [`PushBuilder`]), [`Discord`] |
//! | `Priority` | [`Priority`] |
//! | `Response` (`setDeliveredTo()`, `incrementDeliveredTo()`, `getDeliveredTo()`, `setType()`, `getType()`, `getDetails()`, `addResult()`, `toArray()`) | [`Response`] ([`Response::set_delivered_to`], [`Response::increment_delivered_to`], [`Response::delivered_to`], [`Response::set_kind`], [`Response::kind`], [`Response::details`], [`Response::add_result`]); `send()`'s array is [`Sent`] |
//! | `Exception\InvalidArgumentException` (`getType()`, `getValue()`, its constants) | [`Error::Invalid`] ([`Reason`]) |
//!
//! PHP strings are bytes, and messages here are UTF-8.
#![warn(missing_docs)]

mod adapter;
pub mod chat;
pub mod email;
mod error;
mod http;
mod http2;
pub mod jwt;
mod message;
pub mod push;
mod response;
pub mod sms;
mod values;

pub use adapter::{Adapter, Base, Configure, SEND_COUNTER};
pub use error::{Error, Reason};
pub use http::{BoxFuture, Call, Http, MAX_CONCURRENT_REQUESTS, Multi, OnResult, Reply, Requester, Transport};
pub use http2::Http2;
pub use message::{
    Attachment, Discord, Email, EmailBuilder, Mailbox, Message, MessageType, Priority, Push, PushBuilder, Recipient,
    Sms,
};
pub use response::{Batch, Delivery, Response, Sent, Status};

/// The getting started guide (`guide.md`), compiled and run with the doctests.
#[cfg(doctest)]
#[doc = include_str!("../guide.md")]
pub struct GettingStarted;
