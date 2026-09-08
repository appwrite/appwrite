//! PHP `Utopia\Query\Query`.

use serde_json::{Map, Value as JsonValue};

use crate::builder::parsed_query::{ParsedQuery, TimeBucket};
use crate::compiler::Compiler;
use crate::enums::{CursorDirection, NullsPosition};
use crate::error::QueryError;
use crate::method::Method;
use crate::value::{IntoValues, QueryValue};

/// PHP `Utopia\Query\Query`.
#[derive(Debug, Clone, PartialEq)]
pub struct Query {
    method: Method,
    attribute: String,
    values: Vec<QueryValue>,
    attribute_type: String,
    on_array: bool,
}

impl Query {
    pub const DEFAULT_ALIAS: &'static str = "main";
    pub const GROUP_BY_TIME_BUCKET_INTERVALS: &'static [&'static str] =
        &["1m", "5m", "15m", "1h", "1d", "1w", "1M"];

    pub fn new(
        method: impl IntoMethod,
        attribute: impl Into<String>,
        values: impl IntoValues,
    ) -> Self {
        Self {
            method: method.into_method(),
            attribute: attribute.into(),
            values: values.into_values(),
            attribute_type: String::new(),
            on_array: false,
        }
    }

    pub fn new_method(method: impl IntoMethod) -> Self {
        Self::new(method, "", ())
    }

    pub fn get_method(&self) -> Method {
        self.method
    }

    pub fn get_attribute(&self) -> &str {
        &self.attribute
    }

    pub fn get_values(&self) -> &[QueryValue] {
        &self.values
    }

    pub fn get_value(&self) -> QueryValue {
        self.values.first().cloned().unwrap_or(QueryValue::Null)
    }

    pub fn get_value_or(&self, default: impl Into<QueryValue>) -> QueryValue {
        self.values
            .first()
            .cloned()
            .unwrap_or_else(|| default.into())
    }

    pub fn set_method(&mut self, method: impl IntoMethod) -> &mut Self {
        self.method = method.into_method();
        self
    }

    pub fn set_attribute(&mut self, attribute: impl Into<String>) -> &mut Self {
        self.attribute = attribute.into();
        self
    }

    pub fn set_values(&mut self, values: impl IntoValues) -> &mut Self {
        self.values = values.into_values();
        self
    }

    pub fn set_value(&mut self, value: impl Into<QueryValue>) -> &mut Self {
        self.values = vec![value.into()];
        self
    }

    pub fn is_method(value: &str) -> bool {
        Method::try_from_value(value).is_some()
    }

    pub fn is_spatial_query(&self) -> bool {
        self.method.is_spatial()
    }

    pub fn is_nested(&self) -> bool {
        self.method.is_nested()
    }

    pub fn on_array(&self) -> bool {
        self.on_array
    }

    pub fn set_on_array(&mut self, value: bool) {
        self.on_array = value;
    }

    pub fn set_attribute_type(&mut self, type_name: impl Into<String>) {
        self.attribute_type = type_name.into();
    }

    pub fn get_attribute_type(&self) -> &str {
        &self.attribute_type
    }

    pub fn parse(query: &str) -> Result<Self, QueryError> {
        Self::parse_allow_raw(query, false)
    }

    pub fn parse_allow_raw(query: &str, allow_raw: bool) -> Result<Self, QueryError> {
        let decoded: JsonValue = serde_json::from_str(query)
            .map_err(|e| QueryError::exception(format!("Invalid query: {e}")))?;
        if !matches!(decoded, JsonValue::Array(_) | JsonValue::Object(_)) {
            return Err(QueryError::exception(format!(
                "Invalid query. Must be an array, got {}",
                QueryValue::php_gettype(&decoded)
            )));
        }
        Self::parse_query_json(&decoded, allow_raw)
    }

    pub fn parse_query(query: &JsonValue) -> Result<Self, QueryError> {
        Self::parse_query_json(query, false)
    }

    pub fn parse_query_allow_raw(query: &JsonValue, allow_raw: bool) -> Result<Self, QueryError> {
        Self::parse_query_json(query, allow_raw)
    }

    fn parse_query_json(query: &JsonValue, allow_raw: bool) -> Result<Self, QueryError> {
        let obj = match query {
            JsonValue::Object(map) => map,
            JsonValue::Array(_) => {
                return Err(QueryError::exception(
                    "Invalid query method. Must be a string, got NULL",
                ));
            }
            other => {
                return Err(QueryError::exception(format!(
                    "Invalid query. Must be an array, got {}",
                    QueryValue::php_gettype(other)
                )));
            }
        };

        let method_val = obj.get("method").cloned().unwrap_or(JsonValue::Null);
        let attribute_val = obj.get("attribute").cloned().unwrap_or(JsonValue::Null);
        let values_val = obj
            .get("values")
            .cloned()
            .unwrap_or(JsonValue::Array(vec![]));

        let method = match &method_val {
            JsonValue::Null => String::new(),
            JsonValue::String(s) => s.clone(),
            other => {
                return Err(QueryError::exception(format!(
                    "Invalid query method. Must be a string, got {}",
                    QueryValue::php_gettype(other)
                )));
            }
        };

        if !Self::is_method(&method) {
            return Err(QueryError::exception(format!(
                "Invalid query method: {method}"
            )));
        }

        let attribute = match &attribute_val {
            JsonValue::Null => String::new(),
            JsonValue::String(s) => s.clone(),
            other => {
                return Err(QueryError::exception(format!(
                    "Invalid query attribute. Must be a string, got {}",
                    QueryValue::php_gettype(other)
                )));
            }
        };

        let values_arr = match &values_val {
            JsonValue::Null => Vec::new(),
            JsonValue::Array(items) => items.clone(),
            other => {
                return Err(QueryError::exception(format!(
                    "Invalid query values. Must be an array, got {}",
                    QueryValue::php_gettype(other)
                )));
            }
        };

        let method_enum = Method::try_from_value(&method).expect("checked is_method");

        if method_enum == Method::Raw && !allow_raw {
            return Err(QueryError::validation(
                "Raw queries cannot be parsed from untrusted input; construct via Query::raw() in code",
            ));
        }

        let mut values = Vec::with_capacity(values_arr.len());
        if method_enum.is_nested() {
            for value in &values_arr {
                values.push(QueryValue::Query(Box::new(Self::parse_query_json(
                    value, allow_raw,
                )?)));
            }
        } else {
            for value in &values_arr {
                values.push(QueryValue::from_json(value));
            }
        }

        Ok(Self {
            method: method_enum,
            attribute,
            values,
            attribute_type: String::new(),
            on_array: false,
        })
    }

    pub fn parse_queries<I, S>(queries: I) -> Result<Vec<Self>, QueryError>
    where
        I: IntoIterator<Item = S>,
        S: AsRef<str>,
    {
        Self::parse_queries_allow_raw(queries, false)
    }

    pub fn parse_queries_allow_raw<I, S>(
        queries: I,
        allow_raw: bool,
    ) -> Result<Vec<Self>, QueryError>
    where
        I: IntoIterator<Item = S>,
        S: AsRef<str>,
    {
        queries
            .into_iter()
            .map(|q| Self::parse_allow_raw(q.as_ref(), allow_raw))
            .collect()
    }

    pub fn fingerprint(queries: &[FingerprintInput<'_>]) -> Result<String, QueryError> {
        let mut shapes = Vec::with_capacity(queries.len());
        for query in queries {
            let owned;
            let q = match query {
                FingerprintInput::Str(s) => {
                    owned = Self::parse(s)?;
                    &owned
                }
                FingerprintInput::Query(q) => *q,
            };
            shapes.push(q.shape());
        }
        shapes.sort();
        Ok(md5_hex(&shapes.join("|")))
    }

    pub fn fingerprint_queries(queries: &[Query]) -> Result<String, QueryError> {
        let inputs: Vec<FingerprintInput<'_>> =
            queries.iter().map(FingerprintInput::Query).collect();
        Self::fingerprint(&inputs)
    }

    pub fn fingerprint_strings<S: AsRef<str>>(queries: &[S]) -> Result<String, QueryError> {
        let owned: Vec<String> = queries.iter().map(|s| s.as_ref().to_owned()).collect();
        let inputs: Vec<FingerprintInput<'_>> = owned
            .iter()
            .map(|s| FingerprintInput::Str(s.as_str()))
            .collect();
        Self::fingerprint(&inputs)
    }

    pub fn shape(&self) -> String {
        let mut nodes: Vec<&Query> = Vec::new();
        let mut stack: Vec<&Query> = vec![self];
        while let Some(node) = stack.pop() {
            nodes.push(node);
            if !is_logical(node.method) {
                continue;
            }
            for child in &node.values {
                if let QueryValue::Query(q) = child {
                    stack.push(q.as_ref());
                }
            }
        }

        let mut shapes: Vec<(*const Query, String)> = Vec::new();
        let lookup = |shapes: &[(*const Query, String)], q: &Query| -> String {
            let key = query_id(q);
            shapes
                .iter()
                .rev()
                .find(|(k, _)| *k == key)
                .map(|(_, s)| s.clone())
                .unwrap_or_default()
        };

        for node in nodes.iter().rev() {
            let id = query_id(node);
            if !is_logical(node.method) {
                shapes.push((id, format!("{}:{}", node.method.as_str(), node.attribute)));
                continue;
            }
            let mut child_shapes = Vec::new();
            for child in &node.values {
                if let QueryValue::Query(q) = child {
                    child_shapes.push(lookup(&shapes, q.as_ref()));
                }
            }
            child_shapes.sort();
            shapes.push((
                id,
                format!(
                    "{}:{}({})",
                    node.method.as_str(),
                    node.attribute,
                    child_shapes.join("|")
                ),
            ));
        }

        lookup(&shapes, self)
    }

    pub fn to_array(&self) -> JsonValue {
        self.to_json_value()
    }

    pub fn to_json_value(&self) -> JsonValue {
        let mut map = Map::new();
        map.insert(
            "method".to_owned(),
            JsonValue::String(self.method.as_str().to_owned()),
        );
        if !self.attribute.is_empty() {
            map.insert(
                "attribute".to_owned(),
                JsonValue::String(self.attribute.clone()),
            );
        }
        if self.method.is_nested() {
            let mut values = Vec::new();
            for value in &self.values {
                if let QueryValue::Query(q) = value {
                    values.push(q.to_json_value());
                }
            }
            if !values.is_empty() {
                map.insert("values".to_owned(), JsonValue::Array(values));
            }
        } else {
            let values: Vec<JsonValue> = self.values.iter().map(QueryValue::to_json).collect();
            map.insert("values".to_owned(), JsonValue::Array(values));
        }
        JsonValue::Object(map)
    }

    pub fn compile(&self, compiler: &mut dyn Compiler) -> Result<String, QueryError> {
        match self.method {
            Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => {
                compiler.compile_order(self)
            }
            Method::Limit => compiler.compile_limit(self),
            Method::Offset => compiler.compile_offset(self),
            Method::CursorAfter | Method::CursorBefore => compiler.compile_cursor(self),
            Method::Select => compiler.compile_select(self),
            Method::Count
            | Method::CountDistinct
            | Method::Sum
            | Method::Avg
            | Method::Min
            | Method::Max
            | Method::Stddev
            | Method::StddevPop
            | Method::StddevSamp
            | Method::Variance
            | Method::VarPop
            | Method::VarSamp
            | Method::BitAnd
            | Method::BitOr
            | Method::BitXor => compiler.compile_aggregate(self),
            Method::GroupBy | Method::GroupByTimeBucket => compiler.compile_group_by(self),
            Method::Join
            | Method::LeftJoin
            | Method::RightJoin
            | Method::CrossJoin
            | Method::FullOuterJoin
            | Method::NaturalJoin => compiler.compile_join(self),
            _ => compiler.compile_filter(self),
        }
    }

    pub fn to_string(&self) -> Result<String, QueryError> {
        serde_json::to_string(&self.to_json_value())
            .map_err(|e| QueryError::exception(format!("Invalid Json: {e}")))
    }

    pub fn equal(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Self::new(Method::Equal, attribute, values)
    }

    pub fn not_equal(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        let value = value.into();
        let values = match value {
            QueryValue::Array(items) => items,
            QueryValue::Object(_) => vec![value],
            other => vec![other],
        };
        Self::new(Method::NotEqual, attribute, values)
    }

    pub fn less_than(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Method::LessThan, attribute, vec![value.into()])
    }

    pub fn less_than_equal(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Method::LessThanEqual, attribute, vec![value.into()])
    }

    pub fn greater_than(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Method::GreaterThan, attribute, vec![value.into()])
    }

    pub fn greater_than_equal(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Method::GreaterThanEqual, attribute, vec![value.into()])
    }

    pub fn contains(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Self::new(Method::Contains, attribute, values)
    }

    pub fn contains_string(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Self::new(Method::Contains, attribute, values)
    }

    pub fn contains_any(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Self::new(Method::ContainsAny, attribute, values)
    }

    pub fn not_contains(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Self::new(Method::NotContains, attribute, values)
    }

    pub fn between(
        attribute: impl Into<String>,
        start: impl Into<QueryValue>,
        end: impl Into<QueryValue>,
    ) -> Self {
        Self::new(Method::Between, attribute, vec![start.into(), end.into()])
    }

    pub fn not_between(
        attribute: impl Into<String>,
        start: impl Into<QueryValue>,
        end: impl Into<QueryValue>,
    ) -> Self {
        Self::new(
            Method::NotBetween,
            attribute,
            vec![start.into(), end.into()],
        )
    }

    pub fn search(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            Method::Search,
            attribute,
            vec![QueryValue::from(value.into())],
        )
    }

    pub fn not_search(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            Method::NotSearch,
            attribute,
            vec![QueryValue::from(value.into())],
        )
    }

    pub fn select(attributes: impl IntoValues) -> Self {
        Self::new(Method::Select, "", attributes)
    }

    pub fn order_desc(attribute: impl Into<String>, nulls: Option<NullsPosition>) -> Self {
        let values = match nulls {
            Some(n) => vec![QueryValue::NullsPosition(n)],
            None => vec![],
        };
        Self::new(Method::OrderDesc, attribute, values)
    }

    pub fn order_asc(attribute: impl Into<String>, nulls: Option<NullsPosition>) -> Self {
        let values = match nulls {
            Some(n) => vec![QueryValue::NullsPosition(n)],
            None => vec![],
        };
        Self::new(Method::OrderAsc, attribute, values)
    }

    pub fn order_random() -> Self {
        Self::new_method(Method::OrderRandom)
    }

    pub fn limit(value: i64) -> Self {
        Self::new(Method::Limit, "", vec![QueryValue::Int(value)])
    }

    pub fn offset(value: i64) -> Self {
        Self::new(Method::Offset, "", vec![QueryValue::Int(value)])
    }

    pub fn cursor_after(value: impl Into<QueryValue>) -> Self {
        Self::new(Method::CursorAfter, "", vec![value.into()])
    }

    pub fn cursor_before(value: impl Into<QueryValue>) -> Self {
        Self::new(Method::CursorBefore, "", vec![value.into()])
    }

    pub fn is_null(attribute: impl Into<String>) -> Self {
        Self::new(Method::IsNull, attribute, ())
    }

    pub fn is_not_null(attribute: impl Into<String>) -> Self {
        Self::new(Method::IsNotNull, attribute, ())
    }

    pub fn starts_with(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            Method::StartsWith,
            attribute,
            vec![QueryValue::from(value.into())],
        )
    }

    pub fn not_starts_with(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            Method::NotStartsWith,
            attribute,
            vec![QueryValue::from(value.into())],
        )
    }

    pub fn ends_with(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            Method::EndsWith,
            attribute,
            vec![QueryValue::from(value.into())],
        )
    }

    pub fn not_ends_with(attribute: impl Into<String>, value: impl Into<String>) -> Self {
        Self::new(
            Method::NotEndsWith,
            attribute,
            vec![QueryValue::from(value.into())],
        )
    }

    pub fn created_before(value: impl Into<String>) -> Self {
        Self::less_than("$createdAt", value.into())
    }

    pub fn created_after(value: impl Into<String>) -> Self {
        Self::greater_than("$createdAt", value.into())
    }

    pub fn updated_before(value: impl Into<String>) -> Self {
        Self::less_than("$updatedAt", value.into())
    }

    pub fn updated_after(value: impl Into<String>) -> Self {
        Self::greater_than("$updatedAt", value.into())
    }

    pub fn created_between(start: impl Into<String>, end: impl Into<String>) -> Self {
        Self::between("$createdAt", start.into(), end.into())
    }

    pub fn updated_between(start: impl Into<String>, end: impl Into<String>) -> Self {
        Self::between("$updatedAt", start.into(), end.into())
    }

    pub fn or(queries: impl IntoIterator<Item = Query>) -> Self {
        Self::new(
            Method::Or,
            "",
            queries
                .into_iter()
                .map(QueryValue::from)
                .collect::<Vec<_>>(),
        )
    }

    pub fn and(queries: impl IntoIterator<Item = Query>) -> Self {
        Self::new(
            Method::And,
            "",
            queries
                .into_iter()
                .map(QueryValue::from)
                .collect::<Vec<_>>(),
        )
    }

    pub fn contains_all(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Self::new(Method::ContainsAll, attribute, values)
    }

    pub fn get_by_type(queries: &[Query], types: &[Method], _clone: bool) -> Vec<Query> {
        queries
            .iter()
            .filter(|q| types.contains(&q.method))
            .cloned()
            .collect()
    }

    pub fn get_cursor_queries(queries: &[Query], clone: bool) -> Vec<Query> {
        Self::get_by_type(queries, &[Method::CursorAfter, Method::CursorBefore], clone)
    }

    pub fn group_by_type(queries: &[Query]) -> ParsedQuery {
        let mut filters = Vec::new();
        let mut selections = Vec::new();
        let mut aggregations = Vec::new();
        let mut group_by = Vec::new();
        let mut time_buckets = Vec::new();
        let mut having = Vec::new();
        let mut distinct = false;
        let mut joins = Vec::new();
        let mut unions = Vec::new();
        let mut limit = None;
        let mut offset = None;
        let mut cursor = None;
        let mut cursor_direction = None;

        for query in queries {
            let method = query.get_method();
            let values = query.get_values();
            match method {
                Method::OrderAsc | Method::OrderDesc | Method::OrderRandom => {}
                Method::Limit => {
                    if limit.is_none() {
                        if let Some(v) = values.first() {
                            if let Some(n) = php_is_numeric(v) {
                                limit = Some(n);
                            }
                        }
                    }
                }
                Method::Offset => {
                    if offset.is_none() {
                        if let Some(v) = values.first() {
                            if let Some(n) = php_is_numeric(v) {
                                offset = Some(n);
                            }
                        }
                    }
                }
                Method::CursorAfter | Method::CursorBefore => {
                    if cursor.is_none() {
                        cursor = values.first().cloned();
                        cursor_direction = Some(if method == Method::CursorAfter {
                            CursorDirection::After
                        } else {
                            CursorDirection::Before
                        });
                    }
                }
                Method::Select => selections.push(query.clone()),
                Method::Count
                | Method::CountDistinct
                | Method::Sum
                | Method::Avg
                | Method::Min
                | Method::Max
                | Method::Stddev
                | Method::StddevPop
                | Method::StddevSamp
                | Method::Variance
                | Method::VarPop
                | Method::VarSamp
                | Method::BitAnd
                | Method::BitOr
                | Method::BitXor => aggregations.push(query.clone()),
                Method::GroupBy => {
                    for col in values {
                        group_by.push(col.php_to_string());
                    }
                }
                Method::GroupByTimeBucket => {
                    let interval = values
                        .first()
                        .map(QueryValue::php_to_string)
                        .unwrap_or_default();
                    time_buckets.push(TimeBucket {
                        attribute: query.get_attribute().to_owned(),
                        interval,
                    });
                }
                Method::Having => having.push(query.clone()),
                Method::Distinct => distinct = true,
                Method::Join
                | Method::LeftJoin
                | Method::RightJoin
                | Method::CrossJoin
                | Method::FullOuterJoin
                | Method::NaturalJoin => joins.push(query.clone()),
                Method::Union | Method::UnionAll => unions.push(query.clone()),
                _ => filters.push(query.clone()),
            }
        }

        ParsedQuery {
            filters,
            selections,
            aggregations,
            group_by,
            having,
            distinct,
            joins,
            unions,
            limit,
            offset,
            cursor,
            cursor_direction,
            time_buckets,
        }
    }

    pub fn distance_equal(
        attribute: impl Into<String>,
        values: impl Into<QueryValue>,
        distance: impl Into<QueryValue>,
        meters: bool,
    ) -> Self {
        Self::spatial_distance(Method::DistanceEqual, attribute, values, distance, meters)
    }

    pub fn distance_not_equal(
        attribute: impl Into<String>,
        values: impl Into<QueryValue>,
        distance: impl Into<QueryValue>,
        meters: bool,
    ) -> Self {
        Self::spatial_distance(
            Method::DistanceNotEqual,
            attribute,
            values,
            distance,
            meters,
        )
    }

    pub fn distance_greater_than(
        attribute: impl Into<String>,
        values: impl Into<QueryValue>,
        distance: impl Into<QueryValue>,
        meters: bool,
    ) -> Self {
        Self::spatial_distance(
            Method::DistanceGreaterThan,
            attribute,
            values,
            distance,
            meters,
        )
    }

    pub fn distance_less_than(
        attribute: impl Into<String>,
        values: impl Into<QueryValue>,
        distance: impl Into<QueryValue>,
        meters: bool,
    ) -> Self {
        Self::spatial_distance(
            Method::DistanceLessThan,
            attribute,
            values,
            distance,
            meters,
        )
    }

    fn spatial_distance(
        method: Method,
        attribute: impl Into<String>,
        values: impl Into<QueryValue>,
        distance: impl Into<QueryValue>,
        meters: bool,
    ) -> Self {
        Self::new(
            method,
            attribute,
            vec![QueryValue::Array(vec![
                values.into(),
                distance.into(),
                QueryValue::Bool(meters),
            ])],
        )
    }

    pub fn intersects(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::Intersects, attribute, vec![values.into()])
    }

    pub fn not_intersects(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::NotIntersects, attribute, vec![values.into()])
    }

    pub fn crosses(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::Crosses, attribute, vec![values.into()])
    }

    pub fn not_crosses(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::NotCrosses, attribute, vec![values.into()])
    }

    pub fn overlaps(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::Overlaps, attribute, vec![values.into()])
    }

    pub fn not_overlaps(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::NotOverlaps, attribute, vec![values.into()])
    }

    pub fn touches(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::Touches, attribute, vec![values.into()])
    }

    pub fn not_touches(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::NotTouches, attribute, vec![values.into()])
    }

    pub fn vector_dot(attribute: impl Into<String>, vector: impl IntoValues) -> Self {
        Self::new(
            Method::VectorDot,
            attribute,
            vec![QueryValue::Array(vector.into_values())],
        )
    }

    pub fn vector_cosine(attribute: impl Into<String>, vector: impl IntoValues) -> Self {
        Self::new(
            Method::VectorCosine,
            attribute,
            vec![QueryValue::Array(vector.into_values())],
        )
    }

    pub fn vector_euclidean(attribute: impl Into<String>, vector: impl IntoValues) -> Self {
        Self::new(
            Method::VectorEuclidean,
            attribute,
            vec![QueryValue::Array(vector.into_values())],
        )
    }

    pub fn regex(attribute: impl Into<String>, pattern: impl Into<String>) -> Self {
        Self::new(
            Method::Regex,
            attribute,
            vec![QueryValue::from(pattern.into())],
        )
    }

    pub fn exists(attributes: impl IntoValues) -> Self {
        Self::new(Method::Exists, "", attributes)
    }

    pub fn not_exists(attribute: impl Into<QueryValue>) -> Self {
        let value = attribute.into();
        let values = match value {
            QueryValue::Array(items) => items,
            other => vec![other],
        };
        Self::new(Method::NotExists, "", values)
    }

    pub fn elem_match(
        attribute: impl Into<String>,
        queries: impl IntoIterator<Item = Query>,
    ) -> Self {
        Self::new(
            Method::ElemMatch,
            attribute,
            queries
                .into_iter()
                .map(QueryValue::from)
                .collect::<Vec<_>>(),
        )
    }

    pub fn count(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::Count, attribute, alias)
    }

    pub fn count_distinct(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::CountDistinct, attribute, alias)
    }

    pub fn sum(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::Sum, attribute, alias)
    }

    pub fn avg(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::Avg, attribute, alias)
    }

    pub fn min(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::Min, attribute, alias)
    }

    pub fn max(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::Max, attribute, alias)
    }

    pub fn stddev(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::Stddev, attribute, alias)
    }

    pub fn stddev_pop(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::StddevPop, attribute, alias)
    }

    pub fn stddev_samp(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::StddevSamp, attribute, alias)
    }

    pub fn variance(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::Variance, attribute, alias)
    }

    pub fn var_pop(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::VarPop, attribute, alias)
    }

    pub fn var_samp(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::VarSamp, attribute, alias)
    }

    pub fn bit_and(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::BitAnd, attribute, alias)
    }

    pub fn bit_or(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::BitOr, attribute, alias)
    }

    pub fn bit_xor(attribute: impl Into<String>, alias: impl Into<String>) -> Self {
        agg(Method::BitXor, attribute, alias)
    }

    pub fn group_by(attributes: impl IntoValues) -> Self {
        Self::new(Method::GroupBy, "", attributes)
    }

    pub fn group_by_time_bucket(
        attribute: impl Into<String>,
        interval: impl AsRef<str>,
    ) -> Result<Self, QueryError> {
        let interval = interval.as_ref();
        if !Self::GROUP_BY_TIME_BUCKET_INTERVALS.contains(&interval) {
            return Err(QueryError::validation(format!(
                "Invalid groupByTimeBucket interval: {interval}. Allowed: {}",
                Self::GROUP_BY_TIME_BUCKET_INTERVALS.join(", ")
            )));
        }
        Ok(Self::new(
            Method::GroupByTimeBucket,
            attribute,
            vec![QueryValue::from(interval)],
        ))
    }

    pub fn having(queries: impl IntoIterator<Item = Query>) -> Self {
        Self::new(
            Method::Having,
            "",
            queries
                .into_iter()
                .map(QueryValue::from)
                .collect::<Vec<_>>(),
        )
    }

    pub fn distinct() -> Self {
        Self::new_method(Method::Distinct)
    }

    pub fn join(
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
        operator: impl Into<String>,
        alias: impl Into<String>,
    ) -> Self {
        join_query(Method::Join, table, left, right, operator, alias)
    }

    pub fn left_join(
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
        operator: impl Into<String>,
        alias: impl Into<String>,
    ) -> Self {
        join_query(Method::LeftJoin, table, left, right, operator, alias)
    }

    pub fn right_join(
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
        operator: impl Into<String>,
        alias: impl Into<String>,
    ) -> Self {
        join_query(Method::RightJoin, table, left, right, operator, alias)
    }

    pub fn cross_join(table: impl Into<String>, alias: impl Into<String>) -> Self {
        let alias = alias.into();
        let values = if alias.is_empty() {
            vec![]
        } else {
            vec![QueryValue::from(alias)]
        };
        Self::new(Method::CrossJoin, table, values)
    }

    pub fn full_outer_join(
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
        operator: impl Into<String>,
        alias: impl Into<String>,
    ) -> Self {
        join_query(Method::FullOuterJoin, table, left, right, operator, alias)
    }

    pub fn natural_join(table: impl Into<String>, alias: impl Into<String>) -> Self {
        let alias = alias.into();
        let values = if alias.is_empty() {
            vec![]
        } else {
            vec![QueryValue::from(alias)]
        };
        Self::new(Method::NaturalJoin, table, values)
    }

    pub fn union(queries: impl IntoIterator<Item = Query>) -> Self {
        Self::new(
            Method::Union,
            "",
            queries
                .into_iter()
                .map(QueryValue::from)
                .collect::<Vec<_>>(),
        )
    }

    pub fn union_all(queries: impl IntoIterator<Item = Query>) -> Self {
        Self::new(
            Method::UnionAll,
            "",
            queries
                .into_iter()
                .map(QueryValue::from)
                .collect::<Vec<_>>(),
        )
    }

    pub fn json_contains(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Method::JsonContains, attribute, vec![value.into()])
    }

    pub fn json_not_contains(attribute: impl Into<String>, value: impl Into<QueryValue>) -> Self {
        Self::new(Method::JsonNotContains, attribute, vec![value.into()])
    }

    pub fn json_overlaps(attribute: impl Into<String>, values: impl IntoValues) -> Self {
        Self::new(
            Method::JsonOverlaps,
            attribute,
            vec![QueryValue::Array(values.into_values())],
        )
    }

    pub fn json_path(
        attribute: impl Into<String>,
        path: impl Into<String>,
        operator: impl Into<String>,
        value: impl Into<QueryValue>,
    ) -> Self {
        Self::new(
            Method::JsonPath,
            attribute,
            vec![
                QueryValue::from(path.into()),
                QueryValue::from(operator.into()),
                value.into(),
            ],
        )
    }

    pub fn covers(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::Covers, attribute, vec![values.into()])
    }

    pub fn not_covers(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::NotCovers, attribute, vec![values.into()])
    }

    pub fn spatial_equals(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::SpatialEquals, attribute, vec![values.into()])
    }

    pub fn not_spatial_equals(attribute: impl Into<String>, values: impl Into<QueryValue>) -> Self {
        Self::new(Method::NotSpatialEquals, attribute, vec![values.into()])
    }

    pub fn raw(sql: impl Into<String>, bindings: impl IntoValues) -> Self {
        Self::new(Method::Raw, sql, bindings)
    }

    pub fn page(page: i64, per_page: i64) -> Result<[Self; 2], QueryError> {
        if page < 1 {
            return Err(QueryError::validation(format!(
                "Page must be >= 1, got {page}"
            )));
        }
        if per_page < 1 {
            return Err(QueryError::validation(format!(
                "Per page must be >= 1, got {per_page}"
            )));
        }
        Ok([Self::limit(per_page), Self::offset((page - 1) * per_page)])
    }

    pub fn merge(queries_a: &[Query], queries_b: &[Query]) -> Vec<Query> {
        let singular = [
            Method::Limit,
            Method::Offset,
            Method::CursorAfter,
            Method::CursorBefore,
        ];
        let mut result: Vec<Query> = queries_a.to_vec();
        for query_b in queries_b {
            let method = query_b.get_method();
            if singular.contains(&method) {
                result.retain(|q| q.get_method() != method);
            }
            result.push(query_b.clone());
        }
        result
    }

    pub fn diff(queries_a: &[Query], queries_b: &[Query]) -> Vec<Query> {
        let b_arrays: Vec<JsonValue> = queries_b.iter().map(Query::to_json_value).collect();
        queries_a
            .iter()
            .filter(|query_a| {
                let a_array = query_a.to_json_value();
                !b_arrays.iter().any(|b| *b == a_array)
            })
            .cloned()
            .collect()
    }

    pub fn validate(queries: &[Query], allowed_attributes: &[&str]) -> Vec<String> {
        let skip = [
            Method::Limit,
            Method::Offset,
            Method::CursorAfter,
            Method::CursorBefore,
            Method::OrderRandom,
            Method::Distinct,
            Method::Select,
            Method::Exists,
            Method::NotExists,
        ];
        let mut errors = Vec::new();
        for query in queries {
            let method = query.get_method();
            if method.is_nested() {
                let nested: Vec<Query> = query
                    .get_values()
                    .iter()
                    .filter_map(QueryValue::as_query)
                    .cloned()
                    .collect();
                errors.extend(Self::validate(&nested, allowed_attributes));
                continue;
            }
            if skip.contains(&method) {
                continue;
            }
            if method == Method::GroupBy {
                for col in query.get_values() {
                    let col = col.php_to_string();
                    if !allowed_attributes.contains(&col.as_str()) {
                        errors.push(format!(
                            "Invalid attribute \"{col}\" used in {}",
                            method.as_str()
                        ));
                    }
                }
                continue;
            }
            let attribute = query.get_attribute();
            if attribute.is_empty() || attribute == "*" {
                continue;
            }
            if !allowed_attributes.contains(&attribute) {
                errors.push(format!(
                    "Invalid attribute \"{attribute}\" used in {}",
                    method.as_str()
                ));
            }
        }
        errors
    }
}

