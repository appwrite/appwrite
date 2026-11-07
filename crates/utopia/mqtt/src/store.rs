//! The subscription store: a topic-filter trie that resolves a publish to
//! its subscribers in one walk.

use std::collections::HashMap;

use foldhash::fast::RandomState;
use indexmap::IndexMap;

/// Subscribers by connection, each with its granted QoS, in the order they
/// were found.
pub type Subscribers = IndexMap<i64, u8, RandomState>;

/// One topic level of the trie.
#[derive(Default)]
struct Node {
    /// Child levels by name.
    nodes: HashMap<Box<[u8]>, Node, RandomState>,
    /// Connection => granted QoS, for the filters ending here, in the order
    /// they subscribed.
    fds: IndexMap<i64, u8, RandomState>,
}

impl Node {
    fn is_empty(&self) -> bool {
        self.nodes.is_empty() && self.fds.is_empty()
    }

    /// Removes `fd` from the node at `levels` below this one and prunes the
    /// branch back to the first node still in use. Returns whether this
    /// node is now empty, or `None` when the path does not exist.
    fn remove(&mut self, levels: &[&[u8]], fd: i64) -> Option<bool> {
        let Some((first, rest)) = levels.split_first() else {
            self.fds.shift_remove(&fd);
            return Some(self.is_empty());
        };
        let child = self.nodes.get_mut(*first)?;
        if child.remove(rest, fd)? {
            self.nodes.remove(*first);
            Some(self.is_empty())
        } else {
            Some(false)
        }
    }
}

/// What the store knows of one connection: the isolation key and user it
/// subscribed under, and its subscriptions.
///
/// PHP: the `['prefix' => ..., 'userId' => ..., 'subs' => ...]` array
/// `Store::getConnection()` returns.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Record {
    /// The isolation key (a tenant, a project).
    pub prefix: Vec<u8>,
    /// The user the connection subscribed as.
    pub user_id: Vec<u8>,
    /// Topic filter => granted QoS, in the order subscribed.
    pub subs: IndexMap<Vec<u8>, u8>,
}

/// A topic-filter trie for MQTT subscriptions, indexed by a prefix (an
/// isolation key: a tenant, a project) and then by topic level, so a publish
/// finds every matching filter (`+` one level, `#` the rest) in one walk.
///
/// ```text
/// root -> prefix -> "test" -> "hello" -> fds: {11: 1}
///                          -> "#"     -> fds: {12: 1}
/// ```
///
/// A wildcard filter that does not itself start with `$` never matches a
/// topic whose first level does (`$SYS/...`, MQTT 4.7.2).
///
/// ```
/// use utopia_mqtt::Store;
///
/// let mut store = Store::new();
/// store.subscribe(b"proj", b"user", b"test/+", 11, 1);
/// store.subscribe(b"proj", b"user", b"test/#", 12, 0);
/// store.subscribe(b"other", b"user", b"test/hello", 13, 1);
///
/// let subscribers = store.subscribers(b"proj", b"test/hello");
/// assert_eq!(subscribers.into_iter().collect::<Vec<_>>(), [(12, 0), (11, 1)]);
/// ```
///
/// ```php
/// use Utopia\Mqtt\Subscription\Store;
///
/// $store = new Store();
/// $store->subscribe('proj', 'user', 'test/+', 11, 1);
/// $store->subscribe('proj', 'user', 'test/#', 12, 0);
/// $store->subscribe('other', 'user', 'test/hello', 13, 1);
/// print_r($store->getSubscribers('proj', 'test/hello')); // [12 => 0, 11 => 1]
/// ```
///
/// PHP: `Utopia\Mqtt\Subscription\Store` (and its trie node,
/// `Subscription\Node`).
#[derive(Default)]
pub struct Store {
    connections: HashMap<i64, Record, RandomState>,
    root: Node,
}

/// The levels of a topic under a prefix: the prefix, then each `/`-separated
/// level.
fn levels<'a>(prefix: &'a [u8], topic: &'a [u8]) -> impl Iterator<Item = &'a [u8]> {
    std::iter::once(prefix).chain(topic.split(|&b| b == b'/'))
}

impl Store {
    /// An empty store.
    pub fn new() -> Self {
        Self::default()
    }

