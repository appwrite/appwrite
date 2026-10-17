//! Per-connection state: the session CONNECT negotiated, and the delivery
//! state of QoS 1 messages in flight.

use std::any::Any;
use std::collections::{HashMap, HashSet};
use std::sync::{Arc, Mutex, MutexGuard};

use crate::adapter::Outbound;
use crate::packet::Packet;
use crate::spec::{v3, v5};
use crate::{Error, Properties, Property};

/// What the broker knows about a connection's session. Read and change it
/// through [`Connection::state`].
///
/// PHP: the public properties of `Utopia\Mqtt\Connection`.
#[derive(Clone)]
pub struct State {
    /// The protocol level: 4 for MQTT 3.1.1 (until CONNECT says otherwise), 5
    /// for MQTT 5.0.
    pub protocol: u8,
    /// The isolation key this connection is scoped by (a tenant, a project).
    pub prefix: Vec<u8>,
    /// Clean Start (5.0) / Clean Session (3.1.1): whether a stored session is
    /// discarded, so delivery is live only.
    pub clean_start: bool,
    /// Whatever identity or metadata the broker resolved; the library stores
    /// it and never reads it.
    pub identity: Option<Arc<dyn Any + Send + Sync>>,
    /// Whether CONNECT was accepted (the connection counts as active).
    pub active: bool,
    /// Set once the broker dropped the connection, so a callback resuming
    /// after a yield can tell it is gone.
    pub closed: bool,
    /// The CONNECT keep-alive in seconds; 0 disables keep-alive.
    pub keep_alive: u16,
    /// The Unix time (fractional seconds) past which a silent client is
    /// reaped; 0 while keep-alive is disabled.
    pub expires_at: f64,
    /// The keep-alive wheel second this connection sits in; 0 when not
    /// scheduled.
    pub wheel_slot: i64,
}

impl Default for State {
    fn default() -> Self {
        Self {
            protocol: v3::PROTOCOL_LEVEL,
            prefix: Vec::new(),
            clean_start: true,
            identity: None,
            active: false,
            closed: false,
            keep_alive: 0,
            expires_at: 0.0,
            wheel_slot: 0,
        }
    }
}

impl std::fmt::Debug for State {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("State")
            .field("protocol", &self.protocol)
            .field("prefix", &self.prefix)
            .field("clean_start", &self.clean_start)
            .field("identity", &self.identity.is_some())
            .field("active", &self.active)
            .field("closed", &self.closed)
            .field("keep_alive", &self.keep_alive)
            .field("expires_at", &self.expires_at)
            .field("wheel_slot", &self.wheel_slot)
            .finish()
    }
}

/// A delivery a PUBACK acknowledged.
///
/// PHP: the `['topic' => ..., 'sequence' => ..., 'cursor' => ...]` array
/// `Connection::acknowledge()` returns.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Ack {
    /// The topic delivered.
    pub topic: Vec<u8>,
    /// The sequence acknowledged.
    pub sequence: i64,
    /// The highest sequence of the topic acknowledged without a gap below
    /// it: the one safe to persist for replay.
    pub cursor: i64,
}

#[derive(Default)]
struct Delivery {
    client_id: Vec<u8>,
    packet_id: u16,
    /// Packet id => the topic and sequence in flight.
    inflight: HashMap<u16, (Vec<u8>, i64)>,
    /// Topic => highest contiguously acknowledged sequence.
    cursors: HashMap<Vec<u8>, i64>,
    /// Topic => acknowledged sequences above the cursor, waiting on a gap.
    acked: HashMap<Vec<u8>, HashSet<i64>>,
    /// Topics whose cursor was seeded by `resume()` or `raise()` rather
    /// than by a first delivery.
    resumed: HashSet<Vec<u8>>,
}

impl Delivery {
    /// Advances `cursor` across the contiguous run of acknowledged
    /// sequences above it, so a gap is never skipped.
    ///
    /// PHP: `fold(string $topic, int $cursor)`.
    fn fold(&mut self, topic: &[u8], mut cursor: i64) -> i64 {
        if let Some(pending) = self.acked.get_mut(topic) {
            while pending.remove(&cursor.wrapping_add(1)) {
                cursor = cursor.wrapping_add(1);
            }
        }
        cursor
    }
}

