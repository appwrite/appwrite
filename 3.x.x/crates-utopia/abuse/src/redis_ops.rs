use std::collections::BTreeMap;

use redis::cluster::ClusterConnection;
use redis::{Commands, ConnectionLike, Value};

use crate::error::AbuseError;
use crate::redis_pool::PooledRedis;

/// Redis Cluster connection seam. Implemented for `redis::cluster::ClusterConnection`.
pub trait ClusterConnectionExt: Send {
    /// GET a string key.
    ///
    /// # Errors
    ///
    /// Redis protocol / connection errors.
    fn get_string(&mut self, key: &str) -> Result<Option<String>, AbuseError>;

    /// EVAL a Lua script.
    ///
    /// # Errors
    ///
    /// Redis protocol / connection errors.
    fn eval_script(
        &mut self,
        script: &str,
        keys: &[String],
        argv: &[String],
    ) -> Result<Value, AbuseError>;

    /// DEL one or more keys.
    ///
    /// # Errors
    ///
    /// Redis protocol / connection errors.
    fn delete_keys(&mut self, keys: &[&str]) -> Result<(), AbuseError>;

    /// SCAN (and cluster-master SCAN) matching `pattern`.
    ///
    /// # Errors
    ///
    /// Redis protocol / connection errors.
    fn scan_pattern(&mut self, pattern: &str) -> Result<Vec<String>, AbuseError>;

    /// MGET.
    ///
    /// # Errors
    ///
    /// Redis protocol / connection errors.
    fn mget_strings(&mut self, keys: &[String]) -> Result<Vec<Option<String>>, AbuseError>;

    /// HGETALL.
    ///
    /// # Errors
    ///
    /// Redis protocol / connection errors.
    fn hash_get_all(&mut self, key: &str) -> Result<BTreeMap<String, String>, AbuseError>;

    /// INCR + EXPIRE in MULTI/EXEC.
    ///
    /// # Errors
    ///
    /// Redis protocol / connection errors.
    fn incr_expire(&mut self, key: &str, ttl: i64) -> Result<(), AbuseError>;

    /// SET + EXPIRE in MULTI/EXEC.
    ///
    /// # Errors
    ///
    /// Redis protocol / connection errors.
    fn set_expire(&mut self, key: &str, value: &str, ttl: i64) -> Result<(), AbuseError>;
}

impl ClusterConnectionExt for ClusterConnection {
    fn get_string(&mut self, key: &str) -> Result<Option<String>, AbuseError> {
        get_string(self, key)
    }

    fn eval_script(
        &mut self,
        script: &str,
        keys: &[String],
        argv: &[String],
    ) -> Result<Value, AbuseError> {
        eval_script(self, script, keys, argv)
    }

    fn delete_keys(&mut self, keys: &[&str]) -> Result<(), AbuseError> {
        delete_keys(self, keys)
    }

    fn scan_pattern(&mut self, pattern: &str) -> Result<Vec<String>, AbuseError> {
        scan_all(self, pattern)
    }

    fn mget_strings(&mut self, keys: &[String]) -> Result<Vec<Option<String>>, AbuseError> {
        mget_strings(self, keys)
    }

    fn hash_get_all(&mut self, key: &str) -> Result<BTreeMap<String, String>, AbuseError> {
        hash_get_all(self, key)
    }

    fn incr_expire(&mut self, key: &str, ttl: i64) -> Result<(), AbuseError> {
        incr_expire(self, key, ttl)
    }

    fn set_expire(&mut self, key: &str, value: &str, ttl: i64) -> Result<(), AbuseError> {
        set_expire(self, key, value, ttl)
    }
}

pub(crate) fn get_string<C: Commands>(
    conn: &mut C,
    key: &str,
) -> Result<Option<String>, AbuseError> {
    let value: Option<String> = conn.get(key)?;
    Ok(value)
}

pub(crate) fn eval_script<C: ConnectionLike>(
    conn: &mut C,
    script: &str,
    keys: &[String],
    argv: &[String],
) -> Result<Value, AbuseError> {
    let mut command = redis::cmd("EVAL");
    command.arg(script).arg(keys.len());
    for key in keys {
        command.arg(key);
    }
    for arg in argv {
        command.arg(arg);
    }
    Ok(conn.req_command(&command)?)
}

