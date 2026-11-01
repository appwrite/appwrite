//! MongoDB: [`MongoDb`], a builder that compiles to MongoDB operation
//! documents (JSON) instead of SQL.
//!
//! A select compiles to a `find` (filter, projection, sort, skip, limit) or,
//! when it needs stages a `find` cannot express (grouping, joins, window
//! functions, unions, subqueries, search, pipeline stages), to an
//! `aggregate` pipeline. Writes compile to `insertMany`, `updateMany`,
//! `updateOne` (upserts) and `deleteMany`. Values are bound as `?` strings
//! in the document, in order, as with the SQL builders.
//!
//! | PHP | Rust |
//! |---|---|
//! | `Builder\MongoDB` | [`MongoDb`] ([`MongoDbDialect`]) |
//! | `Builder\MongoDB\Operation` | [`Operation`] |
//! | `Builder\MongoDB\PipelineStage` | [`PipelineStage`] |
//! | `Builder\MongoDB\UpdateOperator` | [`UpdateOperator`] |
//! | `Builder\MongoDB\UpdateOperation` | [`UpdateOperation`] |
//! | `hint(string\|array)` | [`MongoDb::hint`] with an [`IndexHint`] |
//! | `push()`, `pull()`, `addToSet()`, `increment()`, `unsetFields()` | inherent methods of [`MongoDb`] |
//! | `Feature\MongoDB\FieldUpdates` | [`MongoDb::rename`], [`MongoDb::multiply`], [`MongoDb::pop_first`], [`MongoDb::pop_last`], [`MongoDb::pull_all`], [`MongoDb::update_min`], [`MongoDb::update_max`], [`MongoDb::current_date`] |
//! | `Feature\MongoDB\ArrayPushModifiers` | [`MongoDb::push_each`] |
//! | `Feature\MongoDB\ConditionalArrayUpdates` | [`MongoDb::array_filter`] |
//! | `Feature\MongoDB\PipelineStages` | [`MongoDb::bucket`], [`MongoDb::bucket_auto`], [`MongoDb::facet`], [`MongoDb::graph_lookup`], [`MongoDb::merge_into_collection`], [`MongoDb::output_to_collection`], [`MongoDb::replace_root`] |
//! | `Feature\MongoDB\AtlasSearch` | [`MongoDb::search`], [`MongoDb::search_meta`], [`MongoDb::vector_search`] |
//! | `Feature\Upsert`, `Feature\InsertOrIgnore`, `Feature\FullTextSearch`, `Feature\TableSampling` | the [`feature`](crate::builder::feature) traits |

use php_std::ArrayKey;
use php_std::json::{self, Flags};

use crate::builder::AnyBuilder;
use crate::builder::core::{Builder, Dialect, first, is_empty_string, on_values};
use crate::builder::feature::{FullTextSearch, InsertOrIgnore, TableSampling, Upsert};
use crate::builder::types::{Statement, WindowSelect};
use crate::error::{Error, Result};
use crate::hook::Hook;
use crate::method::{Method, backed_enum};
use crate::query::{ParsedQuery, Query};
use crate::value::{self, Array, IntoValue, IntoValues, Object, Param, Value};

backed_enum! {
    /// The operation a compiled document runs.
    ///
    /// PHP: `Utopia\Query\Builder\MongoDB\Operation`.
    pub enum Operation: "Utopia\\Query\\Builder\\MongoDB\\Operation" {
        /// `insertMany`.
        InsertMany = "insertMany",
        /// `updateMany`.
        UpdateMany = "updateMany",
        /// `deleteMany`.
        DeleteMany = "deleteMany",
        /// `updateOne` (upserts).
        UpdateOne = "updateOne",
        /// `find`.
        Find = "find",
        /// `aggregate`.
        Aggregate = "aggregate",
    }
}

backed_enum! {
    /// An aggregation pipeline stage (and the `$text` operator).
    ///
    /// PHP: `Utopia\Query\Builder\MongoDB\PipelineStage`.
    pub enum PipelineStage: "Utopia\\Query\\Builder\\MongoDB\\PipelineStage" {
        /// `$match`.
        Match = "$match",
        /// `$group`.
        Group = "$group",
        /// `$project`.
        Project = "$project",
        /// `$sort`.
        Sort = "$sort",
        /// `$skip`.
        Skip = "$skip",
        /// `$limit`.
        Limit = "$limit",
        /// `$lookup`.
        Lookup = "$lookup",
        /// `$graphLookup`.
        GraphLookup = "$graphLookup",
        /// `$facet`.
        Facet = "$facet",
        /// `$bucket`.
        Bucket = "$bucket",
        /// `$bucketAuto`.
        BucketAuto = "$bucketAuto",
        /// `$setWindowFields`.
        SetWindowFields = "$setWindowFields",
        /// `$search` (Atlas).
        Search = "$search",
        /// `$searchMeta` (Atlas).
        SearchMeta = "$searchMeta",
        /// `$vectorSearch` (Atlas).
        VectorSearch = "$vectorSearch",
        /// `$sample`.
        Sample = "$sample",
        /// `$text`.
        Text = "$text",
        /// `$unwind`.
        Unwind = "$unwind",
        /// `$addFields`.
        AddFields = "$addFields",
        /// `$replaceRoot`.
        ReplaceRoot = "$replaceRoot",
        /// `$unionWith`.
        UnionWith = "$unionWith",
        /// `$unset`.
        Unset = "$unset",
        /// `$merge`.
        Merge = "$merge",
        /// `$out`.
        Out = "$out",
    }
}

backed_enum! {
    /// An update operator.
    ///
    /// PHP: `Utopia\Query\Builder\MongoDB\UpdateOperator`.
    pub enum UpdateOperator: "Utopia\\Query\\Builder\\MongoDB\\UpdateOperator" {
        /// `$set`.
        Set = "$set",
        /// `$push`.
        Push = "$push",
        /// `$pull`.
        Pull = "$pull",
        /// `$pullAll`.
        PullAll = "$pullAll",
        /// `$addToSet`.
        AddToSet = "$addToSet",
        /// `$inc`.
        Increment = "$inc",
        /// `$mul`.
        Multiply = "$mul",
        /// `$unset`.
        Unset = "$unset",
        /// `$rename`.
        Rename = "$rename",
        /// `$pop`.
        Pop = "$pop",
        /// `$min`.
        Min = "$min",
        /// `$max`.
        Max = "$max",
        /// `$currentDate`.
        CurrentDate = "$currentDate",
    }
}

/// An update operator with its fields (field => value).
///
/// PHP: `Utopia\Query\Builder\MongoDB\UpdateOperation`.
#[derive(Debug, Clone, PartialEq)]
pub struct UpdateOperation {
    /// The operator.
    pub operator: UpdateOperator,
    /// Field => value (or modifier document).
    pub fields: Array,
}

impl UpdateOperation {
    /// An operator with its fields.
    ///
    /// PHP: `new UpdateOperation($operator, $fields)`.
    pub fn new(operator: UpdateOperator, fields: Array) -> Self {
        UpdateOperation { operator, fields }
    }
}

/// The index a query must use: a name, or a key pattern (`{"name": 1}`).
///
/// PHP: the `string|array` of `MongoDB::hint()`.
#[derive(Debug, Clone, PartialEq)]
pub enum IndexHint {
    /// An index name.
    Name(String),
    /// A key pattern: field => direction.
    Keys(Array),
}

impl From<&str> for IndexHint {
    /// [`IndexHint::Name`].
    fn from(name: &str) -> Self {
        IndexHint::Name(name.to_owned())
    }
}

impl From<String> for IndexHint {
    /// [`IndexHint::Name`].
    fn from(name: String) -> Self {
        IndexHint::Name(name)
    }
}

impl From<Array> for IndexHint {
    /// [`IndexHint::Keys`].
    fn from(keys: Array) -> Self {
        IndexHint::Keys(keys)
    }
}

impl IndexHint {
    fn to_value(&self) -> Value {
        match self {
            IndexHint::Name(name) => value::string(name),
            IndexHint::Keys(keys) => Value::Array(keys.clone()),
        }
    }
}

/// A compiled facet: its pipeline and the bindings of its sub-builder.
#[derive(Debug, Clone)]
struct Facet {
    name: ArrayKey,
    pipeline: Array,
    bindings: Vec<Value>,
}

/// A MongoDB builder: compiles to operation documents (JSON).
///
/// PHP: `Utopia\Query\Builder\MongoDB`.
///
/// ```
/// use utopia_query::Query;
/// use utopia_query::builder::MongoDb;
///
/// let statement = MongoDb::new()
///     .from("users", "")
///     .select(["name"])
///     .filter([Query::greater_than("age", 18)])
///     .sort_desc("age", None)
///     .limit(10)
///     .build()?;
/// assert_eq!(
///     statement.query,
///     r#"{"collection":"users","operation":"find","filter":{"age":{"$gt":"?"}},"projection":{"name":1,"_id":0},"sort":{"age":-1},"limit":10}"#
/// );
/// assert_eq!(statement.bindings, vec![18.into()]);
/// # Ok::<(), utopia_query::Error>(())
/// ```
///
/// ```php
/// use Utopia\Query\Builder\MongoDB;
/// use Utopia\Query\Query;
///
/// $statement = (new MongoDB())
///     ->from('users')
///     ->select(['name'])
///     ->filter([Query::greaterThan('age', 18)])
///     ->sortDesc('age')
///     ->limit(10)
///     ->build();
/// echo $statement->query, "\n";
/// // {"collection":"users","operation":"find","filter":{"age":{"$gt":"?"}},"projection":{"name":1,"_id":0},"sort":{"age":-1},"limit":10}
/// ```
pub type MongoDb = Builder<MongoDbDialect>;

