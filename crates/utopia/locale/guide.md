Utopia Locale keeps an application's translations. You register each language as a set of keys and texts, pick a default language, and look up texts by key, with placeholders and a fallback language for keys that are not translated yet. In Rust the registered languages live in a `Languages` value that you load once and share; each request creates a cheap `Locale` that borrows it. This guide covers the common tasks.

## Translate a message

Register a language from key and text pairs, create a `Locale` with it as the default, and read texts by key.

```
use utopia_locale::{Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert(
    "en-US",
    Translations::from([
        ("hello".to_owned(), "Hello".to_owned()),
        ("world".to_owned(), "World".to_owned()),
    ]),
);

let locale = Locale::new(&languages, "en-US")?;

assert_eq!(locale.text("hello", Missing::Key, &[])?.as_deref(), Some("Hello"));
assert_eq!(locale.text("world", Missing::Key, &[])?.as_deref(), Some("World"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Locale\Locale;

Locale::setLanguageFromArray('en-US', [
    'hello' => 'Hello',
    'world' => 'World',
]);

$locale = new Locale('en-US');

echo $locale->getText('hello'), "\n"; // Hello
echo $locale->getText('world'), "\n"; // World
```

## Fill in placeholders

Mark a variable part of a text as `{{name}}` and pass its value when you read the text. A placeholder you do not pass stays in the text unchanged.

```
use utopia_locale::{Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert(
    "en-US",
    Translations::from([(
        "likes".to_owned(),
        "You have {{likes}} likes and {{comments}} comments.".to_owned(),
    )]),
);
let locale = Locale::new(&languages, "en-US")?;

let text = locale.text("likes", Missing::Key, &[("likes", "12"), ("comments", "55")])?;
assert_eq!(text.as_deref(), Some("You have 12 likes and 55 comments."));

let text = locale.text("likes", Missing::Key, &[("likes", "12")])?;
assert_eq!(text.as_deref(), Some("You have 12 likes and {{comments}} comments."));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Locale\Locale;

Locale::setLanguageFromArray('en-US', [
    'likes' => 'You have {{likes}} likes and {{comments}} comments.',
]);
$locale = new Locale('en-US');

echo $locale->getText('likes', placeholders: ['likes' => 12, 'comments' => 55]), "\n"; // You have 12 likes and 55 comments.
echo $locale->getText('likes', placeholders: ['likes' => 12]), "\n";                   // You have 12 likes and {{comments}} comments.
```

## Fall back to a complete language

A new language is often only partly translated. Set a fallback language that has every key, and keys the default language lacks are read from it.

```
use utopia_locale::{Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert(
    "en-US",
    Translations::from([
        ("hello".to_owned(), "Hello".to_owned()),
        ("world".to_owned(), "World".to_owned()),
    ]),
);
languages.insert("he-IL", Translations::from([("hello".to_owned(), "שלום".to_owned())]));

let mut locale = Locale::new(&languages, "he-IL")?;
locale.set_fallback("en-US")?;

assert_eq!(locale.text("hello", Missing::Key, &[])?.as_deref(), Some("שלום"));
assert_eq!(locale.text("world", Missing::Key, &[])?.as_deref(), Some("World"));
assert_eq!(locale.fallback(), Some("en-US"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Locale\Locale;

Locale::setLanguageFromArray('en-US', ['hello' => 'Hello', 'world' => 'World']);
Locale::setLanguageFromArray('he-IL', ['hello' => 'שלום']);

$locale = new Locale('he-IL');
$locale->setFallback('en-US');

echo $locale->getText('hello'), "\n"; // שלום
echo $locale->getText('world'), "\n"; // World
echo $locale->fallback, "\n";         // en-US
```

## Show something for missing translations

By default a key no language has is an error. Turn exceptions off to get a placeholder instead: `{{key}}`, your own text, or nothing. Your own text can hold placeholders too.

```
use utopia_locale::{Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert("en-US", Translations::from([("hello".to_owned(), "Hello".to_owned())]));
languages.set_exceptions(false);
let locale = Locale::new(&languages, "en-US")?;

assert_eq!(locale.text("missing", Missing::Key, &[])?.as_deref(), Some("{{missing}}"));
assert_eq!(locale.text("missing", Missing::Text("A custom text"), &[])?.as_deref(), Some("A custom text"));
assert_eq!(locale.text("missing", Missing::Null, &[])?, None);

let text = locale.text("missing", Missing::Text("Sorry {{name}}, missing text"), &[("name", "Matej")])?;
assert_eq!(text.as_deref(), Some("Sorry Matej, missing text"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Locale\Locale;

Locale::setLanguageFromArray('en-US', ['hello' => 'Hello']);
Locale::$exceptions = false;
$locale = new Locale('en-US');

echo $locale->getText('missing'), "\n";                           // {{missing}}
echo $locale->getText('missing', default: 'A custom text'), "\n"; // A custom text
var_dump($locale->getText('missing', default: null));             // NULL

echo $locale->getText('missing', 'Sorry {{name}}, missing text', ['name' => 'Matej']), "\n"; // Sorry Matej, missing text
```

