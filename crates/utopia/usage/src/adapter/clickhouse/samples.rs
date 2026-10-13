//! Canonical samples: the immutable ledger, watermarks and evidence reads.

use php_std::ArrayKey;
use php_std::datetime::TzInfo;
use php_std::json::{self, Flags};
use utopia_query::{Array, Value};

use super::{ClickHouse, SAMPLE_BATCH_SIZE, decode_rows, escape, field};
use crate::error::{Error, Result};
use crate::php;
use crate::sample::{Sample, SampleGap, SampleRange, SampleResult, SampleWatermark};

const COLUMNS: &[&str] = &[
    "id",
    "payloadHash",
    "ingestId",
    "environment",
    "region",
    "projectInternalId",
    "databaseInternalId",
    "member",
    "generation",
    "sequence",
    "metric",
    "intervalStart",
    "intervalEnd",
    "value",
    "eventVersion",
];

fn range_params(range: &SampleRange, limit: i64) -> Vec<(String, Value)> {
    vec![
        ("environment".into(), Value::from(range.environment.as_str())),
        ("region".into(), Value::from(range.region.as_str())),
        ("projectInternalId".into(), Value::from(range.project_internal_id.as_str())),
        ("databaseInternalId".into(), Value::from(range.database_internal_id.as_str())),
        ("member".into(), Value::from(range.member.as_str())),
        ("generation".into(), Value::from(range.generation.as_str())),
        ("metric".into(), Value::from(range.metric.as_str())),
        ("firstSequence".into(), Value::Int(range.first_sequence)),
        ("lastSequence".into(), Value::Int(range.last_sequence)),
        ("queryLimit".into(), Value::Int(limit + 1)),
    ]
}

fn text(value: &Value) -> String {
    String::from_utf8_lossy(php::to_str(value).as_bytes()).into_owned()
}

fn gaps(samples: &[Sample], first: i64, last: i64) -> Result<Vec<SampleGap>> {
    let mut gaps = Vec::new();
    let mut expected = first;
    for sample in samples {
        if sample.sequence > expected {
            gaps.push(SampleGap::new(expected, sample.sequence - 1)?);
        }
        expected = sample.sequence + 1;
    }
    if expected <= last {
        gaps.push(SampleGap::new(expected, last)?);
    }
    Ok(gaps)
}

fn discontinuities(samples: &[Sample], range: &SampleRange) -> Vec<i64> {
    let mut out: Vec<i64> = Vec::new();
    let mut push = |s: i64| {
        if !out.contains(&s) {
            out.push(s);
        }
    };
    let mut expected = &range.interval_start;
    for sample in samples {
        if sample.interval_start != *expected {
            push(sample.sequence);
        }
        expected = &sample.interval_end;
    }
    if let Some(last) = samples.last()
        && *expected != range.interval_end
    {
        push(last.sequence);
    }
    out
}