/// The MongoDB dialect, and its own state: update operators, pipeline
/// stages, search stages and the index hint.
#[derive(Debug, Clone, Default)]
pub struct MongoDbDialect {
    update_operations: Vec<(UpdateOperator, Array)>,
    text_search: Option<String>,
    sample_size: Option<f64>,
    array_filters: Vec<Array>,
    bucket: Option<Array>,
    bucket_auto: Option<Array>,
    facets: Option<Vec<Facet>>,
    graph_lookup: Option<Array>,
    merge: Option<Array>,
    out: Option<(Option<String>, String)>,
    replace_root: Option<String>,
    search: Option<Array>,
    search_meta: Option<Array>,
    vector_search: Option<Array>,
    index_hint: Option<IndexHint>,
}

const FILTER_HOOKS_UNSUPPORTED: &str = "Filter hooks are not supported on the MongoDB builder: \
    Utopia\\Query\\Hook\\Filter returns a SQL expression, which has no place in an operation document. \
    Express the constraint as a Query passed to filter() instead.";

impl Dialect for MongoDbDialect {
    const CLASS: &'static str = "Utopia\\Query\\Builder\\MongoDB";

    /// Identifiers are field paths: no quoting.
    fn quote(_b: &Builder<Self>, identifier: &str) -> Result<String> {
        Ok(identifier.to_owned())
    }

    fn compile_random(_b: &Builder<Self>) -> String {
        "$rand".to_owned()
    }

    fn compile_regex(b: &mut Builder<Self>, attribute: &str, values: &Array, column: Option<&str>) -> Result<String> {
        b.add_binding(first(values), column);
        Ok(format!("{attribute} REGEX ?"))
    }

    fn build(b: &mut Builder<Self>) -> Result<Statement> {
        b.compile_select()
    }

    fn insert(b: &mut Builder<Self>) -> Result<Statement> {
        b.compile_insert(false)
    }

    fn update(b: &mut Builder<Self>) -> Result<Statement> {
        b.compile_update()
    }

    fn delete(b: &mut Builder<Self>) -> Result<Statement> {
        b.compile_delete()
    }

    fn reset(b: &mut Builder<Self>) {
        b.dialect = MongoDbDialect::default();
    }

    /// Filter and join-filter hooks return SQL, which an operation document
    /// cannot hold: they are refused rather than silently dropped.
    fn add_hook(b: &mut Builder<Self>, hook: Hook) -> Result<()> {
        if hook.filter.is_some() || hook.join_filter.is_some() {
            return Err(Error::Unsupported(FILTER_HOOKS_UNSUPPORTED.to_owned()));
        }
        b.base_add_hook(hook);
        Ok(())
    }

    /// Refuses empty and `$`-prefixed field names.
    fn set(b: &mut Builder<Self>, row: Array) -> Result<()> {
        for key in row.keys() {
            validate_field_name(&key_text(&key.to_owned()))?;
        }
        b.core.rows.push(row);
        Ok(())
    }
}

impl FullTextSearch for MongoDbDialect {
    /// A `$text` search on the collection's text index (the attribute is ignored).
    fn filter_search(b: &mut Builder<Self>, _attribute: &str, value: &str) -> Result<()> {
        b.dialect.text_search = Some(value.to_owned());
        Ok(())
    }
}

impl TableSampling for MongoDbDialect {
    /// A `$sample` stage of `ceil(percent)` documents (the method is ignored).
    fn tablesample(b: &mut Builder<Self>, percent: f64, _method: &str) -> Result<()> {
        b.dialect.sample_size = Some(percent);
        Ok(())
    }
}

impl Upsert for MongoDbDialect {
    fn upsert(b: &mut Builder<Self>) -> Result<Statement> {
        b.compile_upsert_one()
    }
}

impl InsertOrIgnore for MongoDbDialect {
    fn insert_or_ignore(b: &mut Builder<Self>) -> Result<Statement> {
        b.compile_insert(true)
    }
}

fn validate_field_name(field: &str) -> Result<()> {
    if field.is_empty() || field.starts_with('$') {
        return Err(Error::Validation(format!("Invalid MongoDB field name: {field}")));
    }
    Ok(())
}

/// An array key as the string PHP's `(string)` gives.
fn key_text(key: &ArrayKey) -> String {
    match key {
        ArrayKey::Int(i) => i.to_string(),
        ArrayKey::Str(s) => String::from_utf8_lossy(s.as_bytes()).into_owned(),
    }
}

fn key(name: &str) -> ArrayKey {
    ArrayKey::from(name)
}

/// A document of `entries` (keys as PHP array keys).
fn doc<const N: usize>(entries: [(&str, Value); N]) -> Array {
    let mut out = Array::with_capacity(N);
    for (k, v) in entries {
        out.set(key(k), v);
    }
    out
}

/// `{stage: body}`.
fn stage(stage: PipelineStage, body: impl Into<Value>) -> Value {
    Value::Array(doc([(stage.as_str(), body.into())]))
}

fn append(list: &mut Array, item: Value) {
    let _ = list.push(item);
}

fn placeholder() -> Value {
    value::string("?")
}

fn quoted(bytes: &[u8]) -> Vec<u8> {
    php_std::pcre::preg_quote(bytes, Some(b"/"))
}

/// The nested query a logical or having query holds.
fn sub_query(value: &Value) -> Result<&Query> {
    match value {
        Value::Ext(Object::Query(q)) => Ok(q),
        other => Err(Error::Type(format!(
            "Utopia\\Query\\Builder\\MongoDB::buildFilterQuery(): Argument #1 ($query) must be of type Utopia\\Query\\Query, {} given",
            other.debug_type()
        ))),
    }
}

/// `json_decode($json, true)`, `None` where PHP returns `null`.
fn decode(json: &str) -> Option<Value> {
    match json::decode_ext(json.as_bytes(), Some(true), json::DEFAULT_DEPTH, Flags::NONE).ok()? {
        Value::Null => None,
        other => Some(other),
    }
}

/// `json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)`.
fn encode(operation: Array) -> Result<String> {
    value::json_encode(&Value::Array(operation), Flags::THROW_ON_ERROR | Flags::UNESCAPED_SLASHES)
        .map_err(|e| Error::Json { message: e.message().to_owned(), code: e.code().code() })
}

/// `isset($array[$name])`.
fn isset<'a>(value: &'a Value, name: &str) -> Option<&'a Value> {
    match value {
        Value::Array(a) => a.get_str(name).filter(|v| !matches!(v, Value::Null)),
        _ => None,
    }
}

/// The pipeline of a decoded operation: an `aggregate`'s pipeline as is, a
/// `find`'s filter, projection, sort, skip and limit as stages.
fn operation_to_pipeline(op: &Value) -> Result<Array> {
    let Value::Array(op) = op else {
        return Err(Error::Type(format!(
            "Utopia\\Query\\Builder\\MongoDB::operationToPipeline(): Argument #1 ($op) must be of type array, {} given",
            op.debug_type()
        )));
    };
    let field = |name: &str| op.get_str(name).filter(|v| !matches!(v, Value::Null));
    if field("operation").is_some_and(|o| matches!(o, Value::Str(s) if s.as_bytes() == b"aggregate")) {
        return Ok(match field("pipeline") {
            Some(Value::Array(pipeline)) => pipeline.clone(),
            _ => Array::default(),
        });
    }
    let mut pipeline = Array::default();
    for (name, kind) in
        [("filter", PipelineStage::Match), ("projection", PipelineStage::Project), ("sort", PipelineStage::Sort)]
    {
        if let Some(v) = field(name).filter(|v| v.truthy()) {
            append(&mut pipeline, stage(kind, v.clone()));
        }
    }
    for (name, kind) in [("skip", PipelineStage::Skip), ("limit", PipelineStage::Limit)] {
        if let Some(v) = field(name) {
            append(&mut pipeline, stage(kind, v.clone()));
        }
    }
    Ok(pipeline)
}

/// The first field a pipeline's `$project` includes (`_id` when none).
fn extract_projection_field(pipeline: &Array) -> String {
    for stage in pipeline.values() {
        if let Some(Value::Array(projection)) = isset(stage, PipelineStage::Project.as_str()) {
            for (field, value) in projection.iter() {
                let field = key_text(&field.to_owned());
                if field != "_id" && matches!(value, Value::Int(1)) {
                    return field;
                }
            }
        }
    }
    "_id".to_owned()
}

/// The last segment of a dotted field (`users.id` -> `id`).
fn strip_table_prefix(field: &str) -> &str {
    field.rsplit('.').next().unwrap_or(field)
}

/// `/^(\w+)\((.+)\)$/`: the function name and its argument text.
fn split_call(function: &str) -> Option<(&str, &str)> {
    let text = function.strip_suffix('\n').unwrap_or(function);
    let name = text.bytes().take_while(|b| b.is_ascii_alphanumeric() || *b == b'_').count();
    if name == 0 || text.as_bytes().get(name) != Some(&b'(') || !text.ends_with(')') || text.len() < name + 3 {
        return None;
    }
    let inner = &text[name + 1..text.len() - 1];
    if inner.contains('\n') {
        return None;
    }
    Some((&text[..name], inner))
}

impl Builder<MongoDbDialect> {
    fn resolve_filter_field(&mut self, attribute: &str) -> Result<String> {
        let field = self.resolve_attribute(attribute)?;
        validate_field_name(&field)?;
        Ok(field)
    }

