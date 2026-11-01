//! The MongoDB builder of the query adapter: `Builder\MongoDB`, its enums
//! and `Builder\MongoDB\UpdateOperation`.

use serde_json::Value as Json;
use utopia_query::builder::{
    AnyBuilder, IndexHint, MongoDb, MongoDbDialect, Operation, PipelineStage, UpdateOperation, UpdateOperator, feature,
};
use utopia_query::{Array, Value};

use super::builder::{any_builder, base_call, full_text_search, insert_or_ignore, rest_values, table_sampling, upsert};
use super::engine::{Args, Failure, Object, Outcome, Ret, fault};
use crate::query_object_boilerplate;

/// `new Builder\MongoDB()`, `new Builder\MongoDB\UpdateOperation(...)`.
pub(crate) fn construct(class: &str, a: &mut Args) -> Option<Outcome> {
    Some(match class {
        "Builder\\MongoDB" => Ok(Ret::obj(MongoDb::new())),
        "Builder\\MongoDB\\UpdateOperation" => (|| {
            let operator = match a.enum_case(0, "operator")? {
                Some((_, case)) => {
                    UpdateOperator::from_name(&case).ok_or_else(|| fault(format!("no UpdateOperator::{case}")))?
                }
                None => return Err(fault("missing operator")),
            };
            Ok(Ret::obj(UpdateOperation::new(operator, a.array(1, "fields")?)))
        })(),
        _ => return None,
    })
}

/// `Builder\MongoDB\{Operation,PipelineStage,UpdateOperator}::Case->value`.
pub(crate) fn enum_call(class: &str, case: &str, method: &str, _a: &mut Args) -> Option<Outcome> {
    if method != "->value" {
        return None;
    }
    let value = match class {
        "Builder\\MongoDB\\Operation" => Operation::from_name(case)?.as_str(),
        "Builder\\MongoDB\\PipelineStage" => PipelineStage::from_name(case)?.as_str(),
        "Builder\\MongoDB\\UpdateOperator" => UpdateOperator::from_name(case)?.as_str(),
        _ => return None,
    };
    Some(Ok(Ret::str(value)))
}

/// `class_implements(new MongoDB())`.
const MONGODB_INTERFACES: &[&str] = &[
    "Builder\\Feature\\Aggregates",
    "Builder\\Feature\\CTEs",
    "Builder\\Feature\\Deletes",
    "Builder\\Feature\\FullTextSearch",
    "Builder\\Feature\\Hooks",
    "Builder\\Feature\\InsertOrIgnore",
    "Builder\\Feature\\Inserts",
    "Builder\\Feature\\Joins",
    "Builder\\Feature\\MongoDB\\ArrayPushModifiers",
    "Builder\\Feature\\MongoDB\\AtlasSearch",
    "Builder\\Feature\\MongoDB\\ConditionalArrayUpdates",
    "Builder\\Feature\\MongoDB\\FieldUpdates",
    "Builder\\Feature\\MongoDB\\PipelineStages",
    "Builder\\Feature\\Selects",
    "Builder\\Feature\\TableSampling",
    "Builder\\Feature\\Unions",
    "Builder\\Feature\\Updates",
    "Builder\\Feature\\Upsert",
    "Builder\\Feature\\Windows",
    "Compiler",
];

/// Compile-time check that the Rust dialect has every shared feature PHP's class implements.
#[allow(dead_code)]
fn mongodb_features<D: feature::FullTextSearch + feature::InsertOrIgnore + feature::TableSampling + feature::Upsert>() {
}

const _: fn() = mongodb_features::<MongoDbDialect>;

fn opt_array(a: &Args, index: usize, name: &str) -> Result<Option<Array>, Failure> {
    a.opt_array(index, name)
}

fn to_string(v: &Value) -> String {
    v.to_php_string().map(|s| String::from_utf8_lossy(s.as_bytes()).into_owned()).unwrap_or_default()
}

