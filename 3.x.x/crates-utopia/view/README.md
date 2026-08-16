# utopia-view

A simple, light view rendering engine for Utopia. Rust port of [utopia-php/view](https://github.com/utopia-php/view).

Renders `.phtml` templates with `$this` as the [`View`] instance: params, named filters, child views via `exec`, and PHP-compatible HTML minify that preserves `<textarea>` / `<pre>` contents.

## Install

```toml
utopia-view = { path = "../utopia-view" } # workspace
```

## Usage

```rust
use serde_json::json;
use utopia_view::{View, ViewError};

fn main() -> Result<(), ViewError> {
    let view = View::new("templates/page.phtml");
    view.set_param("title", "Hello", true)?
        .set_param("items", json!(["one", "two"]), true)?;

    let html = view.render(true)?;
    println!("{html}");
    Ok(())
}
```

Template (`page.phtml`):

```php
<h1><?= $this->print($this->getParam('title'), 'escape') ?></h1>
<?php if ($this->getParam('items')): ?>
<ul>
<?php foreach ($this->getParam('items') as $item): ?>
  <li><?= $this->print($item, 'escape') ?></li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
```

Child views:

```rust
let parent = View::new("layout.phtml");
let child = View::new("partial.phtml");
child.set_param("label", "Hi", true)?;
let _html = parent.exec(&child)?;
```

## API Reference

### Constants

| PHP | Rust | Value |
|-----|------|-------|
| `View::FILTER_ESCAPE` | `View::FILTER_ESCAPE` | `"escape"` |
| `View::FILTER_NL2P` | `View::FILTER_NL2P` | `"nl2p"` |

Default filters are registered in the constructor:

| Name | Behavior |
|------|----------|
| `escape` | PHP `htmlentities($value, ENT_QUOTES, 'UTF-8')` for `&` `"` `'` `<` `>` |
| `nl2p` | Split on `\n\n`, wrap non-empty chunks in `<p>`, then `\n` → `<br />` |

### `View`

| PHP | Rust | Description |
|-----|------|-------------|
| `__construct($path = '')` | `fn new(path: impl Into<String>) -> View` | Optional template path; registers default filters. |
| `setParam($key, $value, $escape = true)` | `fn set_param(&self, key, value, escape: bool) -> Result<&Self, ViewError>` | Key cannot contain `.`. Strings are `htmlspecialchars`'d when `escape` is true. |
| `setParent($view)` | `fn set_parent(&self, view: View) -> &Self` | Record the parent view (object handle). |
| `getParent()` | `fn get_parent(&self) -> Option<View>` | Parent handle, if set. |
| `getParam($path, $default = null)` | `fn get_param(&self, path: &str, default: impl Into<Value>) -> Value` | Dotted path into nested arrays/objects. |
| `setPath($path)` | `fn set_path(&self, path: impl Into<String>) -> &Self` | Template path used by `render`. |
| `setRendered($state = true)` | `fn set_rendered(&self, state: bool) -> &Self` | When true, `render` returns `""`. |
| `isRendered()` | `fn is_rendered(&self) -> bool` | Current rendered flag. |
| `addFilter($name, $callback)` | `fn add_filter(&self, name, callback: impl Fn(Value) -> Value + Send + Sync + 'static) -> &Self` | Register or replace a named filter. |
| `print($value, $filter = '')` | `fn print(&self, value, filter: impl Into<PrintFilter>) -> Result<Value, ViewError>` | Apply named filter(s). |
| `render($minify = true)` | `fn render(&self, minify: bool) -> Result<String, ViewError>` | Include template, optionally minify. |
| `exec($view)` | `fn exec(&self, view: impl Into<ExecArg<'_>>) -> Result<String, ViewError>` | Render child view(s) with parent set. |

Params use [`serde_json::Value`]. Fluent PHP `$this` returns are `&Self` via interior mutability (PHP object-handle semantics).

### `PrintFilter`

| Variant | PHP |
|---------|-----|
| `PrintFilter::None` | `''` or `[]` (`empty($filter)`) |
| `PrintFilter::Name(String)` | `'escape'` |
| `PrintFilter::Chain(Vec<String>)` | `['escape', 'nl2p']` |

`From<&str>`, `From<String>`, `From<Vec<&str>>`, `From<&[&str]>`, and `From<[&str; N]>` are implemented.

### `ExecArg`

| Variant | PHP |
|---------|-----|
| `ExecArg::None` | Neither array nor `View` → `''` |
| `ExecArg::One(&View)` | `exec($view)` |
| `ExecArg::Many(&[View])` | `exec([$a, $b])` |

### Errors

| Error | PHP message |
|-------|-------------|
| `ViewError::DottedKey` | `$key can't contain a dot "." character` |
| `ViewError::FilterNotRegistered { name }` | `Filter "{name}" is not registered` |
| `ViewError::TemplateNotReadable { path }` | `"{path}" view template is not readable` |
| `ViewError::Template(...)` | Interpreter parse/eval error (PHP would be a PHP parse error) |

### Minify

Matches PHP `preg_replace` after placeholder-swapping `<textarea>` / `<pre>` blocks:

| search | replace |
|--------|---------|
| `/>[^\S ]+/s` | `>` |
| `/[^\S ]+</s` | `<` |
| `/(\s)+/s` | `\\1` |

### Template subset

PHP `include` of `.phtml` with `$this` as the view. The interpreter supports:

- Literal HTML (files with no PHP tags emit raw contents, then minify)
- `<?= expr ?>` and `<?php echo expr; ?>`
- `$this->getParam('k')`, `$this->getParam('k', default)`
- `$this->print(...)`, `$this->exec(...)`
- `<?php if ($this->getParam('x')): ?> ... <?php endif; ?>` (`else` / `elseif` too)
- `<?php foreach ($this->getParam('items') as $item): ?> ... <?php endforeach; ?>`

## Tests

```bash
cargo test --manifest-path crates-utopia/view/Cargo.toml
```

Ports `tests/ViewTest.php` from the PHP package, plus nested `getParam`, `htmlspecialchars` on `setParam`, `escape = false`, filter chains, unreadable paths, `exec` children, minify `<textarea>`/`<pre>` preservation, `nl2p`, and dotted-key rejection.

## Benchmarks

```bash
cargo bench --manifest-path crates-utopia/view/Cargo.toml
```

Reports `view_render` (simple mock template) and `view_minify` (whitespace + textarea/pre) ops/s. PHP twin: `benchmarks/view/`.

## Code quality

```bash
cargo fmt --manifest-path crates-utopia/view/Cargo.toml
cargo clippy --manifest-path crates-utopia/view/Cargo.toml --all-targets -- -D warnings
```

## License

MIT - see [LICENSE](LICENSE).