    fn set_update_field(&mut self, operator: UpdateOperator, field: &str, payload: Value) -> Result<&mut Self> {
        validate_field_name(field)?;
        let operations = &mut self.dialect.update_operations;
        let index = match operations.iter().position(|(o, _)| *o == operator) {
            Some(i) => i,
            None => {
                operations.push((operator, Array::default()));
                operations.len() - 1
            }
        };
        operations[index].1.set(key(field), payload);
        Ok(self)
    }

    /// Appends `value` to the array `field` (`$push`).
    ///
    /// PHP: `MongoDB::push()`.
    pub fn push(&mut self, field: &str, value: impl IntoValue) -> Result<&mut Self> {
        self.set_update_field(UpdateOperator::Push, field, value.into_value())
    }

    /// Removes `value` (or the elements matching a condition) from the array `field` (`$pull`).
    ///
    /// PHP: `MongoDB::pull()`.
    pub fn pull(&mut self, field: &str, value: impl IntoValue) -> Result<&mut Self> {
        self.set_update_field(UpdateOperator::Pull, field, value.into_value())
    }

    /// Adds `value` to the array `field` unless present (`$addToSet`).
    ///
    /// PHP: `MongoDB::addToSet()`.
    pub fn add_to_set(&mut self, field: &str, value: impl IntoValue) -> Result<&mut Self> {
        self.set_update_field(UpdateOperator::AddToSet, field, value.into_value())
    }

    /// Adds `amount` (an integer or a float) to `field` (`$inc`).
    ///
    /// PHP: `MongoDB::increment()`.
    pub fn increment(&mut self, field: &str, amount: impl IntoValue) -> Result<&mut Self> {
        self.set_update_field(UpdateOperator::Increment, field, amount.into_value())
    }

    /// Removes the fields from the documents (`$unset`).
    ///
    /// PHP: `MongoDB::unsetFields()`.
    pub fn unset_fields<S: AsRef<str>>(&mut self, fields: impl IntoIterator<Item = S>) -> Result<&mut Self> {
        for field in fields {
            self.set_update_field(UpdateOperator::Unset, field.as_ref(), value::string(""))?;
        }
        Ok(self)
    }

    /// The index the query uses: a name or a key pattern.
    ///
    /// PHP: `MongoDB::hint()`.
    pub fn hint(&mut self, hint: impl Into<IndexHint>) -> &mut Self {
        self.dialect.index_hint = Some(hint.into());
        self
    }

    /// Renames `old_field` to `new_field` (`$rename`).
    ///
    /// PHP: `rename()` (`Feature\MongoDB\FieldUpdates`).
    pub fn rename(&mut self, old_field: &str, new_field: &str) -> Result<&mut Self> {
        validate_field_name(old_field)?;
        validate_field_name(new_field)?;
        self.set_update_field(UpdateOperator::Rename, old_field, value::string(new_field))
    }

    /// Multiplies `field` by `factor` (`$mul`).
    ///
    /// PHP: `multiply()` (`Feature\MongoDB\FieldUpdates`).
    pub fn multiply(&mut self, field: &str, factor: impl IntoValue) -> Result<&mut Self> {
        self.set_update_field(UpdateOperator::Multiply, field, factor.into_value())
    }

    /// Removes the first element of the array `field` (`$pop: -1`).
    ///
    /// PHP: `popFirst()` (`Feature\MongoDB\FieldUpdates`).
    pub fn pop_first(&mut self, field: &str) -> Result<&mut Self> {
        self.set_update_field(UpdateOperator::Pop, field, Value::Int(-1))
    }

    /// Removes the last element of the array `field` (`$pop: 1`).
    ///
    /// PHP: `popLast()` (`Feature\MongoDB\FieldUpdates`).
    pub fn pop_last(&mut self, field: &str) -> Result<&mut Self> {
        self.set_update_field(UpdateOperator::Pop, field, Value::Int(1))
    }

    /// Removes every occurrence of `values` from the array `field` (`$pullAll`).
    ///
    /// PHP: `pullAll()` (`Feature\MongoDB\FieldUpdates`).
    pub fn pull_all(&mut self, field: &str, values: impl IntoValues) -> Result<&mut Self> {
        self.set_update_field(UpdateOperator::PullAll, field, Value::Array(values.into_values()))
    }

    /// Sets `field` to `value` when `value` is smaller (`$min`).
    ///
    /// PHP: `updateMin()` (`Feature\MongoDB\FieldUpdates`).
    pub fn update_min(&mut self, field: &str, value: impl IntoValue) -> Result<&mut Self> {
        self.set_update_field(UpdateOperator::Min, field, value.into_value())
    }

    /// Sets `field` to `value` when `value` is larger (`$max`).
    ///
    /// PHP: `updateMax()` (`Feature\MongoDB\FieldUpdates`).
    pub fn update_max(&mut self, field: &str, value: impl IntoValue) -> Result<&mut Self> {
        self.set_update_field(UpdateOperator::Max, field, value.into_value())
    }

    /// Sets `field` to the current date (`type` is `date` or `timestamp`) (`$currentDate`).
    ///
    /// PHP: `currentDate()` (`Feature\MongoDB\FieldUpdates`).
    pub fn current_date(&mut self, field: &str, date_type: &str) -> Result<&mut Self> {
        self.set_update_field(
            UpdateOperator::CurrentDate,
            field,
            Value::Array(doc([("$type", value::string(date_type))])),
        )
    }

    /// Appends each of `values` to the array `field`, at `position`, keeping
    /// `slice` elements, sorted by `sort` (`$push` with `$each`).
    ///
    /// PHP: `pushEach()` (`Feature\MongoDB\ArrayPushModifiers`).
    ///
    /// ```
    /// use utopia_query::Query;
    /// use utopia_query::builder::MongoDb;
    ///
    /// let statement = MongoDb::new()
    ///     .from("users", "")
    ///     .filter([Query::equal("_id", ["u1"])])
    ///     .push_each("scores", [90, 85], None, Some(-5), None)?
    ///     .increment("visits", 1)?
    ///     .update()?;
    /// assert_eq!(
    ///     statement.query,
    ///     r#"{"collection":"users","operation":"updateMany","filter":{"_id":"?"},"update":{"$push":{"scores":{"$each":["?","?"],"$slice":-5}},"$inc":{"visits":1}}}"#
    /// );
    /// assert_eq!(statement.bindings.len(), 3);
    /// # Ok::<(), utopia_query::Error>(())
    /// ```
    ///
    /// ```php
    /// use Utopia\Query\Builder\MongoDB;
    /// use Utopia\Query\Query;
    ///
    /// $statement = (new MongoDB())
    ///     ->from('users')
    ///     ->filter([Query::equal('_id', ['u1'])])
    ///     ->pushEach('scores', [90, 85], slice: -5)
    ///     ->increment('visits', 1)
    ///     ->update();
    /// echo $statement->query, "\n";
    /// // {"collection":"users","operation":"updateMany","filter":{"_id":"?"},"update":{"$push":{"scores":{"$each":["?","?"],"$slice":-5}},"$inc":{"visits":1}}}
    /// ```
    pub fn push_each(
        &mut self,
        field: &str,
        values: impl IntoValues,
        position: Option<i64>,
        slice: Option<i64>,
        sort: Option<Array>,
    ) -> Result<&mut Self> {
        let values = Array::from_list(values.into_values().values().cloned().collect());
        let mut modifier = doc([("values", Value::Array(values))]);
        if let Some(position) = position {
            modifier.set(key("position"), Value::Int(position));
        }
        if let Some(slice) = slice {
            modifier.set(key("slice"), Value::Int(slice));
        }
        if let Some(sort) = sort {
            modifier.set(key("sort"), Value::Array(sort));
        }
        // Wrapped in an `__each` marker, as PHP stores it: a plain push value
        // shaped like the marker compiles the same way.
        self.set_update_field(UpdateOperator::Push, field, Value::Array(doc([("__each", Value::Array(modifier))])))
    }

    /// Adds an `arrayFilters` condition for a filtered positional update
    /// (`field.$[identifier]`); the identifier is informational and must be
    /// the root of the condition's paths.
    ///
    /// PHP: `arrayFilter()` (`Feature\MongoDB\ConditionalArrayUpdates`).
    pub fn array_filter(&mut self, _identifier: &str, condition: Array) -> &mut Self {
        self.dialect.array_filters.push(condition);
        self
    }

    /// A `$bucket` stage grouping by `group_by` between `boundaries`.
    ///
    /// PHP: `bucket()` (`Feature\MongoDB\PipelineStages`).
    pub fn bucket(
        &mut self,
        group_by: &str,
        boundaries: impl IntoValues,
        default_bucket: Option<&str>,
        output: Array,
    ) -> &mut Self {
        let mut stage = doc([
            ("groupBy", value::string(format!("${group_by}"))),
            ("boundaries", Value::Array(boundaries.into_values())),
        ]);
        if let Some(default) = default_bucket {
            stage.set(key("default"), value::string(default));
        }
        if !output.is_empty() {
            stage.set(key("output"), Value::Array(output));
        }
        self.dialect.bucket = Some(stage);
        self
    }

    /// A `$bucketAuto` stage of `buckets` buckets over `group_by`.
    ///
    /// PHP: `bucketAuto()` (`Feature\MongoDB\PipelineStages`).
    pub fn bucket_auto(&mut self, group_by: &str, buckets: i64, output: Array) -> &mut Self {
        let mut stage = doc([("groupBy", value::string(format!("${group_by}"))), ("buckets", Value::Int(buckets))]);
        if !output.is_empty() {
            stage.set(key("output"), Value::Array(output));
        }
        self.dialect.bucket_auto = Some(stage);
        self
    }

