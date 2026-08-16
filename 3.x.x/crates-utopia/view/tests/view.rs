use std::path::{Path, PathBuf};

use serde_json::{json, Value};
use utopia_view::{View, ViewError};

fn mock_dir() -> PathBuf {
    PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("tests/mocks/View")
}

fn mock(name: &str) -> String {
    mock_dir().join(name).to_string_lossy().into_owned()
}

fn view_with(template: &str) -> View {
    View::new(mock(template))
}

// --- PHP ViewTest.php ---

#[test]
fn test_can_set_param() {
    let view = view_with("template.phtml");
    let value = view.set_param("key", "value", true).unwrap();
    assert!(std::ptr::eq(value, &view));
}

#[test]
fn test_can_get_param() {
    let view = view_with("template.phtml");
    view.set_param("key", "value", true).unwrap();
    assert_eq!(view.get_param("key", "default"), json!("value"));
    assert_eq!(view.get_param("fake", "default"), json!("default"));
}

#[test]
fn test_can_set_path() {
    let view = view_with("template.phtml");
    let value = view.set_path("mocks/View/fake.phtml");
    assert!(std::ptr::eq(value, &view));
}

#[test]
fn test_can_set_rendered() {
    let view = view_with("template.phtml");
    view.set_rendered(true);
    assert!(view.is_rendered());
}

#[test]
fn test_can_get_rendered() {
    let view = view_with("template.phtml");
    view.set_rendered(false);
    assert!(!view.is_rendered());
    view.set_rendered(true);
    assert!(view.is_rendered());
}

#[test]
fn test_can_render_html() {
    let view = view_with("template.phtml");
    assert_eq!(view.render(true).unwrap(), "<div>Test template mock</div>");

    view.set_rendered(true);
    assert_eq!(view.render(true).unwrap(), "");

    view.set_rendered(false);
    view.set_path("just-a-broken-string.phtml");
    let err = view.render(true).unwrap_err();
    assert_eq!(
        err,
        ViewError::TemplateNotReadable {
            path: "just-a-broken-string.phtml".into(),
        }
    );
}

#[test]
fn test_can_escape_unicode() {
    let view = view_with("template.phtml");
    assert_eq!(
        view.print("&\"", View::FILTER_ESCAPE).unwrap(),
        json!("&amp;&quot;")
    );
}

#[test]
fn test_can_filter_new_lines_to_paragraphs() {
    let view = view_with("template.phtml");
    assert_eq!(
        view.print("line1\n\nline2", View::FILTER_NL2P).unwrap(),
        json!("<p>line1</p><p>line2</p>")
    );
}

// --- extra coverage ---

#[test]
fn nested_get_param_walks_arrays() {
    let view = View::new("");
    view.set_param(
        "a",
        json!({
            "b": { "c": 3 },
            "list": [10, 20]
        }),
        true,
    )
    .unwrap();
    assert_eq!(view.get_param("a.b.c", json!(null)), json!(3));
    assert_eq!(view.get_param("a.list.1", json!(null)), json!(20));
    assert_eq!(view.get_param("a.b.missing", "fallback"), json!("fallback"));
    assert_eq!(view.get_param("a.missing.c", "x"), json!("x"));
}

#[test]
fn nested_null_is_treated_as_missing() {
    let view = View::new("");
    view.set_param("a", json!({ "b": null }), true).unwrap();
    assert_eq!(view.get_param("a.b", "default"), json!("default"));
}

#[test]
fn htmlspecialchars_on_set_param() {
    let view = View::new("");
    view.set_param("q", "&\"'<>", true).unwrap();
    assert_eq!(
        view.get_param("q", json!(null)),
        json!("&amp;&quot;&#039;&lt;&gt;")
    );
}

#[test]
fn set_param_escape_false_keeps_raw_string() {
    let view = View::new("");
    view.set_param("html", "<b>ok</b>", false).unwrap();
    assert_eq!(view.get_param("html", json!(null)), json!("<b>ok</b>"));
}

#[test]
fn set_param_does_not_escape_non_strings() {
    let view = View::new("");
    view.set_param("n", 42, true).unwrap();
    view.set_param("flag", true, true).unwrap();
    view.set_param("arr", json!(["<b>"]), true).unwrap();
    assert_eq!(view.get_param("n", json!(null)), json!(42));
    assert_eq!(view.get_param("flag", json!(null)), json!(true));
    assert_eq!(view.get_param("arr", json!(null)), json!(["<b>"]));
}

#[test]
fn dotted_key_rejected() {
    let view = View::new("");
    let err = view.set_param("a.b", "x", true).unwrap_err();
    assert_eq!(err, ViewError::DottedKey);
    assert_eq!(err.to_string(), "$key can't contain a dot \".\" character");
}

#[test]
fn filter_chains() {
    let view = View::new("");
    view.add_filter("upper", |v| {
        Value::String(match v {
            Value::String(s) => s.to_uppercase(),
            other => other.to_string(),
        })
    });
    let chained = view
        .print("line1\n\nline2", [View::FILTER_NL2P, "upper"])
        .unwrap();
    assert_eq!(chained, json!("<P>LINE1</P><P>LINE2</P>"));
}