/// One client connection, by the transport's connection id: its session
/// [`State`], and its outbound deliveries. A broker shares it (cheap to
/// clone behind an `Arc`); every method takes `&self`.
///
/// Delivery draws packet ids and tracks each QoS 1 message in flight until
/// its PUBACK, which advances a per-topic cursor: the highest sequence
/// acknowledged without a gap below it.
///
/// ```
/// use utopia_mqtt::Connection;
///
/// let connection = Connection::new(1, None);
/// connection.track(1, b"topic", 5);
/// connection.track(2, b"topic", 6);
/// connection.track(3, b"topic", 7);
/// assert_eq!(connection.acknowledge(1).unwrap().cursor, 5);
/// assert_eq!(connection.acknowledge(3).unwrap().cursor, 5); // 6 is still out
/// assert_eq!(connection.acknowledge(2).unwrap().cursor, 7);
/// assert_eq!(connection.acknowledge(2), None);
/// ```
///
/// ```php
/// use Utopia\Mqtt\Connection;
///
/// $connection = new Connection(1);
/// $connection->track(1, 'topic', 5);
/// $connection->track(2, 'topic', 6);
/// $connection->track(3, 'topic', 7);
/// echo $connection->acknowledge(1)['cursor'], "\n"; // 5
/// echo $connection->acknowledge(3)['cursor'], "\n"; // 5
/// echo $connection->acknowledge(2)['cursor'], "\n"; // 7
/// ```
///
/// PHP: `Utopia\Mqtt\Connection`.
pub struct Connection {
    fd: i64,
    opened_at: f64,
    outbound: Option<Arc<dyn Outbound>>,
    state: Mutex<State>,
    delivery: Mutex<Delivery>,
}

impl std::fmt::Debug for Connection {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        f.debug_struct("Connection").field("fd", &self.fd).field("state", &*self.state()).finish()
    }
}

impl Connection {
    /// A connection with id `fd`, delivering through `outbound` (without
    /// one, delivery does nothing).
    ///
    /// PHP: `new Connection(int $fd, ?Adapter $adapter = null)`.
    pub fn new(fd: i64, outbound: Option<Arc<dyn Outbound>>) -> Self {
        Self {
            fd,
            opened_at: php_std::datetime::microtime_float(),
            outbound,
            state: Mutex::default(),
            delivery: Mutex::default(),
        }
    }

    /// The transport's connection id.
    ///
    /// PHP: `$fd`.
    pub fn fd(&self) -> i64 {
        self.fd
    }

    /// When the connection opened (Unix time, fractional seconds).
    ///
    /// PHP: `$openedAt`.
    pub fn opened_at(&self) -> f64 {
        self.opened_at
    }

