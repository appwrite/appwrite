//! Canonical samples: immutable, identified observations of one stream,
//! read back with evidence of their completeness (conflicts, gaps,
//! discontinuities, truncation).

use php_std::datetime::{DateTime, TimeZone};
use sha2::{Digest, Sha256};

use crate::error::{Error, Result};

fn utc_text(time: &DateTime) -> String {
    let mut utc = time.clone();
    utc.set_timezone(&TimeZone::utc());
    String::from_utf8_lossy(&utc.format(b"Y-m-d H:i:s.v")).into_owned()
}

fn non_empty(fields: [(&str, &str); 7]) -> Result<()> {
    for (field, value) in fields {
        if value.is_empty() {
            return Err(Error::invalid_argument(format!("{field} cannot be empty")));
        }
    }
    Ok(())
}

/// Length-prefixed parts, hashed: `sha256("<len>:<part>...")` in hex.
fn hash_parts(parts: &[&str]) -> String {
    let mut hasher = Sha256::new();
    for part in parts {
        hasher.update(part.len().to_string().as_bytes());
        hasher.update(b":");
        hasher.update(part.as_bytes());
    }
    hex::encode(hasher.finalize())
}

/// One observation of a stream: who measured it (environment, region,
/// project, database, member, generation), its sequence number, the metric,
/// the interval it covers and the value.
///
/// PHP: `Utopia\Usage\Sample`.
#[derive(Debug, Clone)]
pub struct Sample {
    /// `environment`.
    pub environment: String,
    /// `region`.
    pub region: String,
    /// `projectInternalId`.
    pub project_internal_id: String,
    /// `databaseInternalId`.
    pub database_internal_id: String,
    /// `member`.
    pub member: String,
    /// `generation`.
    pub generation: String,
    /// `sequence`.
    pub sequence: i64,
    /// `metric`.
    pub metric: String,
    /// `intervalStart`.
    pub interval_start: DateTime,
    /// `intervalEnd`.
    pub interval_end: DateTime,
    /// `value`.
    pub value: i64,
    /// `eventVersion` (an unsigned 32-bit integer, at least 1).
    pub event_version: i64,
}

impl Sample {
    /// A sample, checked: no empty identity field, a non-negative sequence,
    /// an event version in `1..=4294967295`, and a start before the end.
    ///
    /// PHP: `new Sample(...)`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        environment: &str,
        region: &str,
        project_internal_id: &str,
        database_internal_id: &str,
        member: &str,
        generation: &str,
        sequence: i64,
        metric: &str,
        interval_start: DateTime,
        interval_end: DateTime,
        value: i64,
        event_version: i64,
    ) -> Result<Sample> {
        non_empty([
            ("environment", environment),
            ("region", region),
            ("projectInternalId", project_internal_id),
            ("databaseInternalId", database_internal_id),
            ("member", member),
            ("generation", generation),
            ("metric", metric),
        ])?;
        if sequence < 0 {
            return Err(Error::invalid_argument("sequence cannot be negative"));
        }
        if !(1..=4_294_967_295).contains(&event_version) {
            return Err(Error::invalid_argument("eventVersion must fit an unsigned 32-bit integer"));
        }
        if interval_start >= interval_end {
            return Err(Error::invalid_argument("intervalStart must be before intervalEnd"));
        }
        Ok(Sample {
            environment: environment.to_owned(),
            region: region.to_owned(),
            project_internal_id: project_internal_id.to_owned(),
            database_internal_id: database_internal_id.to_owned(),
            member: member.to_owned(),
            generation: generation.to_owned(),
            sequence,
            metric: metric.to_owned(),
            interval_start,
            interval_end,
            value,
            event_version,
        })
    }

    /// The canonical stream identity: a retry keeps it even when its
    /// payload changed.
    ///
    /// PHP: `getId()`.
    pub fn id(&self) -> String {
        hash_parts(&[
            &self.environment,
            &self.region,
            &self.project_internal_id,
            &self.database_internal_id,
            &self.member,
            &self.generation,
            &self.sequence.to_string(),
            &self.metric,
        ])
    }

    /// The hash of every money-bearing field: equal ids with different
    /// payload hashes are conflicting observations.
    ///
    /// PHP: `getPayloadHash()`.
    pub fn payload_hash(&self) -> String {
        hash_parts(&[
            &self.id(),
            &utc_text(&self.interval_start),
            &utc_text(&self.interval_end),
            &self.value.to_string(),
            &self.event_version.to_string(),
        ])
    }

    /// The start in UTC, `Y-m-d H:i:s.v`.
    ///
    /// PHP: `getFormattedIntervalStart()`.
    pub fn formatted_interval_start(&self) -> String {
        utc_text(&self.interval_start)
    }

    /// The end in UTC, `Y-m-d H:i:s.v`.
    ///
    /// PHP: `getFormattedIntervalEnd()`.
    pub fn formatted_interval_end(&self) -> String {
        utc_text(&self.interval_end)
    }
}