impl<A: utopia_client::Adapter> ClickHouse<A> {
    pub(super) async fn insert_samples(&self, samples: &[Sample], batch_size: i64) -> Result<bool> {
        if samples.is_empty() {
            return Ok(true);
        }
        self.set_context("addSamples()");
        let batch_size = batch_size.clamp(1, SAMPLE_BATCH_SIZE) as usize;
        let table = self.samples_table();
        let columns = COLUMNS.iter().map(|c| escape(c)).collect::<Vec<_>>().join(", ");
        let sql = format!("INSERT INTO {} ({columns}) FORMAT JSONEachRow", self.reference(&table));
        for batch in samples.chunks(batch_size) {
            let mut rows = Vec::with_capacity(batch.len());
            for sample in batch {
                let mut row = Array::default();
                let mut set = |k: &str, v: Value| {
                    row.set(ArrayKey::from(k), v);
                };
                set("id", Value::from(sample.id().as_str()));
                set("payloadHash", Value::from(sample.payload_hash().as_str()));
                set("ingestId", Value::from(php::random_hex(16).as_str()));
                set("environment", Value::from(sample.environment.as_str()));
                set("region", Value::from(sample.region.as_str()));
                set("projectInternalId", Value::from(sample.project_internal_id.as_str()));
                set("databaseInternalId", Value::from(sample.database_internal_id.as_str()));
                set("member", Value::from(sample.member.as_str()));
                set("generation", Value::from(sample.generation.as_str()));
                set("sequence", Value::Int(sample.sequence));
                set("metric", Value::from(sample.metric.as_str()));
                set("intervalStart", Value::from(sample.formatted_interval_start().as_str()));
                set("intervalEnd", Value::from(sample.formatted_interval_end().as_str()));
                set("value", Value::Int(sample.value));
                set("eventVersion", Value::Int(sample.event_version));
                rows.push(
                    json::encode(&Value::Array(row), Flags::THROW_ON_ERROR, json::DEFAULT_DEPTH)
                        .map_err(|e| Error::Json(e.message().into()))?,
                );
            }
            self.insert(&table, &sql, &rows, true).await?;
        }
        Ok(true)
    }

    pub(super) async fn watermark(&self, range: &SampleRange, limit: i64) -> Result<SampleWatermark> {
        if limit < 1 || limit == i64::MAX {
            return Err(Error::invalid_argument(
                "Sample watermark limit must be positive and leave room for truncation detection",
            ));
        }
        self.set_context("getSampleWatermark()");
        let table = self.reference(&self.samples_table());
        let sql = format!(
            "SELECT concat(ingestId, ':', id, ':', payloadHash) AS entryId\n\
FROM {table}\n\
WHERE environment = {{environment:String}}\n  \
AND region = {{region:String}}\n  \
AND projectInternalId = {{projectInternalId:String}}\n  \
AND databaseInternalId = {{databaseInternalId:String}}\n  \
AND member = {{member:String}}\n  \
AND generation = {{generation:String}}\n  \
AND metric = {{metric:String}}\n  \
AND sequence >= {{firstSequence:UInt64}}\n  \
AND sequence <= {{lastSequence:UInt64}}\n  \
AND ingestId != ''\n\
LIMIT 1 BY entryId\n\
LIMIT {{queryLimit:UInt64}}\n\
FORMAT JSON"
        );
        let mut rows = decode_rows(&self.query(&sql, &range_params(range, limit)).await?);
        let truncated = rows.len() as i64 > limit;
        if truncated {
            rows.truncate(limit as usize);
        }
        let entries = rows.iter().map(|r| text(field(r, "entryId"))).collect();
        SampleWatermark::new(range.clone(), entries, truncated)
    }

