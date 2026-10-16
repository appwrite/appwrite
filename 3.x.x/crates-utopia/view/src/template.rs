//! PHP-subset `.phtml` interpreter for Utopia View templates.

use serde_json::{Map, Value};

use crate::error::ViewError;
use crate::view::{php_truthy, value_to_string, ExecArg, PrintFilter, View};

pub(crate) fn render_template(source: &str, view: &View) -> Result<String, ViewError> {
    if !has_php_tags(source) {
        return Ok(source.to_owned());
    }
    let mut parser = Parser::new(source);
    let nodes = parser.parse_document()?;
    let mut scope = Vec::new();
    eval_nodes(&nodes, view, &mut scope)
}

fn has_php_tags(source: &str) -> bool {
    let lower = source.to_ascii_lowercase();
    lower.contains("<?php") || source.contains("<?=")
}

#[derive(Debug, Clone)]
enum Node {
    Html(String),
    Echo(Expr),
    If {
        cond: Expr,
        then_body: Vec<Node>,
        elseifs: Vec<(Expr, Vec<Node>)>,
        else_body: Vec<Node>,
    },
    Foreach {
        iter: Expr,
        value_var: String,
        key_var: Option<String>,
        body: Vec<Node>,
    },
}

#[derive(Debug, Clone)]
enum Expr {
    Null,
    Bool(bool),
    Number(serde_json::Number),
    String(String),
    Array(Vec<Expr>),
    Object(Vec<(Expr, Expr)>),
    Var(String),
    Index(Box<Expr>, Box<Expr>),
    Concat(Box<Expr>, Box<Expr>),
    Not(Box<Expr>),
    GetParam {
        path: Box<Expr>,
        default: Option<Box<Expr>>,
    },
    Print {
        value: Box<Expr>,
        filter: Option<Box<Expr>>,
    },
    Exec {
        arg: Box<Expr>,
    },
}

struct Parser<'a> {
    src: &'a str,
    pos: usize,
}

#[derive(Clone, Copy, PartialEq, Eq)]
enum BodyCtx {
    Top,
    If,
    Foreach,
}

enum Stop {
    Eof,
    EndIf,
    Else,
    ElseIf(Expr),
    EndForeach,
}

impl<'a> Parser<'a> {
    fn new(src: &'a str) -> Self {
        Self { src, pos: 0 }
    }