/// One exact range of a stream: the identity fields, a sequence range and
/// the interval it must cover.
///
/// PHP: `Utopia\Usage\SampleRange`.
#[derive(Debug, Clone)]
pub struct SampleRange {
    /// `environment`.
    pub environment: String,
    /// `region`.
    pub region: String,
    /// `projectInternalId`.
    pub project_internal_id: String,
    /// `databaseInternalId`.
    pub database_internal_id: String,
    /// `member`.
    pub member: String,
    /// `generation`.
    pub generation: String,
    /// `metric`.
    pub metric: String,
    /// `firstSequence`.
    pub first_sequence: i64,
    /// `lastSequence`.
    pub last_sequence: i64,
    /// `intervalStart`.
    pub interval_start: DateTime,
    /// `intervalEnd`.
    pub interval_end: DateTime,
}

impl SampleRange {
    /// A range, checked like [`Sample::new`]: no empty field,
    /// `0 <= first <= last`, a start before the end.
    ///
    /// PHP: `new SampleRange(...)`.
    #[allow(clippy::too_many_arguments)]
    pub fn new(
        environment: &str,
        region: &str,
        project_internal_id: &str,
        database_internal_id: &str,
        member: &str,
        generation: &str,
        metric: &str,
        first_sequence: i64,
        last_sequence: i64,
        interval_start: DateTime,
        interval_end: DateTime,
    ) -> Result<SampleRange> {
        non_empty([
            ("environment", environment),
            ("region", region),
            ("projectInternalId", project_internal_id),
            ("databaseInternalId", database_internal_id),
            ("member", member),
            ("generation", generation),
            ("metric", metric),
        ])?;
        if first_sequence < 0 || last_sequence < first_sequence {
            return Err(Error::invalid_argument("Invalid sample sequence range"));
        }
        if interval_start >= interval_end {
            return Err(Error::invalid_argument("intervalStart must be before intervalEnd"));
        }
        Ok(SampleRange {
            environment: environment.to_owned(),
            region: region.to_owned(),
            project_internal_id: project_internal_id.to_owned(),
            database_internal_id: database_internal_id.to_owned(),
            member: member.to_owned(),
            generation: generation.to_owned(),
            metric: metric.to_owned(),
            first_sequence,
            last_sequence,
            interval_start,
            interval_end,
        })
    }

    /// The start in UTC, `Y-m-d H:i:s.v`.
    ///
    /// PHP: `getFormattedIntervalStart()`.
    pub fn formatted_interval_start(&self) -> String {
        utc_text(&self.interval_start)
    }

    /// The end in UTC, `Y-m-d H:i:s.v`.
    ///
    /// PHP: `getFormattedIntervalEnd()`.
    pub fn formatted_interval_end(&self) -> String {
        utc_text(&self.interval_end)
    }
}

/// A run of missing sequence numbers, `first..=last`.
///
/// PHP: `Utopia\Usage\SampleGap`.
#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct SampleGap {
    /// The first missing sequence.
    pub first: i64,
    /// The last missing sequence.
    pub last: i64,
}

impl SampleGap {
    /// A gap, `0 <= first <= last`.
    ///
    /// PHP: `new SampleGap($first, $last)`.
    pub fn new(first: i64, last: i64) -> Result<SampleGap> {
        if first < 0 || last < first {
            return Err(Error::invalid_argument("Invalid sample gap"));
        }
        Ok(SampleGap { first, last })
    }
}

/// The exact membership one snapshot of a range captured: entries
/// `<ingestId>:<id>:<payloadHash>`, and whether more existed.
///
/// PHP: `Utopia\Usage\SampleWatermark`.
#[derive(Debug, Clone)]
pub struct SampleWatermark {
    range: SampleRange,
    entries: Vec<String>,
    truncated: bool,
}

fn is_entry(entry: &str) -> bool {
    // `^[a-f0-9]{32}:[a-f0-9]{64}:[a-f0-9]{64}$`, where `$` also matches
    // before a final newline.
    let body = entry.strip_suffix('\n').unwrap_or(entry);
    let hex = |s: &str, n: usize| s.len() == n && s.bytes().all(|b| matches!(b, b'0'..=b'9' | b'a'..=b'f'));
    let mut parts = body.splitn(3, ':');
    matches!((parts.next(), parts.next(), parts.next()), (Some(a), Some(b), Some(c)) if hex(a, 32) && hex(b, 64) && hex(c, 64))
}