    /// A `$facet` stage: each named builder (built now) becomes a sub-pipeline.
    ///
    /// PHP: `facet()` (`Feature\MongoDB\PipelineStages`).
    pub fn facet<'a, K: AsRef<str>>(
        &mut self,
        facets: impl IntoIterator<Item = (K, &'a mut dyn AnyBuilder)>,
    ) -> Result<&mut Self> {
        self.dialect.facets = Some(Vec::new());
        for (name, builder) in facets {
            let result = builder.build()?;
            let op = decode(&result.query)
                .ok_or_else(|| Error::Validation("Cannot parse facet query for MongoDB.".into()))?;
            let facet =
                Facet { name: key(name.as_ref()), pipeline: operation_to_pipeline(&op)?, bindings: result.bindings };
            if let Some(stages) = self.dialect.facets.as_mut() {
                stages.push(facet);
            }
        }
        Ok(self)
    }

    /// A `$graphLookup` stage: a recursive lookup in `from`.
    ///
    /// PHP: `graphLookup()` (`Feature\MongoDB\PipelineStages`).
    #[allow(clippy::too_many_arguments)]
    pub fn graph_lookup(
        &mut self,
        from: &str,
        start_with: &str,
        connect_from_field: &str,
        connect_to_field: &str,
        as_field: &str,
        max_depth: Option<i64>,
        depth_field: Option<&str>,
    ) -> &mut Self {
        let mut stage = doc([
            ("from", value::string(from)),
            ("startWith", value::string(format!("${start_with}"))),
            ("connectFromField", value::string(connect_from_field)),
            ("connectToField", value::string(connect_to_field)),
            ("as", value::string(as_field)),
        ]);
        if let Some(depth) = max_depth {
            stage.set(key("maxDepth"), Value::Int(depth));
        }
        if let Some(field) = depth_field {
            stage.set(key("depthField"), value::string(field));
        }
        self.dialect.graph_lookup = Some(stage);
        self
    }

    /// A terminal `$merge` stage into `collection`.
    ///
    /// PHP: `mergeIntoCollection()` (`Feature\MongoDB\PipelineStages`).
    pub fn merge_into_collection(
        &mut self,
        collection: &str,
        on: Option<Array>,
        when_matched: Option<Array>,
        when_not_matched: Option<Array>,
    ) -> &mut Self {
        let mut stage = doc([("into", value::string(collection))]);
        for (name, part) in [("on", on), ("whenMatched", when_matched), ("whenNotMatched", when_not_matched)] {
            if let Some(part) = part {
                stage.set(key(name), Value::Array(part));
            }
        }
        self.dialect.merge = Some(stage);
        self
    }

    /// A terminal `$out` stage into `collection` (of `database`).
    ///
    /// PHP: `outputToCollection()` (`Feature\MongoDB\PipelineStages`).
    pub fn output_to_collection(&mut self, collection: &str, database: Option<&str>) -> &mut Self {
        self.dialect.out = Some((database.map(str::to_owned), collection.to_owned()));
        self
    }

    /// A `$replaceRoot` stage with `new_root_expression` as the new root.
    ///
    /// PHP: `replaceRoot()` (`Feature\MongoDB\PipelineStages`).
    pub fn replace_root(&mut self, new_root_expression: &str) -> &mut Self {
        self.dialect.replace_root = Some(new_root_expression.to_owned());
        self
    }

    /// An Atlas `$search` stage (first in the pipeline), on `index` if given.
    ///
    /// PHP: `search()` (`Feature\MongoDB\AtlasSearch`).
    pub fn search(&mut self, definition: Array, index: Option<&str>) -> &mut Self {
        self.dialect.search = Some(with_index(definition, index));
        self
    }

    /// An Atlas `$searchMeta` stage: the pipeline returns search metadata only.
    ///
    /// PHP: `searchMeta()` (`Feature\MongoDB\AtlasSearch`).
    pub fn search_meta(&mut self, definition: Array, index: Option<&str>) -> &mut Self {
        self.dialect.search_meta = Some(with_index(definition, index));
        self
    }

    /// An Atlas `$vectorSearch` stage over `path`.
    ///
    /// PHP: `vectorSearch()` (`Feature\MongoDB\AtlasSearch`).
    pub fn vector_search(
        &mut self,
        path: &str,
        query_vector: impl IntoValues,
        num_candidates: i64,
        limit: i64,
        index: Option<&str>,
        filter: Option<Array>,
    ) -> &mut Self {
        let mut stage = doc([
            ("path", value::string(path)),
            ("queryVector", Value::Array(query_vector.into_values())),
            ("numCandidates", Value::Int(num_candidates)),
            ("limit", Value::Int(limit)),
        ]);
        if let Some(index) = index {
            stage.set(key("index"), value::string(index));
        }
        if let Some(filter) = filter {
            stage.set(key("filter"), Value::Array(filter));
        }
        self.dialect.vector_search = Some(stage);
        self
    }

    fn statement_of(&self, operation: Array, read_only: bool) -> Result<Statement> {
        Ok(Statement::with_parts(
            encode(operation)?,
            self.binding_values(),
            read_only,
            self.core.executor.clone(),
            None,
        ))
    }

    fn has_random_order(&self) -> bool {
        !Query::by_type(&self.core.pending, &[Method::OrderRandom]).is_empty()
    }

    fn compile_select(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        for callback in self.before_build.clone() {
            callback(self)?;
        }
        self.validate_table()?;
        let grouped = Query::group_by_type(&self.core.pending);
        let mut result =
            if self.needs_aggregation(&grouped) { self.build_aggregate(&grouped)? } else { self.build_find(&grouped)? };
        for callback in self.core.after_build.clone() {
            result = callback(result)?;
        }
        Ok(result)
    }

    fn documents(&mut self) -> Array {
        let mut documents = Array::with_capacity(self.core.rows.len());
        for row in self.core.rows.clone() {
            let mut document = Array::with_capacity(row.len());
            for (column, value) in row.iter() {
                self.add_binding(value.clone(), None);
                document.set(column.to_owned(), placeholder());
            }
            append(&mut documents, Value::Array(document));
        }
        documents
    }