    fn rest(&self) -> &'a str {
        &self.src[self.pos..]
    }

    fn peek(&self) -> Option<char> {
        self.rest().chars().next()
    }

    fn advance(&mut self) -> Option<char> {
        let c = self.peek()?;
        self.pos += c.len_utf8();
        Some(c)
    }

    fn starts_ascii_ci(&self, s: &str) -> bool {
        let rest = self.rest();
        rest.len() >= s.len()
            && rest.is_char_boundary(s.len())
            && rest[..s.len()].eq_ignore_ascii_case(s)
    }

    fn skip_ws(&mut self) {
        while matches!(
            self.peek(),
            Some(' ' | '\t' | '\n' | '\r' | '\0' | '\x0B' | '\x0C')
        ) {
            self.advance();
        }
    }

    fn error(&self, msg: impl Into<String>) -> ViewError {
        ViewError::Template(format!("{} (at byte {})", msg.into(), self.pos))
    }

    fn parse_document(&mut self) -> Result<Vec<Node>, ViewError> {
        let (nodes, stop) = self.parse_body(BodyCtx::Top)?;
        match stop {
            Stop::Eof => Ok(nodes),
            Stop::EndIf => Err(self.error("unexpected endif")),
            Stop::Else => Err(self.error("unexpected else")),
            Stop::ElseIf(_) => Err(self.error("unexpected elseif")),
            Stop::EndForeach => Err(self.error("unexpected endforeach")),
        }
    }

    fn parse_body(&mut self, ctx: BodyCtx) -> Result<(Vec<Node>, Stop), ViewError> {
        let mut nodes = Vec::new();
        loop {
            if self.pos >= self.src.len() {
                return Ok((nodes, Stop::Eof));
            }
            if let Some(tag_at) = find_next_php_tag(self.rest()) {
                if tag_at > 0 {
                    nodes.push(Node::Html(self.rest()[..tag_at].to_owned()));
                    self.pos += tag_at;
                }
                match self.parse_php_tag(ctx)? {
                    PhpTag::HtmlEcho(expr) => nodes.push(Node::Echo(expr)),
                    PhpTag::If(node) | PhpTag::Foreach(node) => nodes.push(node),
                    PhpTag::Stop(stop) => return Ok((nodes, stop)),
                }
            } else {
                nodes.push(Node::Html(self.rest().to_owned()));
                self.pos = self.src.len();
                return Ok((nodes, Stop::Eof));
            }
        }
    }

    fn parse_php_tag(&mut self, ctx: BodyCtx) -> Result<PhpTag, ViewError> {
        if self.rest().starts_with("<?=") {
            self.pos += 3;
            self.skip_ws();
            let expr = self.parse_expr()?;
            self.skip_ws();
            if self.peek() == Some(';') {
                self.advance();
                self.skip_ws();
            }
            self.expect_close_tag()?;
            return Ok(PhpTag::HtmlEcho(expr));
        }

        if self.starts_ascii_ci("<?php") {
            self.pos += 5;
            self.skip_ws();
            return self.parse_php_statement(ctx);
        }

        Err(self.error("expected PHP tag"))
    }

    fn parse_php_statement(&mut self, ctx: BodyCtx) -> Result<PhpTag, ViewError> {
        if self.eat_keyword("echo") {
            self.skip_ws();
            let expr = self.parse_expr()?;
            self.skip_ws();
            self.eat_char(';');
            self.skip_ws();
            self.expect_close_tag()?;
            return Ok(PhpTag::HtmlEcho(expr));
        }

        if self.eat_keyword("if") {
            self.skip_ws();
            let cond = self.parse_paren_expr()?;
            self.skip_ws();
            self.expect_char(':')?;
            self.skip_ws();
            self.expect_close_tag()?;
            return Ok(PhpTag::If(self.parse_if_remainder(cond)?));
        }

        if self.eat_keyword("elseif") {
            if ctx != BodyCtx::If {
                return Err(self.error("elseif outside if"));
            }
            self.skip_ws();
            let cond = self.parse_paren_expr()?;
            self.skip_ws();
            self.expect_char(':')?;
            self.skip_ws();
            self.expect_close_tag()?;
            return Ok(PhpTag::Stop(Stop::ElseIf(cond)));
        }

        if self.eat_keyword("else") {
            if ctx != BodyCtx::If {
                return Err(self.error("else outside if"));
            }
            self.skip_ws();
            self.expect_char(':')?;
            self.skip_ws();
            self.expect_close_tag()?;
            return Ok(PhpTag::Stop(Stop::Else));
        }

        if self.eat_keyword("endif") {
            if ctx != BodyCtx::If {
                return Err(self.error("endif outside if"));
            }
            self.skip_ws();
            self.eat_char(';');
            self.skip_ws();
            self.expect_close_tag()?;
            return Ok(PhpTag::Stop(Stop::EndIf));
        }

        if self.eat_keyword("foreach") {
            self.skip_ws();
            let (iter, key_var, value_var) = self.parse_foreach_header()?;
            self.skip_ws();
            self.expect_char(':')?;
            self.skip_ws();
            self.expect_close_tag()?;
            let (body, stop) = self.parse_body(BodyCtx::Foreach)?;
            match stop {
                Stop::EndForeach => Ok(PhpTag::Foreach(Node::Foreach {
                    iter,
                    value_var,
                    key_var,
                    body,
                })),
                Stop::Eof => Err(self.error("unclosed foreach")),
                _ => Err(self.error("unexpected token in foreach")),
            }
        } else if self.eat_keyword("endforeach") {
            if ctx != BodyCtx::Foreach {
                return Err(self.error("endforeach outside foreach"));
            }
            self.skip_ws();
            self.eat_char(';');
            self.skip_ws();
            self.expect_close_tag()?;
            Ok(PhpTag::Stop(Stop::EndForeach))
        } else {
            Err(self.error("unsupported PHP statement in view template"))
        }
    }

    fn parse_if_remainder(&mut self, cond: Expr) -> Result<Node, ViewError> {
        let (then_body, mut stop) = self.parse_body(BodyCtx::If)?;
        let mut elseifs = Vec::new();
        loop {
            match stop {
                Stop::EndIf => {
                    return Ok(Node::If {
                        cond,
                        then_body,
                        elseifs,
                        else_body: Vec::new(),
                    });
                }
                Stop::ElseIf(next_cond) => {
                    let (body, next) = self.parse_body(BodyCtx::If)?;
                    elseifs.push((next_cond, body));
                    stop = next;
                }
                Stop::Else => {
                    let (else_body, next) = self.parse_body(BodyCtx::If)?;
                    return match next {
                        Stop::EndIf => Ok(Node::If {
                            cond,
                            then_body,
                            elseifs,
                            else_body,
                        }),
                        _ => Err(self.error("unclosed else")),
                    };
                }
                Stop::Eof => return Err(self.error("unclosed if")),
                Stop::EndForeach => return Err(self.error("unexpected endforeach in if")),
            }
        }
    }

    fn parse_foreach_header(&mut self) -> Result<(Expr, Option<String>, String), ViewError> {
        self.expect_char('(')?;
        self.skip_ws();
        let iter = self.parse_expr()?;
        self.skip_ws();
        if !self.eat_keyword("as") {
            return Err(self.error("expected 'as' in foreach"));
        }
        self.skip_ws();
        let first = self.parse_variable_name()?;
        self.skip_ws();
        if self.rest().starts_with("=>") {
            self.pos += 2;
            self.skip_ws();
            let value = self.parse_variable_name()?;
            self.skip_ws();
            self.expect_char(')')?;
            Ok((iter, Some(first), value))
        } else {
            self.expect_char(')')?;
            Ok((iter, None, first))
        }
    }

    fn parse_variable_name(&mut self) -> Result<String, ViewError> {
        self.expect_char('$')?;
        self.parse_ident()
    }

    fn parse_paren_expr(&mut self) -> Result<Expr, ViewError> {
        self.expect_char('(')?;
        self.skip_ws();
        let expr = self.parse_expr()?;
        self.skip_ws();
        self.expect_char(')')?;
        Ok(expr)
    }

    fn parse_expr(&mut self) -> Result<Expr, ViewError> {
        self.skip_ws();
        if self.peek() == Some('!') {
            self.advance();
            self.skip_ws();
            let inner = self.parse_expr_concat()?;
            return Ok(Expr::Not(Box::new(inner)));
        }
        self.parse_expr_concat()
    }

    fn parse_expr_concat(&mut self) -> Result<Expr, ViewError> {
        let mut left = self.parse_postfix()?;
        loop {
            self.skip_ws();
            if self.peek() == Some('.') {
                let next = self.rest().as_bytes().get(1).copied();
                if next == Some(b'.') {
                    break;
                }
                self.advance();
                self.skip_ws();
                let right = self.parse_postfix()?;
                left = Expr::Concat(Box::new(left), Box::new(right));
            } else {
                break;
            }
        }
        Ok(left)
    }

    fn parse_postfix(&mut self) -> Result<Expr, ViewError> {
        let mut expr = self.parse_primary()?;
        loop {
            self.skip_ws();
            if self.peek() == Some('[') {
                self.advance();
                self.skip_ws();
                let key = self.parse_expr()?;
                self.skip_ws();
                self.expect_char(']')?;
                expr = Expr::Index(Box::new(expr), Box::new(key));
            } else {
                break;
            }
        }
        Ok(expr)
    }

    fn parse_primary(&mut self) -> Result<Expr, ViewError> {
        self.skip_ws();
        match self.peek() {
            Some('(') => {
                self.advance();
                self.skip_ws();
                let expr = self.parse_expr()?;
                self.skip_ws();
                self.expect_char(')')?;
                Ok(expr)
            }
            Some('[') => self.parse_array(),
            Some('$') => self.parse_var_or_this(),
            Some('\'' | '"') => Ok(Expr::String(self.parse_string()?)),
            Some(c) if c.is_ascii_digit() || c == '-' => self.parse_number(),
            Some(_) if self.eat_keyword("null") => Ok(Expr::Null),
            Some(_) if self.eat_keyword("true") => Ok(Expr::Bool(true)),
            Some(_) if self.eat_keyword("false") => Ok(Expr::Bool(false)),
            _ => Err(self.error("expected expression")),
        }
    }

    fn parse_array(&mut self) -> Result<Expr, ViewError> {
        self.expect_char('[')?;
        self.skip_ws();
        if self.peek() == Some(']') {
            self.advance();
            return Ok(Expr::Array(Vec::new()));
        }
        let mut elems = Vec::new();
        let mut pairs = Vec::new();
        let mut assoc = false;
        loop {
            self.skip_ws();
            let first = self.parse_expr()?;
            self.skip_ws();
            if self.rest().starts_with("=>") {
                assoc = true;
                self.pos += 2;
                self.skip_ws();
                let value = self.parse_expr()?;
                pairs.push((first, value));
            } else {
                elems.push(first);
            }
            self.skip_ws();
            if self.peek() == Some(',') {
                self.advance();
                self.skip_ws();
                if self.peek() == Some(']') {
                    self.advance();
                    break;
                }
                continue;
            }
            self.expect_char(']')?;
            break;
        }
        if assoc {
            if !elems.is_empty() {
                return Err(self.error("mixed list/assoc array is not supported"));
            }
            Ok(Expr::Object(pairs))
        } else {
            Ok(Expr::Array(elems))
        }
    }

    fn parse_var_or_this(&mut self) -> Result<Expr, ViewError> {
        self.expect_char('$')?;
        let name = self.parse_ident()?;
        if name == "this" {
            self.skip_ws();
            if !self.rest().starts_with("->") {
                return Err(self.error("expected $this->method"));
            }
            self.pos += 2;
            self.skip_ws();
            let method = self.parse_ident()?;
            self.skip_ws();
            self.expect_char('(')?;
            let args = self.parse_arg_list()?;
            return match method.to_ascii_lowercase().as_str() {
                "getparam" => {
                    if args.is_empty() || args.len() > 2 {
                        return Err(self.error("getParam expects 1 or 2 arguments"));
                    }
                    Ok(Expr::GetParam {
                        path: Box::new(args[0].clone()),
                        default: args.get(1).cloned().map(Box::new),
                    })
                }
                "print" => {
                    if args.is_empty() || args.len() > 2 {
                        return Err(self.error("print expects 1 or 2 arguments"));
                    }
                    Ok(Expr::Print {
                        value: Box::new(args[0].clone()),
                        filter: args.get(1).cloned().map(Box::new),
                    })
                }
                "exec" => {
                    if args.len() != 1 {
                        return Err(self.error("exec expects 1 argument"));
                    }
                    Ok(Expr::Exec {
                        arg: Box::new(args[0].clone()),
                    })
                }
                _ => Err(self.error(format!("unsupported $this->{method}()"))),
            };
        }
        Ok(Expr::Var(name))
    }

    fn parse_arg_list(&mut self) -> Result<Vec<Expr>, ViewError> {
        self.skip_ws();
        if self.peek() == Some(')') {
            self.advance();
            return Ok(Vec::new());
        }
        let mut args = Vec::new();
        loop {
            args.push(self.parse_expr()?);
            self.skip_ws();
            if self.peek() == Some(',') {
                self.advance();
                self.skip_ws();
                continue;
            }
            self.expect_char(')')?;
            break;
        }
        Ok(args)
    }

    fn parse_number(&mut self) -> Result<Expr, ViewError> {
        let start = self.pos;
        if self.peek() == Some('-') {
            self.advance();
        }
        while self.peek().is_some_and(|c| c.is_ascii_digit()) {
            self.advance();
        }
        if self.peek() == Some('.') {
            let rest = self.rest();
            if rest.len() > 1 && rest.as_bytes().get(1).is_some_and(u8::is_ascii_digit) {
                self.advance();
                while self.peek().is_some_and(|c| c.is_ascii_digit()) {
                    self.advance();
                }
            }
        }
        let raw = &self.src[start..self.pos];
        let number = raw
            .parse::<serde_json::Number>()
            .or_else(|_| {
                raw.parse::<f64>()
                    .ok()
                    .and_then(serde_json::Number::from_f64)
                    .ok_or(())
            })
            .map_err(|()| self.error(format!("invalid number {raw}")))?;
        Ok(Expr::Number(number))
    }

    fn parse_string(&mut self) -> Result<String, ViewError> {
        let quote = self
            .advance()
            .ok_or_else(|| self.error("expected string"))?;
        let mut out = String::new();
        while let Some(c) = self.peek() {
            if c == quote {
                self.advance();
                return Ok(out);
            }
            if c == '\\' {
                self.advance();
                match self.peek() {
                    Some('\\') => {
                        self.advance();
                        out.push('\\');
                    }
                    Some(q) if q == quote => {
                        self.advance();
                        out.push(quote);
                    }
                    Some('n') if quote == '"' => {
                        self.advance();
                        out.push('\n');
                    }
                    Some('t') if quote == '"' => {
                        self.advance();
                        out.push('\t');
                    }
                    Some('r') if quote == '"' => {
                        self.advance();
                        out.push('\r');
                    }
                    Some(other) => {
                        self.advance();
                        out.push(other);
                    }
                    None => break,
                }
            } else {
                out.push(c);
                self.advance();
            }
        }
        Err(self.error("unterminated string"))
    }

    fn parse_ident(&mut self) -> Result<String, ViewError> {
        let start = self.pos;
        match self.peek() {
            Some(c) if c.is_ascii_alphabetic() || c == '_' => {
                self.advance();
            }
            _ => return Err(self.error("expected identifier")),
        }
        while self
            .peek()
            .is_some_and(|c| c.is_ascii_alphanumeric() || c == '_')
        {
            self.advance();
        }
        Ok(self.src[start..self.pos].to_owned())
    }

    fn eat_keyword(&mut self, kw: &str) -> bool {
        if !self.starts_ascii_ci(kw) {
            return false;
        }
        let after = self.pos + kw.len();
        let next = self.src.get(after..).and_then(|s| s.chars().next());
        if next.is_some_and(|c| c.is_ascii_alphanumeric() || c == '_') {
            return false;
        }
        self.pos = after;
        true
    }

    fn eat_char(&mut self, expected: char) -> bool {
        if self.peek() == Some(expected) {
            self.advance();
            true
        } else {
            false
        }
    }

    fn expect_char(&mut self, expected: char) -> Result<(), ViewError> {
        if self.eat_char(expected) {
            Ok(())
        } else {
            Err(self.error(format!("expected '{expected}'")))
        }
    }

    fn expect_close_tag(&mut self) -> Result<(), ViewError> {
        self.skip_ws();
        if self.rest().starts_with("?>") {
            self.pos += 2;
            Ok(())
        } else if self.pos >= self.src.len() {
            Ok(())
        } else {
            Err(self.error("expected ?>"))
        }
    }
}

