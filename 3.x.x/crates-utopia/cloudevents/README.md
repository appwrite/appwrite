# utopia-cloudevents

CloudEvents v1.0 types for Utopia. Rust port of [utopia-php/cloudevents](https://github.com/utopia-php/cloudevents).

Minimal surface used by `utopia-feed`: construct, `from_array` / `to_array`, JSON encode/decode.

## `CloudEvent`

| Method | PHP | Description |
|--------|-----|-------------|
| `new` | `__construct` | Required `type`, `source`, `id`. Default `specversion` is `1.0`, `datacontenttype` is `application/json`. |
| `now` | `CloudEvent::now()` | RFC 3339 UTC timestamp with milliseconds (`Y-m-d\TH:i:s.v\Z`). |
| `from_array` | `fromArray` | Required `specversion`, `type`, `source`, `id`. Does **not** default `datacontenttype`. |
| `to_array` | `toArray` | Omits absent optional attributes. Extensions are merged at the top level. |
| `from_json` | `fromJson` | JSON event format; `data_base64` is decoded into `data`. |
| `to_json` | `toJson` | Non-UTF-8 string `data` is emitted as `data_base64`. |
| `validate` | `validate` | Spec version and non-empty required/optional attributes. |