    fn compile_insert(&mut self, ignore: bool) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        self.validate_rows("insert")?;
        let documents = self.documents();
        let mut operation = doc([
            ("collection", value::string(&self.core.table)),
            ("operation", value::string(Operation::InsertMany.as_str())),
            ("documents", Value::Array(documents)),
        ]);
        if ignore {
            operation.set(key("options"), Value::Array(doc([("ordered", Value::Bool(false))])));
        }
        self.statement_of(operation, false)
    }

    fn compile_update(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        let grouped = Query::group_by_type(&self.core.pending);
        let filter = self.build_filter(&grouped)?;
        let update = self.build_update();
        if update.is_empty() {
            return Err(Error::Validation("No update operations specified. Call set() before update().".into()));
        }
        let mut operation = doc([
            ("collection", value::string(&self.core.table)),
            ("operation", value::string(Operation::UpdateMany.as_str())),
            ("filter", non_empty_or_object(filter)),
            ("update", Value::Array(update)),
        ]);
        if !self.dialect.array_filters.is_empty() {
            let filters = Array::from_list(self.dialect.array_filters.iter().cloned().map(Value::Array).collect());
            operation.set(key("options"), Value::Array(doc([("arrayFilters", Value::Array(filters))])));
        }
        self.statement_of(operation, false)
    }

    fn compile_delete(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        let grouped = Query::group_by_type(&self.core.pending);
        let filter = self.build_filter(&grouped)?;
        let operation = doc([
            ("collection", value::string(&self.core.table)),
            ("operation", value::string(Operation::DeleteMany.as_str())),
            ("filter", non_empty_or_object(filter)),
        ]);
        self.statement_of(operation, false)
    }

    fn compile_upsert_one(&mut self) -> Result<Statement> {
        self.core.bindings.clear();
        self.validate_table()?;
        self.validate_rows("upsert")?;
        let row = self.core.rows[0].clone();
        let mut filter = Array::default();
        for conflict in self.core.conflict_keys.clone() {
            let Some(value) = row.get_str(&conflict).filter(|v| !matches!(v, Value::Null)) else {
                return Err(Error::Validation(format!("Conflict key '{conflict}' not found in row data.")));
            };
            self.add_binding(value.clone(), None);
            filter.set(key(&conflict), placeholder());
        }
        let columns: Vec<ArrayKey> = if self.core.conflict_update_columns.is_empty() {
            row.keys()
                .map(|k| k.to_owned())
                .filter(|k| !self.core.conflict_keys.iter().any(|c| *c == key_text(k)))
                .collect()
        } else {
            self.core.conflict_update_columns.iter().map(|c| key(c)).collect()
        };
        let mut set = Array::default();
        for column in columns {
            self.add_binding(row.get(&column).cloned().unwrap_or(Value::Null), None);
            set.set(column, placeholder());
        }
        let operation = doc([
            ("collection", value::string(&self.core.table)),
            ("operation", value::string(Operation::UpdateOne.as_str())),
            ("filter", Value::Array(filter)),
            ("update", Value::Array(doc([(UpdateOperator::Set.as_str(), Value::Array(set))]))),
            ("options", Value::Array(doc([("upsert", Value::Bool(true))]))),
        ]);
        self.statement_of(operation, false)
    }

    fn needs_aggregation(&self, grouped: &ParsedQuery) -> bool {
        self.has_random_order() || self.has_pipeline_only_feature(grouped) || self.has_subquery_feature(grouped)
    }

    fn has_pipeline_only_feature(&self, grouped: &ParsedQuery) -> bool {
        let d = &self.dialect;
        !grouped.aggregations.is_empty()
            || !grouped.group_by.is_empty()
            || !grouped.having.is_empty()
            || !self.core.window_selects.is_empty()
            || grouped.distinct
            || d.text_search.is_some()
            || d.sample_size.is_some()
            || d.bucket.is_some()
            || d.bucket_auto.is_some()
            || d.facets.is_some()
            || d.graph_lookup.is_some()
            || d.merge.is_some()
            || d.out.is_some()
            || d.replace_root.is_some()
            || d.search.is_some()
            || d.search_meta.is_some()
            || d.vector_search.is_some()
    }

    fn has_subquery_feature(&self, grouped: &ParsedQuery) -> bool {
        let c = &self.core;
        !grouped.joins.is_empty()
            || !c.unions.is_empty()
            || !c.ctes.is_empty()
            || !c.sub_selects.is_empty()
            || !c.raw_selects.is_empty()
            || !c.lateral_joins.is_empty()
            || !c.where_in_subqueries.is_empty()
            || !c.exists_subqueries.is_empty()
    }

    fn build_find(&mut self, grouped: &ParsedQuery) -> Result<Statement> {
        let filter = self.build_filter(grouped)?;
        let projection = self.build_projection(grouped)?;
        let sort = self.build_sort()?;
        let mut operation = doc([
            ("collection", value::string(&self.core.table)),
            ("operation", value::string(Operation::Find.as_str())),
        ]);
        if !filter.is_empty() {
            operation.set(key("filter"), Value::Array(filter));
        }
        if !projection.is_empty() {
            operation.set(key("projection"), Value::Array(projection));
        }
        if !sort.is_empty() {
            operation.set(key("sort"), Value::Array(sort));
        }
        if let Some(offset) = grouped.offset {
            operation.set(key("skip"), Value::Int(offset));
        }
        if let Some(limit) = grouped.limit {
            operation.set(key("limit"), Value::Int(limit));
        }
        if let Some(hint) = &self.dialect.index_hint {
            operation.set(key("hint"), hint.to_value());
        }
        self.statement_of(operation, true)
    }

    fn build_aggregate(&mut self, grouped: &ParsedQuery) -> Result<Statement> {
        let mut pipeline = Array::default();
        // $searchMeta short-circuits: it returns metadata only.
        if let Some(meta) = self.dialect.search_meta.clone() {
            append(&mut pipeline, stage(PipelineStage::SearchMeta, meta));
            return self.aggregate_statement(pipeline);
        }
        self.append_search_stages(&mut pipeline);
        self.append_join_stages(&mut pipeline, grouped)?;
        self.append_filter_stages(&mut pipeline, grouped)?;
        self.append_grouping_stages(&mut pipeline, grouped)?;
        self.append_window_stages(&mut pipeline)?;
        self.append_projection_stage(&mut pipeline, grouped)?;
        self.append_union_stages(&mut pipeline)?;
        self.append_ordering_stages(&mut pipeline)?;
        append_pagination_stages(&mut pipeline, grouped);
        self.append_output_stages(&mut pipeline);
        self.aggregate_statement(pipeline)
    }

    fn aggregate_statement(&self, pipeline: Array) -> Result<Statement> {
        let mut operation = doc([
            ("collection", value::string(&self.core.table)),
            ("operation", value::string(Operation::Aggregate.as_str())),
            ("pipeline", Value::Array(pipeline)),
        ]);
        if let Some(hint) = &self.dialect.index_hint {
            operation.set(key("hint"), hint.to_value());
        }
        self.statement_of(operation, true)
    }

    fn append_search_stages(&mut self, pipeline: &mut Array) {
        if let Some(search) = self.dialect.search.clone() {
            append(pipeline, stage(PipelineStage::Search, search));
        }
        if let Some(vector) = self.dialect.vector_search.clone() {
            append(pipeline, stage(PipelineStage::VectorSearch, vector));
        }
        if let Some(term) = self.dialect.text_search.clone() {
            self.add_binding(value::string(term), None);
            let text = doc([(PipelineStage::Text.as_str(), Value::Array(doc([("$search", placeholder())])))]);
            append(pipeline, stage(PipelineStage::Match, text));
        }
        if let Some(size) = self.dialect.sample_size {
            let size = php_std::format::dval_to_lval(size.ceil());
            append(pipeline, stage(PipelineStage::Sample, doc([("size", Value::Int(size))])));
        }
    }

    fn append_join_stages(&mut self, pipeline: &mut Array, grouped: &ParsedQuery) -> Result<()> {
        for join in &grouped.joins {
            for stage in self.build_join_stages(join)? {
                append(pipeline, stage);
            }
        }
        if let Some(lookup) = self.dialect.graph_lookup.clone() {
            append(pipeline, stage(PipelineStage::GraphLookup, lookup));
        }
        Ok(())
    }

    fn append_filter_stages(&mut self, pipeline: &mut Array, grouped: &ParsedQuery) -> Result<()> {
        for (index, sub) in self.core.where_in_subqueries.clone().into_iter().enumerate() {
            self.append_where_in_subquery(pipeline, sub.column.as_str(), sub.subquery, sub.not, index)?;
        }
        for (index, sub) in self.core.exists_subqueries.clone().into_iter().enumerate() {
            self.append_exists_subquery(pipeline, sub.subquery, sub.not, index)?;
        }
        let filter = self.build_filter(grouped)?;
        if !filter.is_empty() {
            append(pipeline, stage(PipelineStage::Match, filter));
        }
        Ok(())
    }

    fn append_grouping_stages(&mut self, pipeline: &mut Array, grouped: &ParsedQuery) -> Result<()> {
        if grouped.distinct && grouped.group_by.is_empty() && grouped.aggregations.is_empty() {
            self.append_distinct(pipeline, grouped)?;
        }
        if let Some(bucket) = self.dialect.bucket.clone() {
            append(pipeline, stage(PipelineStage::Bucket, bucket));
        } else if let Some(bucket) = self.dialect.bucket_auto.clone() {
            append(pipeline, stage(PipelineStage::BucketAuto, bucket));
        } else if !grouped.group_by.is_empty() || !grouped.aggregations.is_empty() {
            append(pipeline, stage(PipelineStage::Group, build_group(grouped)?));
            let reshape = build_project_from_group(grouped)?;
            if !reshape.is_empty() {
                append(pipeline, stage(PipelineStage::Project, reshape));
            }
        }
        if let Some(root) = self.dialect.replace_root.clone() {
            append(pipeline, stage(PipelineStage::ReplaceRoot, doc([("newRoot", value::string(root))])));
        }
        if !grouped.having.is_empty() || !self.core.raw_havings.is_empty() {
            let having = self.build_having(grouped)?;
            if !having.is_empty() {
                append(pipeline, stage(PipelineStage::Match, having));
            }
        }
        Ok(())
    }

    fn append_window_stages(&mut self, pipeline: &mut Array) -> Result<()> {
        for window in self.core.window_selects.clone() {
            append(pipeline, build_window_stage(&window)?);
        }
        Ok(())
    }

    fn append_projection_stage(&mut self, pipeline: &mut Array, grouped: &ParsedQuery) -> Result<()> {
        if !grouped.group_by.is_empty() || !grouped.aggregations.is_empty() || grouped.distinct {
            return Ok(());
        }
        if self.dialect.bucket.is_some() || self.dialect.bucket_auto.is_some() {
            return Ok(());
        }
        let mut projection = self.build_projection(grouped)?;
        if projection.is_empty() {
            return Ok(());
        }
        // Window function outputs stay in the projection.
        for window in &self.core.window_selects {
            projection.set(key(&window.alias), Value::Int(1));
        }
        append(pipeline, stage(PipelineStage::Project, projection));
        Ok(())
    }

    fn append_union_stages(&mut self, pipeline: &mut Array) -> Result<()> {
        if let Some(facets) = self.dialect.facets.clone() {
            let mut facet = Array::with_capacity(facets.len());
            for entry in facets {
                facet.set(entry.name, Value::Array(entry.pipeline));
                self.add_bindings(entry.bindings);
            }
            append(pipeline, stage(PipelineStage::Facet, facet));
        }
        for union in self.core.unions.clone() {
            let op = decode(&union.query)
                .ok_or_else(|| Error::Validation("Cannot parse union query for MongoDB.".into()))?;
            let sub_pipeline = operation_to_pipeline(&op)?;
            let collection = match &op {
                Value::Array(a) => a.get_str("collection").cloned().unwrap_or(Value::Null),
                _ => Value::Null,
            };
            let mut union_with = doc([("coll", collection)]);
            if !sub_pipeline.is_empty() {
                union_with.set(key("pipeline"), Value::Array(sub_pipeline));
            }
            append(pipeline, stage(PipelineStage::UnionWith, union_with));
            self.add_bindings(union.bindings);
        }
        Ok(())
    }

    fn append_ordering_stages(&mut self, pipeline: &mut Array) -> Result<()> {
        let random = self.has_random_order();
        if random {
            let rand = doc([("$rand", Value::Object(Array::default()))]);
            append(pipeline, stage(PipelineStage::AddFields, doc([("_rand", Value::Array(rand))])));
        }
        let mut sort = self.build_sort()?;
        if random {
            sort.set(key("_rand"), Value::Int(1));
        }
        if !sort.is_empty() {
            append(pipeline, stage(PipelineStage::Sort, sort));
        }
        if random {
            append(pipeline, stage(PipelineStage::Unset, value::string("_rand")));
        }
        Ok(())
    }

    fn append_output_stages(&self, pipeline: &mut Array) {
        if let Some(merge) = self.dialect.merge.clone() {
            append(pipeline, stage(PipelineStage::Merge, merge));
            return;
        }
        if let Some((database, collection)) = &self.dialect.out {
            let out = match database {
                Some(db) => Value::Array(doc([("db", value::string(db)), ("coll", value::string(collection))])),
                None => value::string(collection),
            };
            append(pipeline, stage(PipelineStage::Out, out));
        }
    }

    fn build_filter(&mut self, grouped: &ParsedQuery) -> Result<Array> {
        let mut conditions = Vec::with_capacity(grouped.filters.len());
        for filter in &grouped.filters {
            conditions.push(self.build_filter_query(filter)?);
        }
        Ok(combine(conditions, "$and"))
    }

    fn build_filter_query(&mut self, query: &Query) -> Result<Array> {
        match query.method() {
            Method::And => self.build_logical(query, "$and"),
            Method::Or => self.build_logical(query, "$or"),
            Method::Exists => self.build_field_exists(query, true),
            Method::NotExists => self.build_field_exists(query, false),
            _ => self.build_field_filter(query),
        }
    }

    fn build_field_filter(&mut self, query: &Query) -> Result<Array> {
        let method = query.method();
        let attribute = self.resolve_filter_field(query.attribute())?;
        let values = query.values();
        use Method::*;
        match method {
            Equal => Ok(self.build_in(&attribute, values)),
            NotEqual => Ok(self.build_not_in(&attribute, values)),
            LessThan => Ok(self.build_comparison(&attribute, "$lt", values)),
            LessThanEqual => Ok(self.build_comparison(&attribute, "$lte", values)),
            GreaterThan => Ok(self.build_comparison(&attribute, "$gt", values)),
            GreaterThanEqual => Ok(self.build_comparison(&attribute, "$gte", values)),
            Between => Ok(self.build_between(&attribute, values, false)),
            NotBetween => Ok(self.build_between(&attribute, values, true)),
            StartsWith => self.build_regex_filter(&attribute, values, "^", "", false),
            NotStartsWith => self.build_regex_filter(&attribute, values, "^", "", true),
            EndsWith => self.build_regex_filter(&attribute, values, "", "$", false),
            NotEndsWith => self.build_regex_filter(&attribute, values, "", "$", true),
            Contains => self.build_contains(&attribute, values),
            ContainsAny => {
                if query.on_array() {
                    Ok(self.build_in(&attribute, values))
                } else {
                    self.build_contains(&attribute, values)
                }
            }
            ContainsAll => self.build_contains_each(&attribute, values, false),
            NotContains => self.build_not_contains(&attribute, values),
            Regex => {
                self.add_binding(first(values), None);
                Ok(field_doc(&attribute, Value::Array(doc([("$regex", placeholder())]))))
            }
            IsNull => Ok(field_doc(&attribute, Value::Null)),
            IsNotNull => Ok(field_doc(&attribute, Value::Array(doc([("$ne", Value::Null)])))),
            other => Err(Error::Unsupported(format!("Unsupported filter type for MongoDB: {}", other.as_str()))),
        }
    }

    fn bind_placeholders(&mut self, values: Vec<Value>) -> Value {
        let mut placeholders = Array::with_capacity(values.len());
        for value in values {
            self.add_binding(value, None);
            append(&mut placeholders, placeholder());
        }
        Value::Array(placeholders)
    }

    fn build_in(&mut self, attribute: &str, values: &Array) -> Array {
        let has_null = values.values().any(|v| matches!(v, Value::Null));
        let mut non_nulls: Vec<Value> = values.values().filter(|v| !matches!(v, Value::Null)).cloned().collect();
        if has_null && non_nulls.is_empty() {
            return field_doc(attribute, Value::Null);
        }
        if non_nulls.len() == 1 && !has_null {
            self.add_binding(non_nulls.remove(0), None);
            return field_doc(attribute, placeholder());
        }
        let placeholders = self.bind_placeholders(non_nulls);
        let in_ = field_doc(attribute, Value::Array(doc([("$in", placeholders)])));
        if has_null {
            let either = Array::from_list(vec![Value::Array(in_), Value::Array(field_doc(attribute, Value::Null))]);
            return doc([("$or", Value::Array(either))]);
        }
        in_
    }

    fn build_not_in(&mut self, attribute: &str, values: &Array) -> Array {
        let has_null = values.values().any(|v| matches!(v, Value::Null));
        let mut non_nulls: Vec<Value> = values.values().filter(|v| !matches!(v, Value::Null)).cloned().collect();
        let not_null = || Value::Array(doc([("$ne", Value::Null)]));
        if has_null && non_nulls.is_empty() {
            return field_doc(attribute, not_null());
        }
        if non_nulls.len() == 1 && !has_null {
            self.add_binding(non_nulls.remove(0), None);
            return field_doc(attribute, Value::Array(doc([("$ne", placeholder())])));
        }
        let placeholders = self.bind_placeholders(non_nulls);
        let nin = field_doc(attribute, Value::Array(doc([("$nin", placeholders)])));
        if has_null {
            let both = Array::from_list(vec![Value::Array(nin), Value::Array(field_doc(attribute, not_null()))]);
            return doc([("$and", Value::Array(both))]);
        }
        nin
    }

    fn build_comparison(&mut self, attribute: &str, operator: &str, values: &Array) -> Array {
        self.add_binding(first(values), None);
        field_doc(attribute, Value::Array(doc([(operator, placeholder())])))
    }

    fn build_between(&mut self, attribute: &str, values: &Array, not: bool) -> Array {
        self.add_binding(first(values), None);
        self.add_binding(values.get(&ArrayKey::Int(1)).cloned().unwrap_or(Value::Null), None);
        if not {
            let either = Array::from_list(vec![
                Value::Array(field_doc(attribute, Value::Array(doc([("$lt", placeholder())])))),
                Value::Array(field_doc(attribute, Value::Array(doc([("$gt", placeholder())])))),
            ]);
            return doc([("$or", Value::Array(either))]);
        }
        field_doc(attribute, Value::Array(doc([("$gte", placeholder()), ("$lte", placeholder())])))
    }

    fn build_regex_filter(
        &mut self,
        attribute: &str,
        values: &Array,
        prefix: &str,
        suffix: &str,
        not: bool,
    ) -> Result<Array> {
        const STR: Param<'static> = Param::internal("preg_quote", 1, "str");
        let raw = first(values);
        let raw = STR.bytes(&raw)?;
        let mut pattern = prefix.as_bytes().to_vec();
        pattern.extend(quoted(&raw));
        pattern.extend_from_slice(suffix.as_bytes());
        self.add_binding(value::string(pattern), None);
        Ok(field_doc(attribute, regex_condition(not)))
    }

    /// `preg_quote((string) $value, '/')` as a binding.
    fn bind_pattern(&mut self, value: &Value) -> Result<()> {
        let pattern = quoted(&value::concat_bytes(value)?);
        self.add_binding(value::string(pattern), None);
        Ok(())
    }

    fn build_contains(&mut self, attribute: &str, values: &Array) -> Result<Array> {
        if values.len() == 1 {
            self.bind_pattern(&first(values))?;
            return Ok(field_doc(attribute, regex_condition(false)));
        }
        let mut conditions = Array::with_capacity(values.len());
        for value in values.values() {
            self.bind_pattern(value)?;
            append(&mut conditions, Value::Array(field_doc(attribute, regex_condition(false))));
        }
        Ok(doc([("$or", Value::Array(conditions))]))
    }

    fn build_contains_each(&mut self, attribute: &str, values: &Array, not: bool) -> Result<Array> {
        let mut conditions = Array::with_capacity(values.len());
        for value in values.values() {
            self.bind_pattern(value)?;
            append(&mut conditions, Value::Array(field_doc(attribute, regex_condition(not))));
        }
        Ok(doc([("$and", Value::Array(conditions))]))
    }

    fn build_not_contains(&mut self, attribute: &str, values: &Array) -> Result<Array> {
        if values.len() == 1 {
            self.bind_pattern(&first(values))?;
            return Ok(field_doc(attribute, regex_condition(true)));
        }
        self.build_contains_each(attribute, values, true)
    }

    fn build_logical(&mut self, query: &Query, operator: &str) -> Result<Array> {
        let mut conditions = Array::with_capacity(query.values().len());
        for value in query.values().values() {
            let condition = self.build_filter_query(sub_query(value)?)?;
            append(&mut conditions, Value::Array(condition));
        }
        if conditions.is_empty() {
            return Ok(if operator == "$or" { doc([("$expr", Value::Bool(false))]) } else { Array::default() });
        }
        Ok(doc([(operator, Value::Array(conditions))]))
    }

    /// SQL parity: `IS NOT NULL` is a present non-null value
    /// (`{$exists: true, $ne: null}`), `IS NULL` an explicit null (`{$type: 10}`).
    fn build_field_exists(&mut self, query: &Query, exists: bool) -> Result<Array> {
        let mut conditions = Vec::with_capacity(query.values().len());
        const FIELD: Param<'static> = Param::method(
            "Utopia\\Query\\Builder\\MongoDB::resolveFilterField",
            1,
            "attribute",
            "Builder/MongoDB.php",
            1159,
        );
        for attribute in query.values().values() {
            let field = self.resolve_filter_field(&FIELD.string(attribute)?)?;
            let condition = if exists {
                doc([("$exists", Value::Bool(true)), ("$ne", Value::Null)])
            } else {
                doc([("$type", Value::Int(10))])
            };
            conditions.push(field_doc(&field, Value::Array(condition)));
        }
        if conditions.len() == 1 {
            return Ok(conditions.remove(0));
        }
        Ok(doc([("$and", Value::Array(Array::from_list(conditions.into_iter().map(Value::Array).collect())))]))
    }

    fn build_projection(&mut self, grouped: &ParsedQuery) -> Result<Array> {
        let Some(selection) = grouped.selections.first() else {
            return Ok(Array::default());
        };
        const FIELD: Param<'static> =
            Param::method("Utopia\\Query\\Builder::resolveAttribute", 1, "attribute", "Builder/MongoDB.php", 1188);
        let mut projection = Array::with_capacity(selection.values().len() + 1);
        for field in selection.values().values() {
            let resolved = self.resolve_attribute(&FIELD.string(field)?)?;
            projection.set(key(&resolved), Value::Int(1));
        }
        if !projection.contains_key(&key("_id")) {
            projection.set(key("_id"), Value::Int(0));
        }
        Ok(projection)
    }

    fn build_sort(&mut self) -> Result<Array> {
        let mut sort = Array::default();
        let orders: Vec<Query> =
            Query::by_type(&self.core.pending, &[Method::OrderAsc, Method::OrderDesc]).into_iter().cloned().collect();
        for order in &orders {
            let attribute = self.resolve_attribute(order.attribute())?;
            let direction = if order.method() == Method::OrderAsc { 1 } else { -1 };
            sort.set(key(&attribute), Value::Int(direction));
        }
        Ok(sort)
    }

    fn build_join_stages(&mut self, join: &Query) -> Result<Vec<Value>> {
        let table = join.attribute();
        if matches!(join.method(), Method::CrossJoin | Method::NaturalJoin) {
            return Err(Error::Unsupported(
                "Cross and natural joins cannot be expressed as a MongoDB $lookup. Reached via \
                 queries([Query::crossJoin(...)]); the MongoDB builder does not implement Feature\\CrossJoins."
                    .into(),
            ));
        }
        let conditions = join.join_on_queries();
        let Some(condition) = conditions.first() else {
            return Err(Error::Validation("Join query must have values.".into()));
        };
        if conditions.len() > 1 || condition.method() != Method::On {
            return Err(Error::Unsupported(
                "MongoDB $lookup in localField/foreignField form only supports a single on() condition. \
                 Use a pipeline-form $lookup via a raw stage for filtered joins."
                    .into(),
            ));
        }
        let (left, operator, right) = on_values(condition);
        let alias = if join.alias().is_empty() { table } else { join.alias() };
        if is_empty_string(&left) || is_empty_string(&right) {
            return Err(Error::Validation("Join ON requires left and right columns".into()));
        }
        if !matches!(&operator, Value::Str(s) if s.as_bytes() == b"=") {
            return Err(Error::Unsupported(format!(
                "MongoDB $lookup in localField/foreignField form only supports equality joins. Got operator \
                 '{}'. Use a pipeline-form $lookup via a raw stage for non-equality joins.",
                value::concat(&operator)?
            )));
        }
        let field = |line: u32, value: &Value| -> Result<String> {
            let param = Param::method(
                "Utopia\\Query\\Builder\\MongoDB::stripTablePrefix",
                1,
                "field",
                "Builder/MongoDB.php",
                line,
            );
            Ok(strip_table_prefix(&param.string(value)?).to_owned())
        };
        let local = field(1340, &left)?;
        let foreign = field(1341, &right)?;
        let lookup = doc([
            ("from", value::string(table)),
            ("localField", value::string(local)),
            ("foreignField", value::string(foreign)),
            ("as", value::string(alias)),
        ]);
        let path = value::string(format!("${alias}"));
        let unwind = if join.method() == Method::LeftJoin {
            Value::Array(doc([("path", path), ("preserveNullAndEmptyArrays", Value::Bool(true))]))
        } else {
            path
        };
        Ok(vec![stage(PipelineStage::Lookup, lookup), stage(PipelineStage::Unwind, unwind)])
    }

    fn append_distinct(&mut self, pipeline: &mut Array, grouped: &ParsedQuery) -> Result<()> {
        let Some(selection) = grouped.selections.first() else {
            return Ok(());
        };
        const FIELD: Param<'static> =
            Param::method("Utopia\\Query\\Builder::resolveAttribute", 1, "attribute", "Builder/MongoDB.php", 1375);
        // Each attribute resolved once, kept by the field as an array key
        // (`$resolved[$field]`): fields sharing a key share the last resolution.
        let mut by_key = Array::with_capacity(selection.values().len());
        for field in selection.values().values() {
            let name = self.resolve_attribute(&FIELD.string(field)?)?;
            by_key.set(value::array_key(field)?, value::string(name));
        }
        let mut resolved = Vec::with_capacity(selection.values().len());
        for field in selection.values().values() {
            resolved.push(value::to_php_string(by_key.get(&value::array_key(field)?).unwrap_or(&Value::Null)));
        }
        let mut id = Array::with_capacity(resolved.len());
        for field in &resolved {
            id.set(key(field), value::string(format!("${field}")));
        }
        append(pipeline, stage(PipelineStage::Group, doc([("_id", Value::Array(id))])));
        let mut project = doc([("_id", Value::Int(0))]);
        for field in &resolved {
            project.set(key(field), value::string(format!("$_id.{field}")));
        }
        append(pipeline, stage(PipelineStage::Project, project));
        Ok(())
    }

    fn build_having(&mut self, grouped: &ParsedQuery) -> Result<Array> {
        let mut conditions = Vec::new();
        for having in &grouped.having {
            for value in having.values().values() {
                conditions.push(self.build_filter_query(sub_query(value)?)?);
            }
        }
        Ok(combine(conditions, "$and"))
    }

    fn append_where_in_subquery(
        &mut self,
        pipeline: &mut Array,
        column: &str,
        mut subquery: Box<dyn AnyBuilder>,
        not: bool,
        index: usize,
    ) -> Result<()> {
        let result = subquery.build()?;
        let op = decode(&result.query)
            .ok_or_else(|| Error::Validation("Cannot parse subquery for MongoDB WHERE IN.".into()))?;
        self.add_bindings(result.bindings);
        let collection = sub_collection(&op);
        let sub_pipeline = operation_to_pipeline(&op)?;
        let sub_field = extract_projection_field(&sub_pipeline);
        let lookup_alias = format!("_sub_{index}");
        let ids = format!("_sub_ids_{index}");
        append(
            pipeline,
            stage(
                PipelineStage::Lookup,
                doc([
                    ("from", collection),
                    ("pipeline", Value::Array(sub_pipeline)),
                    ("as", value::string(&lookup_alias)),
                ]),
            ),
        );
        let map = doc([
            ("input", value::string(format!("${lookup_alias}"))),
            ("as", value::string("s")),
            ("in", value::string(format!("$$s.{sub_field}"))),
        ]);
        append(
            pipeline,
            stage(PipelineStage::AddFields, doc([(ids.as_str(), Value::Array(doc([("$map", Value::Array(map))])))])),
        );
        let column = self.resolve_attribute(column)?;
        let operands = Array::from_list(vec![value::string(format!("${column}")), value::string(format!("${ids}"))]);
        let in_ = Value::Array(doc([("$in", Value::Array(operands))]));
        let expr = if not { Value::Array(doc([("$not", in_)])) } else { in_ };
        append(pipeline, stage(PipelineStage::Match, doc([("$expr", expr)])));
        let unset = Array::from_list(vec![value::string(&lookup_alias), value::string(&ids)]);
        append(pipeline, stage(PipelineStage::Unset, Value::Array(unset)));
        Ok(())
    }

    fn append_exists_subquery(
        &mut self,
        pipeline: &mut Array,
        mut subquery: Box<dyn AnyBuilder>,
        not: bool,
        index: usize,
    ) -> Result<()> {
        let result = subquery.build()?;
        let op = decode(&result.query)
            .ok_or_else(|| Error::Validation("Cannot parse subquery for MongoDB EXISTS.".into()))?;
        self.add_bindings(result.bindings);
        let collection = sub_collection(&op);
        let mut sub_pipeline = operation_to_pipeline(&op)?;
        // An existence check needs one document.
        if !sub_pipeline.values().any(|s| isset(s, PipelineStage::Limit.as_str()).is_some()) {
            append(&mut sub_pipeline, stage(PipelineStage::Limit, Value::Int(1)));
        }
        let alias = format!("_exists_{index}");
        append(
            pipeline,
            stage(
                PipelineStage::Lookup,
                doc([("from", collection), ("pipeline", Value::Array(sub_pipeline)), ("as", value::string(&alias))]),
            ),
        );
        let condition =
            if not { doc([("$size", Value::Int(0))]) } else { doc([("$ne", Value::Array(Array::default()))]) };
        append(pipeline, stage(PipelineStage::Match, field_doc(&alias, Value::Array(condition))));
        append(pipeline, stage(PipelineStage::Unset, value::string(&alias)));
        Ok(())
    }

    fn build_update(&mut self) -> Array {
        let mut update = Array::default();
        if let Some(row) = self.core.rows.first().cloned() {
            let mut set = Array::with_capacity(row.len());
            for (column, value) in row.iter() {
                self.add_binding(value.clone(), None);
                set.set(column.to_owned(), placeholder());
            }
            update.set(key(UpdateOperator::Set.as_str()), Value::Array(set));
        }
        for (operator, fields) in self.dialect.update_operations.clone() {
            if fields.is_empty() {
                continue;
            }
            let payload = match operator {
                UpdateOperator::Push => self.emit_push_fields(&fields),
                UpdateOperator::Pull | UpdateOperator::AddToSet | UpdateOperator::Min | UpdateOperator::Max => {
                    self.emit_bound_values(&fields)
                }
                UpdateOperator::PullAll => self.emit_pull_all_fields(&fields),
                _ => fields,
            };
            update.set(key(operator.as_str()), Value::Array(payload));
        }
        update
    }

    fn emit_push_fields(&mut self, fields: &Array) -> Array {
        let mut push = Array::with_capacity(fields.len());
        for (field, value) in fields.iter() {
            let modifier = match value {
                Value::Array(a) => a.get_str("__each").cloned(),
                _ => None,
            };
            let Some(modifier) = modifier else {
                self.add_binding(value.clone(), None);
                push.set(field.to_owned(), placeholder());
                continue;
            };
            let entry = |name: &str| isset(&modifier, name).cloned();
            let mut each = Array::default();
            if let Some(Value::Array(values)) = entry("values") {
                for value in values.values() {
                    self.add_binding(value.clone(), None);
                    append(&mut each, placeholder());
                }
            }
            let mut each_doc = doc([("$each", Value::Array(each))]);
            for (name, modifier_key) in [("position", "$position"), ("slice", "$slice"), ("sort", "$sort")] {
                if let Some(value) = entry(name) {
                    each_doc.set(key(modifier_key), value);
                }
            }
            push.set(field.to_owned(), Value::Array(each_doc));
        }
        push
    }

    fn emit_bound_values(&mut self, fields: &Array) -> Array {
        let mut out = Array::with_capacity(fields.len());
        for (field, value) in fields.iter() {
            self.add_binding(value.clone(), None);
            out.set(field.to_owned(), placeholder());
        }
        out
    }

    fn emit_pull_all_fields(&mut self, fields: &Array) -> Array {
        let mut out = Array::with_capacity(fields.len());
        for (field, values) in fields.iter() {
            let mut placeholders = Array::default();
            if let Value::Array(values) = values {
                for value in values.values() {
                    self.add_binding(value.clone(), None);
                    append(&mut placeholders, placeholder());
                }
            }
            out.set(field.to_owned(), Value::Array(placeholders));
        }
        out
    }
}