fn agg(method: Method, attribute: impl Into<String>, alias: impl Into<String>) -> Query {
    let alias = alias.into();
    let values = if alias.is_empty() {
        vec![]
    } else {
        vec![QueryValue::from(alias)]
    };
    Query::new(method, attribute, values)
}

fn join_query(
    method: Method,
    table: impl Into<String>,
    left: impl Into<String>,
    right: impl Into<String>,
    operator: impl Into<String>,
    alias: impl Into<String>,
) -> Query {
    let mut values = vec![
        QueryValue::from(left.into()),
        QueryValue::from(operator.into()),
        QueryValue::from(right.into()),
    ];
    let alias = alias.into();
    if !alias.is_empty() {
        values.push(QueryValue::from(alias));
    }
    Query::new(method, table, values)
}

fn is_logical(method: Method) -> bool {
    matches!(method, Method::And | Method::Or | Method::ElemMatch)
}

fn query_id(query: &Query) -> *const Query {
    query
}

fn php_is_numeric(value: &QueryValue) -> Option<i64> {
    match value {
        QueryValue::Int(n) => Some(*n),
        QueryValue::UInt(n) => i64::try_from(*n).ok(),
        QueryValue::Float(n) => Some(*n as i64),
        QueryValue::String(s) => {
            if let Ok(n) = s.parse::<i64>() {
                Some(n)
            } else {
                s.parse::<f64>().ok().map(|n| n as i64)
            }
        }
        _ => None,
    }
}

