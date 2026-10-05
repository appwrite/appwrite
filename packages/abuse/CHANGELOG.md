# Changelog

## 3.0.0 (unreleased)

### Breaking changes

- `Utopia\Abuse\Abuse` is removed. Call `check()`, `reset()`, `getLogs()` and `cleanup()` on the adapter directly.
- The `Utopia\Abuse\Adapters\` namespace is renamed to `Utopia\Abuse\Adapter\`, e.g. `Adapters\TimeLimit\Redis` is now `Adapter\TimeLimit\Redis`.
- Adapters are immutable. `setParam()` is replaced by `withParams()` and `withParam()`, which return a copy with the params applied. `check()` returns a `Utopia\Abuse\Result` (`limited`, `limit`, `remaining`, `reset`) instead of a `bool`, `peek()` returns the same `Result` without recording a hit, and `remaining()`, `limit()` and `time()` are removed in favour of the `Result` fields.
- Adapters are `readonly` classes, and all of them except the `RedisPool` adapters are `final`. `TimeLimit` adapters throw `InvalidArgumentException` for a window of zero seconds or less instead of failing with a division by zero.
- `ReCaptcha` is no longer an adapter. `Utopia\Abuse\Adapters\ReCaptcha` is replaced by the standalone `Utopia\Abuse\ReCaptcha`: construct it with the secret and an optional PSR-18 client (a utopia-php/client cURL client by default) and call `verify($response, $ip, $score)`.
- A `TimeLimit` adapter now takes its window from the clock on every `check()`, `peek()` and `reset()` instead of once at construction. For custom `TimeLimit` adapters, `hit()`, `count()` and `set()` receive the window start, and `hit()` must return the count before the call and leave it unchanged once the limit is reached, so that checking and counting a hit is one step.
- Requires utopia-php/database `^8.0`. Pass a database 8 `Utopia\Database\Database` to `TimeLimit\Database`; `getLogs()` returns database 8 `Document`s.
- Adds a direct requirement on utopia-php/query `^0.6`, the query library database 8 builds on.
- Requires PHP 8.5 or later, the floor utopia-php/database 8 sets. abuse 2.x declared PHP 8.4.1 but could not be installed on PHP 8.4 either, because every utopia-php/database 7 release requires PHP 8.5.
- `TimeLimit\Database::ATTRIBUTES` and `TimeLimit\Database::INDEXES` are removed. `TimeLimit\Database::attributes()` and `TimeLimit\Database::indexes()` return the same schema as `Utopia\Database\Attribute` and `Utopia\Database\Index` objects, and `setup()` creates the collection from them. The schema is unchanged, so an existing `abuse` collection needs no migration.

### Dependencies

- `utopia-php/database` is required as `^8.0` instead of a development branch pinned through an inline alias, and the `repositories` block is gone. Before 8.0.0 is tagged the constraint is served by the database branch's own `8.0.x-dev` alias. A `repositories` entry is honoured only in the root package, so consumers could not resolve the previous requirement without replicating it themselves.
- `minimum-stability` is `dev` with `prefer-stable: true` until utopia-php/database 8.0.0 is tagged; it returns to `stable` with the tag.
- Adds `psr/http-client` `^1.0` and `psr/http-message` `^2.0`, the interfaces `ReCaptcha` takes its HTTP client and request through.
