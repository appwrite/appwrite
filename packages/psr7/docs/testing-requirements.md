# Testing Requirements

These requirements translate the vendored FIG specifications and production-readiness concerns into local test coverage.

Source specs:

- `docs/psr/PSR-7-http-message.md`
- `docs/psr/PSR-17-http-factory.md`
- `docs/rfc/RFC-2046-media-types.md`
- `docs/rfc/RFC-7578-multipart-form-data.md`

## PSR-7 message coverage

- Messages are immutable: every `with*()` method returns a changed copy and leaves the original unchanged.
- Header lookup is case-insensitive, but `getHeaders()` preserves the original header case.
- `withHeader()` replaces existing values regardless of case.
- `withAddedHeader()` appends values regardless of case.
- `withoutHeader()` removes values regardless of case.
- Header values are normalized to string arrays.
- `Host` is derived from the URI when creating or replacing request URIs unless preservation rules prevent it.
- `withUri($uri, true)` preserves a populated `Host` header.
- `withUri($uri, true)` still sets `Host` when no host header exists and the new URI has a host.
- Request targets include path and query and default to `/`.
- URI authority includes user info, host, and non-default ports.
- URI default ports are omitted from `getPort()` and string output.
- Stream state changes after `detach()`/`close()` match PSR-7 expectations.

## PSR-17 factory coverage

- Dedicated factories implement their respective PSR-17 interfaces.
- Request factory accepts string and `UriInterface` values.
- Response factory rejects invalid status codes through the response object.
- Stream factory creates streams from strings, files, and resources.

## Multipart coverage

- Multipart request factory creates stable per-part headers and terminal boundaries.
- Multipart request factory always sets `Content-Type` to `multipart/form-data` with a `boundary` parameter equal to the generated body's boundary, including when the caller already supplied `Content-Type`.
- Multipart request factory supports scalar fields, file/body parts, filenames, content types, and custom per-part headers.
- Multipart request factory emits CRLF-delimited part headers and bodies.
- Multipart request factory escapes quoted `Content-Disposition` parameter values for field names and filenames.
- Multipart request factory supports repeated field names by accepting multiple explicit parts with the same name.
- Multipart response helpers parse quoted and unquoted boundaries.
- Multipart response helpers treat only delimiter lines as multipart boundaries and do not split on boundary-like text inside part content.
- Multipart response helpers ignore preamble and epilogue content.
- Multipart response helpers preserve part ordering.
- Multipart response helpers preserve repeated field names as separate ordered parts.
- Multipart response helpers support quoted and token `Content-Disposition` parameter values.
- Multipart response helpers preserve duplicate per-part headers.
- Multipart response parts expose name, filename, content type, headers, and body.
- Multipart response helpers reject missing boundaries.
- Multipart response helpers preserve CRLF-like bytes inside part bodies.