/// `$definition` with `index` set when given.
fn with_index(mut definition: Array, index: Option<&str>) -> Array {
    if let Some(index) = index {
        definition.set(key("index"), value::string(index));
    }
    definition
}

/// `{field: value}`.
fn field_doc(field: &str, value: Value) -> Array {
    doc([(field, value)])
}

/// `{$regex: ?}` or `{$not: {$regex: ?}}`.
fn regex_condition(not: bool) -> Value {
    let regex = Value::Array(doc([("$regex", placeholder())]));
    if not { Value::Array(doc([("$not", regex)])) } else { regex }
}

/// The filter, or `{}` when empty.
fn non_empty_or_object(filter: Array) -> Value {
    if filter.is_empty() { Value::Object(Array::default()) } else { Value::Array(filter) }
}

/// No conditions: empty; one: itself; more: `{operator: [...]}`.
fn combine(mut conditions: Vec<Array>, operator: &str) -> Array {
    match conditions.len() {
        0 => Array::default(),
        1 => conditions.remove(0),
        _ => doc([(operator, Value::Array(Array::from_list(conditions.into_iter().map(Value::Array).collect())))]),
    }
}

/// `$subOp['collection'] ?? ''`.
fn sub_collection(op: &Value) -> Value {
    isset(op, "collection").cloned().unwrap_or_else(|| value::string(""))
}

