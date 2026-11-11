Every Utopia server (HTTP, CLI, queue workers) runs callbacks declared the same way: a hook names the params it reads, with a default and a validator each, and the resources it injects. The server resolves the params from what a request or a job carries, validates them, and calls the callback.

## Declare a hook

A hook's params and injections keep their declaration order, which is the order its callback receives them in.

```
use serde_json::json;
use utopia_servers::{Hook, Param};
use utopia_validators::Text;

let mut hook = Hook::new();
hook.desc("Send a welcome email")
    .groups(&["mails"])
    .label("scope", json!("users.write"))
    .param("email", Param::new(json!(""), Text::new(256), "Recipient.").example("me@example.com"))
    .inject("mailer");

assert_eq!(hook.get_groups(), ["mails"]);
assert_eq!(hook.get_label("scope"), Some(&json!("users.write")));
assert_eq!(hook.params()["email"].example, "me@example.com");
assert_eq!(hook.dependencies(), ["mailer"]);
```

```php
use Utopia\Servers\Hook;
use Utopia\Validator\Text;

$hook = (new Hook())
    ->desc('Send a welcome email')
    ->groups(['mails'])
    ->label('scope', 'users.write')
    ->param('email', '', new Text(256), 'Recipient.', example: 'me@example.com')
    ->inject('mailer');

echo implode(',', $hook->getGroups()), "\n";            // mails
echo $hook->getLabel('scope', null), "\n";              // users.write
echo $hook->getParams()['email']['example'], "\n";      // me@example.com
echo implode(',', $hook->getDependencies()), "\n";      // mailer
```

## Resolve and validate params

`Base::prepare` reads each param from the path values or the request (under its key or an alias), falls back to its default, validates it, and returns a child container holding the values.

```
use std::sync::Arc;
use serde_json::{json, Map, Value};
use utopia_di::Container;
use utopia_servers::{Base, Hook, Param};
use utopia_validators::Text;

let mut hook = Hook::new();
hook.param("projectId", Param::new(json!(""), Text::new(36), "").aliases(&["project"]));

let request: Map<String, Value> = [("project".to_owned(), json!("demo"))].into_iter().collect();
let scope = Base::new().prepare(&Arc::new(Container::new()), &mut hook, &Map::new(), &request)?;

assert_eq!(*scope.get::<Value>("projectId")?, json!("demo"));
assert_eq!(hook.param_value("projectId")?, &json!("demo"));
# Ok::<(), Box<dyn std::error::Error>>(())
```

```php
use Utopia\DI\Container;
use Utopia\Servers\Base;
use Utopia\Servers\Hook;
use Utopia\Validator\Text;

$server = new class () extends Base {
    public function start() {}
    public function run(Hook $hook, array $request): Container { return $this->prepare(new Container(), $hook, [], $request); }
};

$hook = (new Hook())->param('projectId', '', new Text(36), aliases: ['project']);
echo $server->run($hook, ['project' => 'demo'])->get('projectId'), "\n"; // demo
echo $hook->getParamValue('projectId'), "\n";                            // demo
```

## Report invalid params

A missing required param, or one its validator rejects, fails with a 400 error whose message names the param.

```
use std::sync::Arc;
use serde_json::{json, Map};
use utopia_di::Container;
use utopia_servers::{Base, Error, Hook, Param};
use utopia_validators::Text;

let mut hook = Hook::new();
hook.param("name", Param::new(json!(""), Text::new(10), ""));

let error = Base::new().prepare(&Arc::new(Container::new()), &mut hook, &Map::new(), &Map::new()).unwrap_err();
assert!(matches!(error, Error::Server { code: 400, .. }));
assert_eq!(error.to_string(), "Param \"name\" is not optional.");
```

```php
use Utopia\DI\Container;
use Utopia\Servers\Base;
use Utopia\Servers\Exception;
use Utopia\Servers\Hook;
use Utopia\Validator\Text;

$server = new class () extends Base {
    public function start() {}
    public function run(Hook $hook): Container { return $this->prepare(new Container(), $hook, [], []); }
};

try {
    $server->run((new Hook())->param('name', '', new Text(10)));
} catch (Exception $e) {
    echo $e->getCode(), ' ', $e->getMessage(), "\n"; // 400 Param "name" is not optional.
}
```