pub(crate) fn delete_keys<C: Commands>(conn: &mut C, keys: &[&str]) -> Result<(), AbuseError> {
    if keys.is_empty() {
        return Ok(());
    }
    let _: () = conn.del(keys)?;
    Ok(())
}

pub(crate) fn incr_expire<C: ConnectionLike>(
    conn: &mut C,
    key: &str,
    ttl: i64,
) -> Result<(), AbuseError> {
    let mut pipe = redis::pipe();
    pipe.atomic()
        .cmd("INCR")
        .arg(key)
        .cmd("EXPIRE")
        .arg(key)
        .arg(ttl);
    let _: Vec<Value> = pipe.query(conn)?;
    Ok(())
}

pub(crate) fn incr_expire_checked<C: ConnectionLike>(
    conn: &mut C,
    key: &str,
    ttl: i64,
) -> Result<(), AbuseError> {
    let mut pipe = redis::pipe();
    pipe.atomic()
        .cmd("INCR")
        .arg(key)
        .cmd("EXPIRE")
        .arg(key)
        .arg(ttl);
    let result: Vec<Value> = pipe.query(conn)?;
    if result.iter().any(is_redis_false) {
        return Err(AbuseError::RedisTransaction);
    }
    Ok(())
}

pub(crate) fn set_expire<C: ConnectionLike>(
    conn: &mut C,
    key: &str,
    value: &str,
    ttl: i64,
) -> Result<(), AbuseError> {
    let mut pipe = redis::pipe();
    pipe.atomic()
        .cmd("SET")
        .arg(key)
        .arg(value)
        .cmd("EXPIRE")
        .arg(key)
        .arg(ttl);
    let _: Vec<Value> = pipe.query(conn)?;
    Ok(())
}

pub(crate) fn set_expire_checked<C: ConnectionLike>(
    conn: &mut C,
    key: &str,
    value: &str,
    ttl: i64,
) -> Result<(), AbuseError> {
    let mut pipe = redis::pipe();
    pipe.atomic()
        .cmd("SET")
        .arg(key)
        .arg(value)
        .cmd("EXPIRE")
        .arg(key)
        .arg(ttl);
    let result: Vec<Value> = pipe.query(conn)?;
    if result.iter().any(is_redis_false) {
        return Err(AbuseError::RedisTransaction);
    }
    Ok(())
}

pub(crate) fn scan_once<C: ConnectionLike>(
    conn: &mut C,
    pattern: &str,
    count: i64,
) -> Result<Vec<String>, AbuseError> {
    let (next, keys): (u64, Vec<String>) = redis::cmd("SCAN")
        .arg(0_u64)
        .arg("MATCH")
        .arg(pattern)
        .arg("COUNT")
        .arg(count)
        .query(conn)?;
    let _ = next;
    Ok(keys)
}

pub(crate) fn scan_all<C: ConnectionLike>(
    conn: &mut C,
    pattern: &str,
) -> Result<Vec<String>, AbuseError> {
    let mut cursor: u64 = 0;
    let mut matches = Vec::new();
    loop {
        let (next, keys): (u64, Vec<String>) = redis::cmd("SCAN")
            .arg(cursor)
            .arg("MATCH")
            .arg(pattern)
            .arg("COUNT")
            .arg(100)
            .query(conn)?;
        matches.extend(keys);
        cursor = next;
        if cursor == 0 {
            break;
        }
    }
    Ok(matches)
}

pub(crate) fn mget_strings<C: ConnectionLike>(
    conn: &mut C,
    keys: &[String],
) -> Result<Vec<Option<String>>, AbuseError> {
    if keys.is_empty() {
        return Ok(Vec::new());
    }
    let mut command = redis::cmd("MGET");
    for key in keys {
        command.arg(key);
    }
    Ok(command.query(conn)?)
}

pub(crate) fn hash_get_all<C: Commands>(
    conn: &mut C,
    key: &str,
) -> Result<BTreeMap<String, String>, AbuseError> {
    Ok(conn.hgetall(key)?)
}