fn append_pagination_stages(pipeline: &mut Array, grouped: &ParsedQuery) {
    if let Some(offset) = grouped.offset {
        append(pipeline, stage(PipelineStage::Skip, Value::Int(offset)));
    }
    if let Some(limit) = grouped.limit {
        append(pipeline, stage(PipelineStage::Limit, Value::Int(limit)));
    }
}

fn aggregate_alias(aggregate: &Query) -> String {
    if aggregate.alias().is_empty() { aggregate.method().as_str().to_owned() } else { aggregate.alias().to_owned() }
}

fn build_group(grouped: &ParsedQuery) -> Result<Array> {
    let id = match grouped.group_by.as_slice() {
        [] => Value::Null,
        [column] => value::string(format!("${}", value::concat(column)?)),
        columns => {
            let mut id = Array::with_capacity(columns.len());
            for column in columns {
                let path = value::string(format!("${}", value::concat(column)?));
                id.set(value::array_key(column)?, path);
            }
            Value::Array(id)
        }
    };
    let mut group = doc([("_id", id)]);
    for aggregate in &grouped.aggregations {
        let method = aggregate.method();
        let column = || value::string(format!("${}", aggregate.attribute()));
        let accumulator = match method {
            Method::Count => doc([("$sum", Value::Int(1))]),
            Method::Sum => doc([("$sum", column())]),
            Method::Avg => doc([("$avg", column())]),
            Method::Min => doc([("$min", column())]),
            Method::Max => doc([("$max", column())]),
            other => {
                return Err(Error::Unsupported(format!("Unsupported aggregation for MongoDB: {}", other.as_str())));
            }
        };
        group.set(key(&aggregate_alias(aggregate)), Value::Array(accumulator));
    }
    Ok(group)
}