    /// Subscribes connection `fd` to the filter `topic` under `prefix`, at
    /// `qos`. Subscribing the same filter again replaces its QoS (MQTT
    /// 3.8.4). A connection is one identity: when `fd` reappears under
    /// another prefix or user, it was reused, and its earlier subscriptions
    /// are dropped first.
    ///
    /// PHP: `subscribe(string $prefix, string $userId, string $topic, int $fd, int $qos = 1)`.
    pub fn subscribe(&mut self, prefix: &[u8], user_id: &[u8], topic: &[u8], fd: i64, qos: u8) {
        if self.connections.get(&fd).is_some_and(|c| c.prefix != prefix || c.user_id != user_id) {
            self.close(fd);
        }
        if self.connections.get(&fd).is_some_and(|c| c.subs.contains_key(topic)) {
            self.unsubscribe(topic, fd);
        }

        let mut node = &mut self.root;
        for level in levels(prefix, topic) {
            node = node.nodes.entry(level.into()).or_default();
        }
        node.fds.insert(fd, qos);

        self.connections
            .entry(fd)
            .or_insert_with(|| Record { prefix: prefix.to_vec(), user_id: user_id.to_vec(), subs: IndexMap::new() })
            .subs
            .insert(topic.to_vec(), qos);
    }

    /// Unsubscribes connection `fd` from the filter `topic`, pruning the
    /// trie branch that no longer leads anywhere. Unknown filters and
    /// connections are ignored.
    ///
    /// PHP: `unsubscribe(string $topic, int $fd)`.
    pub fn unsubscribe(&mut self, topic: &[u8], fd: i64) {
        let Some(record) = self.connections.get_mut(&fd) else {
            return;
        };
        if !record.subs.contains_key(topic) {
            return;
        }
        let path: Vec<&[u8]> = levels(&record.prefix, topic).collect();
        self.root.remove(&path, fd);
        record.subs.shift_remove(topic);
        if record.subs.is_empty() {
            self.connections.remove(&fd);
        }
    }

    /// Drops every subscription of connection `fd`.
    ///
    /// PHP: `close(int $fd)`.
    pub fn close(&mut self, fd: i64) {
        let topics: Vec<Vec<u8>> =
            self.connections.get(&fd).map(|c| c.subs.keys().cloned().collect()).unwrap_or_default();
        for topic in topics {
            self.unsubscribe(&topic, fd);
        }
        self.connections.remove(&fd);
    }

    /// What the store knows of connection `fd`, if it has subscriptions.
    ///
    /// PHP: `getConnection(int $fd)`.
    pub fn connection(&self, fd: i64) -> Option<&Record> {
        self.connections.get(&fd)
    }

    /// The connections subscribed to `topic` under `prefix`, each with the
    /// highest QoS any of its matching filters was granted.
    ///
    /// PHP: `getSubscribers(string $prefix, string $topic)`.
    pub fn subscribers(&self, prefix: &[u8], topic: &[u8]) -> Subscribers {
        // The nodes whose subscribers match, in the order PHP collects them.
        let mut matched: Vec<&Node> = Vec::new();
        let mut current: Vec<&Node> = vec![&self.root];
        let mut next: Vec<&Node> = Vec::new();
        for (index, level) in levels(prefix, topic).enumerate() {
            // Index 0 is the prefix, so index 1 is the topic's first level.
            let system = index == 1 && level.first() == Some(&b'$');
            next.clear();
            for parent in &current {
                if !system && let Some(rest) = parent.nodes.get(&b"#"[..]) {
                    matched.push(rest);
                }
                if let Some(exact) = parent.nodes.get(level) {
                    next.push(exact);
                }
                if !system && let Some(one) = parent.nodes.get(&b"+"[..]) {
                    next.push(one);
                }
            }
            std::mem::swap(&mut current, &mut next);
            if current.is_empty() {
                break;
            }
        }
        for parent in &current {
            matched.push(*parent);
            // A trailing #: the filter `a/#` matches the topic `a`.
            if let Some(rest) = parent.nodes.get(&b"#"[..]) {
                matched.push(rest);
            }
        }
        let mut nodes = matched.into_iter().filter(|n| !n.fds.is_empty());
        let Some(first) = nodes.next() else {
            return Subscribers::default();
        };
        // The first node's connections are distinct: take them as they are.
        let mut found = first.fds.clone();
        for node in nodes {
            for (&fd, &qos) in &node.fds {
                let granted = found.entry(fd).or_insert(0);
                *granted = (*granted).max(qos);
            }
        }
        found
    }
}