fn is_redis_false(value: &Value) -> bool {
    match value {
        Value::Int(0) | Value::Boolean(false) | Value::Nil => true,
        Value::SimpleString(status) => status.eq_ignore_ascii_case("false"),
        Value::BulkString(data) => data == b"0" || data.eq_ignore_ascii_case(b"false"),
        _ => false,
    }
}

pub(crate) fn value_as_i64(value: &Value) -> i64 {
    match value {
        Value::Int(number) => *number,
        Value::Double(number) => *number as i64,
        Value::BulkString(data) => std::str::from_utf8(data)
            .ok()
            .and_then(|text| text.parse().ok())
            .unwrap_or(0),
        Value::SimpleString(text) => text.parse().unwrap_or(0),
        _ => 0,
    }
}

pub(crate) fn value_as_string(value: &Value) -> String {
    match value {
        Value::BulkString(data) => String::from_utf8_lossy(data).into_owned(),
        Value::SimpleString(status) => status.clone(),
        Value::Int(number) => number.to_string(),
        Value::Double(number) => number.to_string(),
        _ => String::new(),
    }
}

pub(crate) fn bulk_values(value: Value) -> Result<Vec<Value>, AbuseError> {
    match value {
        Value::Array(items) => Ok(items),
        other => Err(AbuseError::Message(format!(
            "unexpected Redis EVAL result: {other:?}"
        ))),
    }
}

pub(crate) fn slice_logs(
    mut keys: Vec<String>,
    offset: Option<i64>,
    limit: Option<i64>,
) -> Vec<String> {
    keys.sort();
    let offset = usize::try_from(offset.unwrap_or(0)).unwrap_or(0);
    let limit = usize::try_from(limit.unwrap_or(25)).unwrap_or(25);
    if offset >= keys.len() {
        return Vec::new();
    }
    keys.into_iter().skip(offset).take(limit).collect()
}

impl PooledRedis {
    pub(crate) fn get_string(&mut self, key: &str) -> Result<Option<String>, AbuseError> {
        match self {
            Self::Standalone(conn) => get_string(conn, key),
            Self::Cluster(conn) => get_string(conn, key),
        }
    }

    pub(crate) fn eval_script(
        &mut self,
        script: &str,
        keys: &[String],
        argv: &[String],
    ) -> Result<Value, AbuseError> {
        match self {
            Self::Standalone(conn) => eval_script(conn, script, keys, argv),
            Self::Cluster(conn) => eval_script(conn, script, keys, argv),
        }
    }

    pub(crate) fn delete_keys(&mut self, keys: &[&str]) -> Result<(), AbuseError> {
        match self {
            Self::Standalone(conn) => delete_keys(conn, keys),
            Self::Cluster(conn) => delete_keys(conn, keys),
        }
    }

    pub(crate) fn incr_expire_checked(&mut self, key: &str, ttl: i64) -> Result<(), AbuseError> {
        match self {
            Self::Standalone(conn) => incr_expire_checked(conn, key, ttl),
            Self::Cluster(conn) => incr_expire_checked(conn, key, ttl),
        }
    }

    pub(crate) fn set_expire_checked(
        &mut self,
        key: &str,
        value: &str,
        ttl: i64,
    ) -> Result<(), AbuseError> {
        match self {
            Self::Standalone(conn) => set_expire_checked(conn, key, value, ttl),
            Self::Cluster(conn) => set_expire_checked(conn, key, value, ttl),
        }
    }

    pub(crate) fn scan_all(&mut self, pattern: &str) -> Result<Vec<String>, AbuseError> {
        match self {
            Self::Standalone(conn) => scan_all(conn, pattern),
            Self::Cluster(conn) => scan_all(conn, pattern),
        }
    }

    pub(crate) fn hash_get_all(
        &mut self,
        key: &str,
    ) -> Result<BTreeMap<String, String>, AbuseError> {
        match self {
            Self::Standalone(conn) => hash_get_all(conn, key),
            Self::Cluster(conn) => hash_get_all(conn, key),
        }
    }

    pub(crate) fn mget_strings(
        &mut self,
        keys: &[String],
    ) -> Result<Vec<Option<String>>, AbuseError> {
        match self {
            Self::Standalone(conn) => mget_strings(conn, keys),
            Self::Cluster(conn) => mget_strings(conn, keys),
        }
    }
}