## Load translations from files

Keep each language in a JSON file of keys and texts. `load` reads a file asynchronously; `insert_json` takes JSON you already have in memory.

```
use utopia_locale::{Languages, Locale, Missing};

let path = std::env::temp_dir().join("utopia-locale-guide-hi-IN.json");
std::fs::write(&path, r#"{"hello": "Namaste", "world": "Duniya"}"#)?;

let mut languages = Languages::new();
let runtime = tokio::runtime::Builder::new_current_thread().build()?;
runtime.block_on(languages.load("hi-IN", &path))?;
languages.insert_json("en-US", br#"{"hello": "Hello"}"#)?;

let locale = Locale::new(&languages, "hi-IN")?;
assert_eq!(locale.text("world", Missing::Key, &[])?.as_deref(), Some("Duniya"));
assert_eq!(languages.names().collect::<Vec<_>>(), ["hi-IN", "en-US"]);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Locale\Locale;

$path = sys_get_temp_dir() . '/utopia-locale-guide-hi-IN.json';
file_put_contents($path, '{"hello": "Namaste", "world": "Duniya"}');

Locale::setLanguageFromJSON('hi-IN', $path);
Locale::setLanguageFromArray('en-US', ['hello' => 'Hello']);

$locale = new Locale('hi-IN');
echo $locale->getText('world'), "\n";              // Duniya
echo implode(', ', Locale::getLanguages()), "\n"; // hi-IN, en-US
```

## Switch the language for each request

Load languages once at startup. For each request, create a `Locale` with the language the user asked for, or change the default of an existing one. `translations` returns every text of the current default, for example to send to a client.

```
use utopia_locale::{Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert(
    "en-US",
    Translations::from([("hello".to_owned(), "Hello".to_owned()), ("bye".to_owned(), "Bye".to_owned())]),
);
languages.insert("he-IL", Translations::from([("hello".to_owned(), "שלום".to_owned())]));

let requested = "he-IL";
let mut locale = Locale::new(&languages, requested)?;
assert_eq!(locale.default(), "he-IL");
assert_eq!(locale.text("hello", Missing::Key, &[])?.as_deref(), Some("שלום"));

locale.set_default("en-US")?;
let all = locale.translations()?;
assert_eq!(all.len(), 2);
assert_eq!(all.keys().collect::<Vec<_>>(), ["hello", "bye"]);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Locale\Locale;

Locale::setLanguageFromArray('en-US', ['hello' => 'Hello', 'bye' => 'Bye']);
Locale::setLanguageFromArray('he-IL', ['hello' => 'שלום']);

$requested = 'he-IL';
$locale = new Locale($requested);
echo $locale->default, "\n";          // he-IL
echo $locale->getText('hello'), "\n"; // שלום

$locale->setDefault('en-US');
$all = $locale->getTranslations();
echo count($all), "\n";                    // 2
echo implode(', ', array_keys($all)), "\n"; // hello, bye
```

## Catch unknown languages and keys

With exceptions on, the default, an unknown language or a key no language has fails with an error. Check a language with `contains` before you use it, or handle the error and fall back.

```
use utopia_locale::{Error, Languages, Locale, Missing, Translations};

let mut languages = Languages::new();
languages.insert("en-US", Translations::from([("hello".to_owned(), "Hello".to_owned())]));

assert!(!languages.contains("fr-FR"));
let error = Locale::new(&languages, "fr-FR").unwrap_err();
assert_eq!(error, Error::Exception("Locale not found".into()));

let locale = Locale::new(&languages, "en-US")?;
let error = locale.text("missing", Missing::Key, &[]).unwrap_err();
assert_eq!(error.to_string(), r#"Key named "missing" not found"#);
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\Locale\Locale;

Locale::setLanguageFromArray('en-US', ['hello' => 'Hello']);

var_dump(in_array('fr-FR', Locale::getLanguages())); // bool(false)
try {
    new Locale('fr-FR');
} catch (\Exception $e) {
    echo $e->getMessage(), "\n"; // Locale not found
}

$locale = new Locale('en-US');
try {
    $locale->getText('missing');
} catch (\Exception $e) {
    echo $e->getMessage(), "\n"; // Key named "missing" not found
}
```