impl SampleWatermark {
    /// A watermark of `entries` (unique, each binding an ingestion id, a
    /// canonical id and a payload hash) for `range`.
    ///
    /// PHP: `new SampleWatermark($range, $entries, $truncated)`.
    pub fn new(range: SampleRange, entries: Vec<String>, truncated: bool) -> Result<SampleWatermark> {
        let mut seen = std::collections::HashSet::with_capacity(entries.len());
        if !entries.iter().all(|e| seen.insert(e.as_str())) {
            return Err(Error::invalid_argument("entries must be a unique list"));
        }
        if !entries.iter().all(|e| is_entry(e)) {
            return Err(Error::invalid_argument("entries must bind an ingestion ID, canonical ID and payload hash"));
        }
        Ok(SampleWatermark { range, entries, truncated })
    }

    /// Whether the watermark was taken for exactly `range`.
    ///
    /// PHP: `matches($range)`.
    pub fn matches(&self, range: &SampleRange) -> bool {
        let r = &self.range;
        r.environment == range.environment
            && r.region == range.region
            && r.project_internal_id == range.project_internal_id
            && r.database_internal_id == range.database_internal_id
            && r.member == range.member
            && r.generation == range.generation
            && r.metric == range.metric
            && r.first_sequence == range.first_sequence
            && r.last_sequence == range.last_sequence
            && r.formatted_interval_start() == range.formatted_interval_start()
            && r.formatted_interval_end() == range.formatted_interval_end()
    }

    /// The captured entries.
    ///
    /// PHP: `getEntries()`.
    pub fn entries(&self) -> &[String] {
        &self.entries
    }

    /// Whether more entries existed than the limit allowed.
    ///
    /// PHP: `isTruncated()`.
    pub fn is_truncated(&self) -> bool {
        self.truncated
    }
}

/// The samples of a range read under a watermark, with the evidence that
/// decides whether they are complete.
///
/// PHP: `Utopia\Usage\SampleResult`.
#[derive(Debug, Clone)]
pub struct SampleResult {
    samples: Vec<Sample>,
    conflicts: Vec<i64>,
    gaps: Vec<SampleGap>,
    discontinuities: Vec<i64>,
    duplicate_count: i64,
    truncated: bool,
    watermark: SampleWatermark,
}

impl SampleResult {
    /// A result.
    ///
    /// PHP: `new SampleResult(...)`.
    pub fn new(
        samples: Vec<Sample>,
        conflicts: Vec<i64>,
        gaps: Vec<SampleGap>,
        discontinuities: Vec<i64>,
        duplicate_count: i64,
        truncated: bool,
        watermark: SampleWatermark,
    ) -> SampleResult {
        SampleResult { samples, conflicts, gaps, discontinuities, duplicate_count, truncated, watermark }
    }

    /// The consistent samples, by sequence.
    ///
    /// PHP: `getSamples()`.
    pub fn samples(&self) -> &[Sample] {
        &self.samples
    }

    /// Sequences observed with conflicting payloads (or out of range).
    ///
    /// PHP: `getConflicts()`.
    pub fn conflicts(&self) -> &[i64] {
        &self.conflicts
    }

    /// Missing sequence runs.
    ///
    /// PHP: `getGaps()`.
    pub fn gaps(&self) -> &[SampleGap] {
        &self.gaps
    }

    /// Sequences whose interval does not continue the previous one.
    ///
    /// PHP: `getDiscontinuities()`.
    pub fn discontinuities(&self) -> &[i64] {
        &self.discontinuities
    }

    /// Repeated copies of identical observations.
    ///
    /// PHP: `getDuplicateCount()`.
    pub fn duplicate_count(&self) -> i64 {
        self.duplicate_count
    }

    /// Whether more samples existed than the limit allowed.
    ///
    /// PHP: `isTruncated()`.
    pub fn is_truncated(&self) -> bool {
        self.truncated
    }

    /// The watermark the read was bound to.
    ///
    /// PHP: `getWatermark()`.
    pub fn watermark(&self) -> &SampleWatermark {
        &self.watermark
    }

    /// Whether nothing is missing, conflicting or cut off.
    ///
    /// PHP: `isComplete()`.
    pub fn is_complete(&self) -> bool {
        !self.truncated
            && !self.watermark.is_truncated()
            && self.conflicts.is_empty()
            && self.gaps.is_empty()
            && self.discontinuities.is_empty()
    }
}
