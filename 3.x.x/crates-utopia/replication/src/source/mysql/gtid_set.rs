use std::fmt;

/// PHP `Utopia\Replication\Source\MySQL\GtidSet`.
#[derive(Debug, Clone, Default)]
pub struct GtidSet {
    /// Lower-cased UUID => sorted inclusive [start, end] intervals. Insertion order.
    sids: Vec<(String, Vec<(i64, i64)>)>,
}

impl GtidSet {
    /// PHP `__construct(string $gtidSet = '')`.
    #[must_use]
    pub fn new(gtid_set: &str) -> Self {
        let mut set = Self::default();
        let trimmed = gtid_set.trim();
        if trimmed.is_empty() {
            return set;
        }
        for entry in trimmed.split(',') {
            let mut parts = entry.trim().split(':');
            let Some(sid) = parts.next() else {
                continue;
            };
            let sid = sid.to_ascii_lowercase();
            for interval in parts {
                let (start, end) = if let Some((a, b)) = interval.split_once('-') {
                    (parse_i64(a), parse_i64(b))
                } else {
                    let n = parse_i64(interval);
                    (n, n)
                };
                set.add_interval(&sid, start, end);
            }
        }
        set
    }

    /// PHP `add(string $sid, int $gno)`.
    pub fn add(&mut self, sid: &str, gno: i64) {
        self.add_interval(&sid.to_ascii_lowercase(), gno, gno);
    }

    fn add_interval(&mut self, sid: &str, start: i64, end: i64) {
        let intervals = if let Some((_, intervals)) = self.sids.iter_mut().find(|(s, _)| s == sid) {
            intervals
        } else {
            self.sids.push((sid.to_owned(), Vec::new()));
            &mut self.sids.last_mut().unwrap().1
        };
        intervals.push((start, end));
        intervals.sort_by_key(|i| i.0);
        let mut merged: Vec<(i64, i64)> = Vec::new();
        for interval in intervals.drain(..) {
            if let Some(last) = merged.last_mut() {
                if interval.0 <= last.1 + 1 {
                    last.1 = last.1.max(interval.1);
                    continue;
                }
            }
            merged.push(interval);
        }
        *intervals = merged;
    }

    /// PHP `isEmpty()`.
    #[must_use]
    pub fn is_empty(&self) -> bool {
        self.sids.is_empty()
    }

    /// PHP `encode()`.
    #[must_use]
    pub fn encode(&self) -> Vec<u8> {
        let mut payload = Vec::new();
        payload.extend_from_slice(&(self.sids.len() as u64).to_le_bytes());
        for (sid, intervals) in &self.sids {
            let hex: String = sid.chars().filter(|c| *c != '-').collect();
            let bytes = hex::decode(hex).unwrap_or_default();
            payload.extend_from_slice(&bytes);
            payload.extend_from_slice(&(intervals.len() as u64).to_le_bytes());
            for (start, end) in intervals {
                payload.extend_from_slice(&(*start as u64).to_le_bytes());
                payload.extend_from_slice(&((*end + 1) as u64).to_le_bytes());
            }
        }
        payload
    }
}

impl fmt::Display for GtidSet {
    fn fmt(&self, f: &mut fmt::Formatter<'_>) -> fmt::Result {
        let mut entries = Vec::new();
        for (sid, intervals) in &self.sids {
            let mut parts = vec![sid.clone()];
            for (start, end) in intervals {
                if start == end {
                    parts.push(start.to_string());
                } else {
                    parts.push(format!("{start}-{end}"));
                }
            }
            entries.push(parts.join(":"));
        }
        write!(f, "{}", entries.join(","))
    }
}

fn parse_i64(s: &str) -> i64 {
    s.parse().unwrap_or(0)
}
