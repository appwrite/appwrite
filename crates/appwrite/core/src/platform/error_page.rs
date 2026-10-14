//! The HTML error page routes labelled `error` render for browsers
//! (`app/views/general/error.phtml`, rendered and minified by
//! `Utopia\Http\View::render()` in `general.php`'s error hook).
//!
//! `error_head.html` is the template's `<head>` as PHP renders it (it holds
//! no logic); the body is built here from the same branches. Nothing renders
//! the PHP error trace: it is development-only and its frames are PHP's.

/// `htmlspecialchars($value, ENT_QUOTES, 'UTF-8')` (View::setParam).
fn escape(value: &str) -> String {
    let mut out = String::with_capacity(value.len());
    for c in value.chars() {
        match c {
            '&' => out.push_str("&amp;"),
            '"' => out.push_str("&quot;"),
            '\'' => out.push_str("&#039;"),
            '<' => out.push_str("&lt;"),
            '>' => out.push_str("&gt;"),
            c => out.push(c),
        }
    }
    out
}

/// What the minifier does to text between tags: a run of whitespace
/// becomes its first character, and whitespace other than spaces is
/// dropped next to a tag.
fn minify_text(text: &str) -> String {
    let mut out = String::with_capacity(text.len());
    let mut chars = text.chars().peekable();
    while let Some(c) = chars.next() {
        if c.is_whitespace() {
            while chars.peek().is_some_and(|n| n.is_whitespace()) {
                chars.next();
            }
        }
        out.push(c);
    }
    // `>[^\S ]+` and `[^\S ]+<`: the text sits between tags.
    let trimmed_start = out.trim_start_matches(|c: char| c.is_whitespace() && c != ' ');
    trimmed_start.trim_end_matches(|c: char| c.is_whitespace() && c != ' ').to_owned()
}

/// The page for an error of `kind` (`type`), status `code` and `message`,
/// for a project named `project_name`.
pub fn render(project_name: &str, message: &str, kind: &str, code: u16, development: bool, url: &str) -> String {
    let message = escape(message);
    let kind = escape(kind);
    let (label, label_class, message, simple) = match kind.as_str() {
        "proxy_error_override" => {
            let message = match code {
                401 => "You must sign in to access this page.",
                403 => "You are not authorized to access this page.",
                404 => "The page you are looking for does not exist.",
                504 => "The server did not respond in time.",
                501 => "This page is not implemented yet.",
                c if c >= 500 => "An unexpected server error occured.",
                _ => "An unexpected client error occured.",
            };
            (format!("Error {code}"), "", message.to_owned(), true)
        }
        "function_execute_permission_missing" => ("Execution not permitted".to_owned(), "warning", message, true),
        "build_not_ready" => (
            "Deployment is still building".to_owned(),
            "warning",
            "The page will update after the build completes.".to_owned(),
            true,
        ),
        "build_failed" => (
            "Deployment build failed".to_owned(),
            "error",
            "An error occurred during the build process.".to_owned(),
            true,
        ),
        "rule_not_found" => {
            ("Nothing is here yet".to_owned(), "", "This page is empty, but you can make it yours.".to_owned(), true)
        }
        "deployment_not_found" => (
            "No active deployments".to_owned(),
            "",
            "This page is empty, activate a deployment to make it live.".to_owned(),
            true,
        ),
        "build_canceled" => (
            "Deployment build canceled".to_owned(),
            "",
            "This build was canceled and won't be deployed.".to_owned(),
            true,
        ),
        "general_route_not_found" => {
            ("Page not found".to_owned(), "", "The page you're looking for doesn't exist.".to_owned(), true)
        }
        _ => (format!("Error {code}"), "", message, false),
    };
    // `proxy_error_override` clears the type.
    let kind = if kind == "proxy_error_override" { String::new() } else { kind };

    let title = minify_text(&escape(&format!("{project_name} - Error")));
    let mut html = include_str!("error_head.html").replace("{{url}}", url).replace("{{title}}", &title);
    html.push_str("<body> <div class=\"main\"> <div id=\"views-error\" class=\"content ");
    html.push_str(if simple { "large-error" } else { "small-error" });
    html.push_str("\"> <div class=\"center\"><span class=\"");
    html.push_str(label_class);
    html.push_str("\">");
    html.push_str(&minify_text(&label));
    html.push_str("</span></div> <h1>");
    html.push_str(&minify_text(&message));
    html.push_str("</h1> ");
    if !kind.is_empty() {
        html.push_str("<div class=\"center\"> <span class='type'>");
        html.push_str(&minify_text(&kind));
        html.push_str("</span> </div> ");
    }
    html.push_str("<div class=\"center\" style=\"margin-top: 20px;\"> ");
    if development {
        html.push_str("<button class=\"bordered-button\" onclick=\"openTraceView()\">View error trace</button> </div> </div> <div id=\"views-trace\" style=\"display: none;\" class=\"error-trace\"> <button class=\"back-button\" onclick=\"openErrorView()\"> Back </button> <div class=\"trace-grid-header\">Error trace</div> </div> </div> ");
    } else {
        html.push_str("</div> </div> </div> ");
    }
    html.push_str("<script> function openErrorView() { document.getElementById('views-trace').style.display = 'none'; document.getElementById('views-error').style.display = 'block'; } function openTraceView() { document.getElementById('views-trace').style.display = 'block'; document.getElementById('views-error').style.display = 'none'; } </script></body></html>");
    html
}

#[cfg(test)]
mod tests {
    #[test]
    fn renders_like_the_php_view() {
        let html =
            super::render("Demo", "This provider is disabled.", "project_provider_disabled", 412, false, "http://x");
        assert!(html.starts_with("<!DOCTYPE html><html lang=\"en\"><head> "));
        assert!(html.contains("<title>Demo - Error</title>"));
        assert!(html.contains("<span class=\"\">Error 412</span></div> <h1>This provider is disabled.</h1> <div class=\"center\"> <span class='type'>project_provider_disabled</span> </div> <div class=\"center\" style=\"margin-top: 20px;\"> </div> </div> </div> <script>"));
        assert!(html.ends_with("</script></body></html>"));
    }
}