enum PhpTag {
    HtmlEcho(Expr),
    If(Node),
    Foreach(Node),
    Stop(Stop),
}

fn find_next_php_tag(s: &str) -> Option<usize> {
    let mut offset = 0;
    let bytes = s.as_bytes();
    while offset < bytes.len() {
        if let Some(rel) = s[offset..].find("<?") {
            let at = offset + rel;
            let rest = &s[at..];
            if rest.starts_with("<?=") {
                return Some(at);
            }
            if rest.len() >= 5 && rest[..5].eq_ignore_ascii_case("<?php") {
                return Some(at);
            }
            offset = at + 2;
        } else {
            return None;
        }
    }
    None
}

fn eval_nodes(
    nodes: &[Node],
    view: &View,
    scope: &mut Vec<Map<String, Value>>,
) -> Result<String, ViewError> {
    let mut out = String::new();
    for node in nodes {
        match node {
            Node::Html(html) => out.push_str(html),
            Node::Echo(expr) => {
                let value = eval_expr(expr, view, scope)?;
                out.push_str(&value_to_string(&value));
            }
            Node::If {
                cond,
                then_body,
                elseifs,
                else_body,
            } => {
                let body = if php_truthy(&eval_expr(cond, view, scope)?) {
                    then_body.as_slice()
                } else {
                    let mut matched = None;
                    for (elseif_cond, elseif_body) in elseifs {
                        if php_truthy(&eval_expr(elseif_cond, view, scope)?) {
                            matched = Some(elseif_body.as_slice());
                            break;
                        }
                    }
                    matched.unwrap_or(else_body.as_slice())
                };
                out.push_str(&eval_nodes(body, view, scope)?);
            }
            Node::Foreach {
                iter,
                value_var,
                key_var,
                body,
            } => {
                let iterable = eval_expr(iter, view, scope)?;
                let items = foreach_items(&iterable);
                for (key, value) in items {
                    let mut frame = Map::new();
                    if let Some(k) = key_var {
                        frame.insert(k.clone(), key);
                    }
                    frame.insert(value_var.clone(), value);
                    scope.push(frame);
                    out.push_str(&eval_nodes(body, view, scope)?);
                    scope.pop();
                }
            }
        }
    }
    Ok(out)
}