/// After `$group`, the `_id` fields flattened back to the top level.
fn build_project_from_group(grouped: &ParsedQuery) -> Result<Array> {
    let mut project = doc([("_id", Value::Int(0))]);
    match grouped.group_by.as_slice() {
        [] => {}
        [column] => {
            project.set(value::array_key(column)?, value::string("$_id"));
        }
        columns => {
            for column in columns {
                let path = value::string(format!("$_id.{}", value::concat(column)?));
                project.set(value::array_key(column)?, path);
            }
        }
    }
    for aggregate in &grouped.aggregations {
        project.set(key(&aggregate_alias(aggregate)), Value::Int(1));
    }
    Ok(project)
}

/// A `$setWindowFields` stage for a window function.
fn build_window_stage(window: &WindowSelect) -> Result<Value> {
    let function = &window.function;
    let name = function.trim_matches(['(', ')']).to_ascii_lowercase();
    let ranking = match name.as_str() {
        "row_number" => Some("$documentNumber"),
        "rank" => Some("$rank"),
        "dense_rank" => Some("$denseRank"),
        _ => None,
    };
    let order_by = window.order_by.as_ref().filter(|o| !o.is_empty());
    let output = match ranking {
        Some(operator) => {
            if order_by.is_none() {
                return Err(Error::Validation(format!(
                    "Window function '{function}' requires an ORDER BY clause on MongoDB."
                )));
            }
            doc([(operator, Value::Object(Array::default()))])
        }
        None => {
            let Some((aggregate, argument)) = split_call(function) else {
                return Err(Error::Unsupported(format!("Unsupported window function: {function}")));
            };
            let argument = argument.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B']);
            // $setWindowFields takes a single expression.
            if argument.contains(',') {
                return Err(Error::Unsupported(format!(
                    "Multi-argument window functions are not supported on MongoDB: {function}"
                )));
            }
            let aggregate = aggregate.to_ascii_lowercase();
            let operator = match aggregate.as_str() {
                "sum" | "count" => "$sum",
                "avg" => "$avg",
                "min" => "$min",
                "max" => "$max",
                _ => return Err(Error::Unsupported(format!("Unsupported window function: {function}"))),
            };
            let operand = if aggregate == "count" { Value::Int(1) } else { value::string(format!("${argument}")) };
            let bounds = Array::from_list(vec![value::string("unbounded"), value::string("current")]);
            doc([(operator, operand), ("window", Value::Array(doc([("documents", Value::Array(bounds))])))])
        }
    };
    let mut body = doc([("output", Value::Array(doc([(window.alias.as_str(), Value::Array(output))])))]);
    if let Some(partition) = window.partition_by.as_ref().filter(|p| !p.is_empty()) {
        let partition_by = match partition.as_slice() {
            [column] => value::string(format!("${column}")),
            columns => {
                let mut by = Array::with_capacity(columns.len());
                for column in columns {
                    by.set(key(column), value::string(format!("${column}")));
                }
                Value::Array(by)
            }
        };
        body.set(key("partitionBy"), partition_by);
    }
    if let Some(order) = order_by {
        let mut sort_by = Array::with_capacity(order.len());
        for column in order {
            match column.strip_prefix('-') {
                Some(name) => sort_by.set(key(name), Value::Int(-1)),
                None => sort_by.set(key(column), Value::Int(1)),
            };
        }
        body.set(key("sortBy"), Value::Array(sort_by));
    }
    Ok(stage(PipelineStage::SetWindowFields, body))
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn split_call_matches_php_pattern() {
        assert_eq!(split_call("SUM(amount)"), Some(("SUM", "amount")));
        assert_eq!(split_call("SUM(amount)\n"), Some(("SUM", "amount")));
        assert_eq!(split_call("f()"), None);
        assert_eq!(split_call("(x)"), None);
        assert_eq!(split_call("f(a)(b)"), Some(("f", "a)(b")));
    }

    #[test]
    fn strips_table_prefix() {
        assert_eq!(strip_table_prefix("users.id"), "id");
        assert_eq!(strip_table_prefix("id"), "id");
    }
}