#[test]
fn unregistered_filter_message() {
    let view = View::new("");
    let err = view.print("x", "nope").unwrap_err();
    assert_eq!(
        err,
        ViewError::FilterNotRegistered {
            name: "nope".into()
        }
    );
    assert_eq!(err.to_string(), "Filter \"nope\" is not registered");
}

#[test]
fn print_empty_filter_is_noop() {
    let view = View::new("");
    assert_eq!(view.print("<b>", "").unwrap(), json!("<b>"));
    assert_eq!(view.print("<b>", Vec::<&str>::new()).unwrap(), json!("<b>"));
}

#[test]
fn unreadable_path_message() {
    let view = View::new("missing-view.phtml");
    let err = view.render(true).unwrap_err();
    assert_eq!(
        err.to_string(),
        "\"missing-view.phtml\" view template is not readable"
    );
}

#[test]
fn empty_path_is_not_readable() {
    let view = View::new("");
    let err = view.render(true).unwrap_err();
    assert_eq!(err.to_string(), "\"\" view template is not readable");
}

#[test]
fn rendered_short_circuits_unreadable_path() {
    let view = View::new("does-not-exist.phtml");
    view.set_rendered(true);
    assert_eq!(view.render(true).unwrap(), "");
}

#[test]
fn exec_children_sets_parent_and_renders() {
    let parent = View::new("");
    parent.set_param("root", "yes", true).unwrap();

    let child = View::new(mock("child.phtml"));
    child.set_param("label", "kid", true).unwrap();

    let html = parent.exec(&child).unwrap();
    assert_eq!(html, "<span>kid</span>");
    assert!(child.get_parent().is_some());
    assert_eq!(
        child.get_parent().unwrap().get_param("root", json!(null)),
        json!("yes")
    );
}

#[test]
fn exec_array_of_children() {
    let parent = View::new("");
    let a = View::new(mock("child.phtml"));
    a.set_param("label", "A", true).unwrap();
    let b = View::new(mock("child.phtml"));
    b.set_param("label", "B", true).unwrap();
    let html = parent.exec(&[a, b][..]).unwrap();
    assert_eq!(html, "<span>A</span><span>B</span>");
}

#[test]
fn minify_preserves_textarea_and_pre() {
    let view = View::new(mock("minify.phtml"));
    let html = view.render(true).unwrap();
    assert!(
        html.contains("<textarea>\nA  B\n</textarea>"),
        "textarea inner whitespace must be preserved, got {html:?}"
    );
    assert!(
        html.contains("<pre>\nC  D\n</pre>"),
        "pre inner whitespace must be preserved, got {html:?}"
    );
    assert!(
        html.contains("<p>E</p>"),
        "content outside textarea/pre should minify, got {html:?}"
    );
    assert!(
        !html.contains("<p>\nE\n</p>"),
        "outer newlines should be stripped, got {html:?}"
    );
}

#[test]
fn nl2p_inner_newlines_become_br() {
    let view = View::new("");
    assert_eq!(
        view.print("a\nb\n\nc", View::FILTER_NL2P).unwrap(),
        json!("<p>a<br />b</p><p>c</p>")
    );
    assert_eq!(view.print("\n\n", View::FILTER_NL2P).unwrap(), json!(""));
}

#[test]
fn echo_and_print_in_template() {
    let view = View::new(mock("echo.phtml"));
    view.set_param("name", "Ada", true).unwrap();
    view.set_param("bio", "x & y", false).unwrap();
    let html = view.render(false).unwrap();
    assert!(html.contains("<div>Ada</div>"));
    assert!(html.contains("<p>x &amp; y</p>"));
}

#[test]
fn if_else_and_foreach_templates() {
    let view = View::new(mock("control.phtml"));
    view.set_param("show", true, true).unwrap();
    view.set_param("items", json!(["a", "<b>"]), true).unwrap();
    let html = view.render(false).unwrap();
    assert!(html.contains("YES"));
    assert!(!html.contains("NO"));
    assert!(html.contains("[a]"));
    assert!(html.contains("[&lt;b&gt;]"));

    view.set_param("show", false, true).unwrap();
    let html = view.render(false).unwrap();
    assert!(html.contains("NO"));
}

#[test]
fn raw_file_without_php_tags_matches_mock() {
    let source = include_str!("mocks/View/template.phtml");
    assert_eq!(source, "<div>Test template mock</div>");
    let view = View::new(mock("template.phtml"));
    assert_eq!(view.render(true).unwrap(), source);
    assert_eq!(view.render(false).unwrap(), source);
}

#[test]
fn parent_roundtrip() {
    let parent = View::new("parent.phtml");
    let child = View::new("child.phtml");
    assert!(child.get_parent().is_none());
    child.set_parent(parent.clone());
    assert_eq!(child.get_parent().unwrap().path(), "parent.phtml");
}

#[test]
fn fluent_setters_return_self() {
    let view = View::new("");
    view.set_path("a.phtml")
        .set_rendered(false)
        .add_filter("id", |v| v)
        .set_param("k", "v", true)
        .unwrap();
    assert_eq!(view.path(), "a.phtml");
    assert_eq!(view.get_param("k", json!(null)), json!("v"));
}

#[test]
fn mock_template_file_exists() {
    assert!(Path::new(&mock("template.phtml")).is_file());
}