fn foreach_items(value: &Value) -> Vec<(Value, Value)> {
    match value {
        Value::Array(items) => items
            .iter()
            .enumerate()
            .map(|(i, v)| (Value::from(i), v.clone()))
            .collect(),
        Value::Object(map) => map
            .iter()
            .map(|(k, v)| (Value::String(k.clone()), v.clone()))
            .collect(),
        _ => Vec::new(),
    }
}

fn eval_expr(expr: &Expr, view: &View, scope: &[Map<String, Value>]) -> Result<Value, ViewError> {
    match expr {
        Expr::Null => Ok(Value::Null),
        Expr::Bool(v) => Ok(Value::Bool(*v)),
        Expr::Number(n) => Ok(Value::Number(n.clone())),
        Expr::String(s) => Ok(Value::String(s.clone())),
        Expr::Array(items) => {
            let mut out = Vec::with_capacity(items.len());
            for item in items {
                out.push(eval_expr(item, view, scope)?);
            }
            Ok(Value::Array(out))
        }
        Expr::Object(pairs) => {
            let mut map = Map::new();
            for (k, v) in pairs {
                let key = value_to_string(&eval_expr(k, view, scope)?);
                map.insert(key, eval_expr(v, view, scope)?);
            }
            Ok(Value::Object(map))
        }
        Expr::Var(name) => Ok(scope_get(scope, name).unwrap_or(Value::Null)),
        Expr::Index(base, key) => {
            let base_v = eval_expr(base, view, scope)?;
            let key_v = eval_expr(key, view, scope)?;
            Ok(index_value(&base_v, &key_v).unwrap_or(Value::Null))
        }
        Expr::Concat(left, right) => {
            let a = value_to_string(&eval_expr(left, view, scope)?);
            let b = value_to_string(&eval_expr(right, view, scope)?);
            Ok(Value::String(format!("{a}{b}")))
        }
        Expr::Not(inner) => Ok(Value::Bool(!php_truthy(&eval_expr(inner, view, scope)?))),
        Expr::GetParam { path, default } => {
            let path_v = eval_expr(path, view, scope)?;
            let path_s = value_to_string(&path_v);
            let default_v = match default {
                Some(d) => eval_expr(d, view, scope)?,
                None => Value::Null,
            };
            Ok(view.get_param(&path_s, default_v))
        }
        Expr::Print { value, filter } => {
            let v = eval_expr(value, view, scope)?;
            let filter = match filter {
                Some(f) => print_filter_from_value(&eval_expr(f, view, scope)?)?,
                None => PrintFilter::None,
            };
            view.print(v, filter)
        }
        Expr::Exec { arg } => {
            let _ = eval_expr(arg, view, scope)?;
            Ok(Value::String(view.exec(ExecArg::None)?))
        }
    }
}

fn scope_get(scope: &[Map<String, Value>], name: &str) -> Option<Value> {
    scope
        .iter()
        .rev()
        .find_map(|frame| frame.get(name).cloned())
}

fn index_value(base: &Value, key: &Value) -> Option<Value> {
    match base {
        Value::Object(map) => map.get(&value_to_string(key)).cloned(),
        Value::Array(arr) => {
            let idx = match key {
                Value::Number(n) => n.as_u64().map(|n| n as usize),
                Value::String(s) => s.parse().ok(),
                _ => None,
            }?;
            arr.get(idx).cloned()
        }
        _ => None,
    }
}

fn print_filter_from_value(value: &Value) -> Result<PrintFilter, ViewError> {
    match value {
        Value::Null => Ok(PrintFilter::None),
        Value::String(s) => Ok(PrintFilter::from(s.as_str())),
        Value::Array(items) => {
            let mut names = Vec::new();
            for item in items {
                names.push(value_to_string(item));
            }
            Ok(PrintFilter::from(names))
        }
        other => Err(ViewError::Template(format!(
            "invalid print filter {}",
            value_to_string(other)
        ))),
    }
}