    pub(super) async fn read_samples(
        &self,
        range: &SampleRange,
        watermark: &SampleWatermark,
        limit: i64,
    ) -> Result<SampleResult> {
        if limit < 1 || limit == i64::MAX {
            return Err(Error::invalid_argument(
                "Sample limit must be positive and leave room for truncation detection",
            ));
        }
        if !watermark.matches(range) {
            return Err(Error::invalid_argument("Sample watermark does not match the requested range"));
        }
        self.set_context("findSamples()");
        let table = self.reference(&self.samples_table());
        let sql = format!(
            "SELECT\n    \
environment,\n    \
region,\n    \
projectInternalId,\n    \
databaseInternalId,\n    \
member,\n    \
generation,\n    \
sequence,\n    \
metric,\n    \
argMin(\n        \
tuple(intervalStart, intervalEnd, value, eventVersion, payloadHash),\n        \
tuple(\n            \
payloadHash,\n            \
intervalStart,\n            \
intervalEnd,\n            \
value,\n            \
eventVersion,\n            \
concat(ingestId, ':', id, ':', payloadHash)\n        \
)\n    \
) AS observation,\n    \
uniqExact(concat(ingestId, ':', id, ':', payloadHash)) AS copies,\n    \
uniqExact(tuple(payloadHash, intervalStart, intervalEnd, value, eventVersion)) AS variants\n\
FROM {table}\n\
WHERE environment = {{environment:String}}\n  \
AND region = {{region:String}}\n  \
AND projectInternalId = {{projectInternalId:String}}\n  \
AND databaseInternalId = {{databaseInternalId:String}}\n  \
AND member = {{member:String}}\n  \
AND generation = {{generation:String}}\n  \
AND metric = {{metric:String}}\n  \
AND sequence >= {{firstSequence:UInt64}}\n  \
AND sequence <= {{lastSequence:UInt64}}\n  \
AND has({{entries:Array(String)}}, concat(ingestId, ':', id, ':', payloadHash))\n\
GROUP BY\n    \
environment,\n    \
region,\n    \
projectInternalId,\n    \
databaseInternalId,\n    \
member,\n    \
generation,\n    \
sequence,\n    \
metric\n\
ORDER BY sequence ASC\n\
LIMIT {{queryLimit:UInt64}}\n\
FORMAT JSON"
        );
        let mut params = range_params(range, limit);
        let entries = if watermark.entries().is_empty() {
            "[]".to_owned()
        } else {
            format!("['{}']", watermark.entries().join("','"))
        };
        params.insert(params.len() - 1, ("entries".into(), Value::from(entries.as_str())));

        let mut rows = decode_rows(&self.query(&sql, &params).await?);
        let truncated = rows.len() as i64 > limit;
        if truncated {
            rows.truncate(limit as usize);
        }

        let mut samples: Vec<Sample> = Vec::new();
        let mut conflicts: Vec<i64> = Vec::new();
        let mut duplicates = 0i64;
        let utc = php_std::datetime::TimeZone::utc();
        for row in rows {
            let sequence = php::to_int(field(&row, "sequence"));
            let copies = php::to_int(field(&row, "copies"));
            let variants = php::to_int(field(&row, "variants"));
            duplicates += (copies - variants.max(1)).max(0);
            if variants != 1 {
                conflicts.push(sequence);
                continue;
            }
            let observation = match field(&row, "observation") {
                Value::Array(a) if a.len() == 5 => a.clone(),
                _ => {
                    conflicts.push(sequence);
                    continue;
                }
            };
            let part = |i: i64| observation.get(&ArrayKey::Int(i)).cloned().unwrap_or(Value::Null);
            let start = php_std::datetime::DateTime::new(php::to_str(&part(0)).as_bytes(), Some(&utc), &TzInfo::utc())?;
            let end = php_std::datetime::DateTime::new(php::to_str(&part(1)).as_bytes(), Some(&utc), &TzInfo::utc())?;
            let sample = match Sample::new(
                &text(field(&row, "environment")),
                &text(field(&row, "region")),
                &text(field(&row, "projectInternalId")),
                &text(field(&row, "databaseInternalId")),
                &text(field(&row, "member")),
                &text(field(&row, "generation")),
                sequence,
                &text(field(&row, "metric")),
                start,
                end,
                php::to_int(&part(2)),
                php::to_int(&part(3)),
            ) {
                Ok(sample) => sample,
                Err(Error::InvalidArgument(_)) => {
                    conflicts.push(sequence);
                    continue;
                }
                Err(e) => return Err(e),
            };
            if sample.payload_hash() != text(&part(4))
                || sample.interval_start < range.interval_start
                || sample.interval_end > range.interval_end
            {
                conflicts.push(sequence);
                continue;
            }
            samples.push(sample);
        }

        let gaps = gaps(&samples, range.first_sequence, range.last_sequence)?;
        let discontinuities = discontinuities(&samples, range);
        let mut unique: Vec<i64> = Vec::with_capacity(conflicts.len());
        for c in conflicts {
            if !unique.contains(&c) {
                unique.push(c);
            }
        }
        Ok(SampleResult::new(samples, unique, gaps, discontinuities, duplicates, truncated, watermark.clone()))
    }
}