fn md5_hex(s: &str) -> String {
    format!("{:x}", md5::compute(s.as_bytes()))
}

/// PHP `Method|string`.
pub trait IntoMethod {
    fn into_method(self) -> Method;
}

impl IntoMethod for Method {
    fn into_method(self) -> Method {
        self
    }
}

impl IntoMethod for &str {
    fn into_method(self) -> Method {
        Method::try_from_value(self).unwrap_or_else(|| panic!("Invalid query method: {self}"))
    }
}

impl IntoMethod for String {
    fn into_method(self) -> Method {
        self.as_str().into_method()
    }
}

/// Input to [`Query::fingerprint`].
#[derive(Clone, Copy, Debug)]
pub enum FingerprintInput<'a> {
    Str(&'a str),
    Query(&'a Query),
}

impl<'a> From<&'a str> for FingerprintInput<'a> {
    fn from(s: &'a str) -> Self {
        Self::Str(s)
    }
}

impl<'a> From<&'a Query> for FingerprintInput<'a> {
    fn from(q: &'a Query) -> Self {
        Self::Query(q)
    }
}

impl Query {
    /// Convenience default-arg wrappers matching PHP signatures.
    pub fn order_asc_attr(attribute: impl Into<String>) -> Self {
        Self::order_asc(attribute, None)
    }

    pub fn order_desc_attr(attribute: impl Into<String>) -> Self {
        Self::order_desc(attribute, None)
    }

    pub fn count_star() -> Self {
        Self::count("*", "")
    }

    pub fn join_eq(
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
    ) -> Self {
        Self::join(table, left, right, "=", "")
    }

    pub fn left_join_eq(
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
    ) -> Self {
        Self::left_join(table, left, right, "=", "")
    }

    pub fn right_join_eq(
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
    ) -> Self {
        Self::right_join(table, left, right, "=", "")
    }

    pub fn full_outer_join_eq(
        table: impl Into<String>,
        left: impl Into<String>,
        right: impl Into<String>,
    ) -> Self {
        Self::full_outer_join(table, left, right, "=", "")
    }

    pub fn raw_sql(sql: impl Into<String>) -> Self {
        Self::raw(sql, ())
    }

    pub fn page_default(page: i64) -> Result<[Self; 2], QueryError> {
        Self::page(page, 25)
    }

    pub fn distance_less_than_default(
        attribute: impl Into<String>,
        values: impl Into<QueryValue>,
        distance: impl Into<QueryValue>,
    ) -> Self {
        Self::distance_less_than(attribute, values, distance, false)
    }
}