fn mongodb_call(b: &mut MongoDb, method: &str, a: &mut Args) -> Option<Outcome> {
    Some((|| -> Outcome {
        match method {
            "push" => {
                b.push(&a.str(0, "field")?, a.value(1, "value")?)?;
            }
            "pull" => {
                b.pull(&a.str(0, "field")?, a.value(1, "value")?)?;
            }
            "addToSet" => {
                b.add_to_set(&a.str(0, "field")?, a.value(1, "value")?)?;
            }
            "increment" => {
                let amount = if a.has(1, "amount") { a.value(1, "amount")? } else { Value::Int(1) };
                b.increment(&a.str(0, "field")?, amount)?;
            }
            "unsetFields" => {
                let fields: Vec<String> = rest_values(a, 0)?.iter().map(to_string).collect();
                b.unset_fields(fields)?;
            }
            "hint" => {
                let hint = match a.raw(0, "hint") {
                    Some(Json::String(_)) => IndexHint::Name(a.str(0, "hint")?),
                    _ => IndexHint::Keys(a.array(0, "hint")?),
                };
                b.hint(hint);
            }
            "rename" => {
                b.rename(&a.str(0, "oldField")?, &a.str(1, "newField")?)?;
            }
            "multiply" => {
                b.multiply(&a.str(0, "field")?, a.value(1, "factor")?)?;
            }
            "popFirst" => {
                b.pop_first(&a.str(0, "field")?)?;
            }
            "popLast" => {
                b.pop_last(&a.str(0, "field")?)?;
            }
            "pullAll" => {
                b.pull_all(&a.str(0, "field")?, a.array(1, "values")?)?;
            }
            "updateMin" => {
                b.update_min(&a.str(0, "field")?, a.value(1, "value")?)?;
            }
            "updateMax" => {
                b.update_max(&a.str(0, "field")?, a.value(1, "value")?)?;
            }
            "currentDate" => {
                b.current_date(&a.str(0, "field")?, &a.str_or(1, "type", "date")?)?;
            }
            "pushEach" => {
                b.push_each(
                    &a.str(0, "field")?,
                    a.array(1, "values")?,
                    a.opt_int(2, "position")?,
                    a.opt_int(3, "slice")?,
                    opt_array(a, 4, "sort")?,
                )?;
            }
            "arrayFilter" => {
                b.array_filter(&a.str(0, "identifier")?, a.array(1, "condition")?);
            }
            "bucket" => {
                b.bucket(
                    &a.str(0, "groupBy")?,
                    a.array(1, "boundaries")?,
                    a.opt_str(2, "defaultBucket")?.as_deref(),
                    a.array_or_empty(3, "output")?,
                );
            }
            "bucketAuto" => {
                b.bucket_auto(&a.str(0, "groupBy")?, a.int(1, "buckets")?, a.array_or_empty(2, "output")?);
            }
            "facet" => {
                let mut facets: Vec<(String, Box<dyn AnyBuilder>)> = Vec::new();
                for (key, raw) in a.entries(0, "facets")? {
                    let id = raw.get("$o").and_then(Json::as_i64).ok_or_else(|| fault("facet is not a builder"))?;
                    let builder = a.with_id(id, |o| any_builder(o))?.ok_or_else(|| fault("facet is not a builder"))?;
                    let name = match key {
                        php_std::ArrayKey::Int(i) => i.to_string(),
                        php_std::ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
                    };
                    facets.push((name, builder));
                }
                let mut items: Vec<(&str, &mut dyn AnyBuilder)> = Vec::with_capacity(facets.len());
                for (name, builder) in facets.iter_mut() {
                    items.push((name.as_str(), builder.as_mut()));
                }
                b.facet(items)?;
            }
            "graphLookup" => {
                b.graph_lookup(
                    &a.str(0, "from")?,
                    &a.str(1, "startWith")?,
                    &a.str(2, "connectFromField")?,
                    &a.str(3, "connectToField")?,
                    &a.str(4, "as")?,
                    a.opt_int(5, "maxDepth")?,
                    a.opt_str(6, "depthField")?.as_deref(),
                );
            }
            "mergeIntoCollection" => {
                b.merge_into_collection(
                    &a.str(0, "collection")?,
                    opt_array(a, 1, "on")?,
                    opt_array(a, 2, "whenMatched")?,
                    opt_array(a, 3, "whenNotMatched")?,
                );
            }
            "outputToCollection" => {
                b.output_to_collection(&a.str(0, "collection")?, a.opt_str(1, "database")?.as_deref());
            }
            "replaceRoot" => {
                b.replace_root(&a.str(0, "newRootExpression")?);
            }
            "search" => {
                b.search(a.array(0, "searchDefinition")?, a.opt_str(1, "index")?.as_deref());
            }
            "searchMeta" => {
                b.search_meta(a.array(0, "searchDefinition")?, a.opt_str(1, "index")?.as_deref());
            }
            "vectorSearch" => {
                b.vector_search(
                    &a.str(0, "path")?,
                    a.array(1, "queryVector")?,
                    a.int(2, "numCandidates")?,
                    a.int(3, "limit")?,
                    a.opt_str(4, "index")?.as_deref(),
                    opt_array(a, 5, "filter")?,
                );
            }
            _ => return Err(fault("not a MongoDB method")),
        }
        Ok(Ret::This)
    })())
    .filter(|r| !matches!(r, Err(Failure::Fault(f)) if f == "not a MongoDB method"))
}

impl Object for MongoDb {
    fn class(&self) -> String {
        "Builder\\MongoDB".into()
    }
    fn call(&mut self, method: &str, a: &mut Args) -> Option<Outcome> {
        mongodb_call(self, method, a)
            .or_else(|| base_call(self, method, a))
            .or_else(|| full_text_search(self, method, a))
            .or_else(|| table_sampling(self, method, a))
            .or_else(|| upsert(self, method, a))
            .or_else(|| insert_or_ignore(self, method, a))
    }
    fn parents(&self) -> &'static [&'static str] {
        &["Builder"]
    }
    fn interfaces(&self) -> &'static [&'static str] {
        MONGODB_INTERFACES
    }
    query_object_boilerplate!();
}

impl Object for UpdateOperation {
    fn class(&self) -> String {
        "Builder\\MongoDB\\UpdateOperation".into()
    }
    fn is_value(&self) -> bool {
        true
    }
    fn props(&self) -> Vec<(&'static str, Ret)> {
        vec![
            ("operator", Ret::Enum("Builder\\MongoDB\\UpdateOperator".into(), self.operator.name())),
            ("fields", Ret::Value(Value::Array(self.fields.clone()))),
        ]
    }
    fn call(&mut self, _method: &str, _a: &mut Args) -> Option<Outcome> {
        None
    }
    query_object_boilerplate!();
}