    /// The session state, locked. Do not hold the guard while calling the
    /// connection's other methods.
    pub fn state(&self) -> MutexGuard<'_, State> {
        self.state.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn delivery(&self) -> MutexGuard<'_, Delivery> {
        self.delivery.lock().unwrap_or_else(|e| e.into_inner())
    }

    fn protocol(&self) -> u8 {
        self.state().protocol
    }

    /// Delivers a PUBLISH, encoded for the connection's protocol. QoS 1
    /// draws the next packet id, and with a `sequence` is tracked until its
    /// PUBACK (see [`acknowledge`](Connection::acknowledge)).
    ///
    /// PHP: `publish(string $topic, string $payload, int $qos = 0, bool $dup = false, ?int $sequence = null)`.
    pub fn publish(
        &self,
        topic: &[u8],
        payload: &[u8],
        qos: u8,
        dup: bool,
        sequence: Option<i64>,
    ) -> Result<(), Error> {
        let Some(outbound) = &self.outbound else {
            return Ok(());
        };
        let packet_id = if qos > 0 { self.next_packet_id() } else { 0 };
        let packet = if self.protocol() >= v5::PROTOCOL_LEVEL {
            v5::publish(topic, payload, qos, packet_id, None, dup)?
        } else {
            v3::publish(topic, payload, qos, packet_id, dup)
        };
        outbound.send(self.fd, &packet)?;
        if qos == Packet::QOS_1
            && let Some(sequence) = sequence
        {
            self.track(packet_id, topic, sequence);
        }
        Ok(())
    }

    /// Acknowledges a client's QoS 1 PUBLISH.
    ///
    /// PHP: `puback(int $packetId)`.
    pub fn puback(&self, packet_id: u16) -> Result<(), Error> {
        let Some(outbound) = &self.outbound else {
            return Ok(());
        };
        let id = packet_id.to_be_bytes();
        let packet = if self.protocol() >= v5::PROTOCOL_LEVEL { v5::puback(&id) } else { v3::puback(&id) };
        outbound.send(self.fd, &packet)
    }

    /// Closes the connection, first sending a 5.0 client a DISCONNECT with
    /// the reason code (and the Reason String, when not empty) unless the
    /// reason is 0. 3.1.1 has no server DISCONNECT: the socket is just
    /// closed. The close happens even when sending fails; the send error is
    /// returned.
    ///
    /// PHP: `disconnect(int $reason = 0, ?string $reasonString = null)`.
    pub fn disconnect(&self, reason: u8, reason_string: Option<&[u8]>) -> Result<(), Error> {
        let Some(outbound) = &self.outbound else {
            return Ok(());
        };
        let sent = if reason != 0 && self.protocol() >= v5::PROTOCOL_LEVEL {
            let properties = reason_string
                .filter(|r| !r.is_empty())
                .map(|r| Properties::new().with(Property::bytes(Property::REASON_STRING, r)));
            v5::disconnect(reason, properties.as_ref()).and_then(|packet| outbound.send(self.fd, &packet))
        } else {
            Ok(())
        };
        let closed = outbound.close(self.fd);
        sent.and(closed)
    }

    /// Pushes the keep-alive deadline to `multiplier` keep-alive intervals
    /// past `now`: every packet a client sends shows it is alive. Does
    /// nothing while keep-alive is disabled.
    ///
    /// PHP: `updateExpiresAt(float $now, float $multiplier)`.
    pub fn update_expires_at(&self, now: f64, multiplier: f64) {
        let mut state = self.state();
        if state.keep_alive > 0 {
            state.expires_at = now + f64::from(state.keep_alive) * multiplier;
        }
    }

    /// The next outbound packet id, from 1 to 65,535 and around again (0 is
    /// not a packet id).
    ///
    /// PHP: `nextPacketId()`.
    pub fn next_packet_id(&self) -> u16 {
        let mut delivery = self.delivery();
        delivery.packet_id = (delivery.packet_id % 0xFFFF) + 1;
        delivery.packet_id
    }

    /// Anchors a topic's cursor at the sequence delivery resumes from (its
    /// persisted cursor), before delivering the topic. Without it the first
    /// tracked delivery anchors the cursor one below its sequence, which is
    /// right only when a topic is tracked in order.
    ///
    /// PHP: `resume(string $topic, int $cursor)`.
    pub fn resume(&self, topic: &[u8], cursor: i64) {
        let mut delivery = self.delivery();
        delivery.cursors.insert(topic.to_vec(), cursor);
        delivery.resumed.insert(topic.to_vec());
    }

    /// Whether the topic's cursor was seeded by [`resume`](Connection::resume)
    /// or [`raise`](Connection::raise), so it tracks acknowledged progress
    /// from a persisted cursor.
    ///
    /// PHP: `resumed(string $topic)`.
    pub fn resumed(&self, topic: &[u8]) -> bool {
        self.delivery().resumed.contains(topic)
    }

    /// Raises a topic's cursor to at least `cursor` without lowering it,
    /// unlike [`resume`](Connection::resume): acknowledged sequences at or
    /// below it are dropped and the contiguous acknowledged run above it is
    /// folded in.
    ///
    /// PHP: `raise(string $topic, int $cursor)`.
    pub fn raise(&self, topic: &[u8], cursor: i64) {
        let mut delivery = self.delivery();
        let cursor = delivery.cursors.get(topic).copied().unwrap_or(cursor).max(cursor);
        if let Some(pending) = delivery.acked.get_mut(topic) {
            pending.retain(|&sequence| sequence > cursor);
        }
        let cursor = delivery.fold(topic, cursor);
        delivery.cursors.insert(topic.to_vec(), cursor);
        delivery.resumed.insert(topic.to_vec());
    }

    /// The resume baseline advanced by contiguous acknowledgements; `None`
    /// while the topic is untracked.
    ///
    /// PHP: `cursor(string $topic)`.
    pub fn cursor(&self, topic: &[u8]) -> Option<i64> {
        self.delivery().cursors.get(topic).copied()
    }

    /// Records an outbound QoS 1 delivery so its PUBACK can be matched back
    /// to its topic and sequence.
    ///
    /// PHP: `track(int $packetId, string $topic, int $sequence)`.
    pub fn track(&self, packet_id: u16, topic: &[u8], sequence: i64) {
        let mut delivery = self.delivery();
        delivery.inflight.insert(packet_id, (topic.to_vec(), sequence));
        delivery.cursors.entry(topic.to_vec()).or_insert(sequence.wrapping_sub(1));
    }

    /// Resolves a PUBACK to the delivery it acknowledges, and advances the
    /// topic's cursor across the run of acknowledged sequences above it, so a
    /// sequence never delivered or not acknowledged yet is never skipped.
    /// `None` for an unknown or repeated packet id.
    ///
    /// PHP: `acknowledge(int $packetId)`.
    pub fn acknowledge(&self, packet_id: u16) -> Option<Ack> {
        let mut delivery = self.delivery();
        let (topic, sequence) = delivery.inflight.remove(&packet_id)?;
        delivery.acked.entry(topic.clone()).or_default().insert(sequence);
        let start = delivery.cursors.get(&topic).copied().unwrap_or(sequence.wrapping_sub(1));
        let cursor = delivery.fold(&topic, start);
        delivery.cursors.insert(topic.clone(), cursor);
        Some(Ack { topic, sequence, cursor })
    }

    /// Sets the Client Identifier: the client's, or `mqtt_<fd>` when it sent
    /// none (a server-assigned id). A broker resuming sessions across
    /// reconnects should resolve a stable id itself.
    ///
    /// PHP: `setClientId(string $clientId)`.
    pub fn set_client_id(&self, client_id: &[u8]) {
        self.delivery().client_id =
            if client_id.is_empty() { format!("mqtt_{}", self.fd).into_bytes() } else { client_id.to_vec() };
    }

    /// The Client Identifier.
    ///
    /// PHP: `getClientId()`.
    pub fn client_id(&self) -> Vec<u8> {
        self.delivery().client_id.clone()
    }
}
