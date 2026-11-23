/**
 * Normalize user-generated text before it is placed in JSON-LD values.
 * Keeps readable whitespace while removing characters that break JSON/HTML consumers.
 */
export function sanitizeJsonLdText(value: string): string {
  return value
    .replace(/\0/g, '')
    .replace(/[\u0001-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '')
    .replace(/\u2028/g, '\n')
    .replace(/\u2029/g, '\n\n')
}

/**
 * Serialize structured data for embedding in `<script type="application/ld+json">`.
 *
 * JSON.stringify handles quotes and backslashes, but HTML parsers still treat a
 * literal `</script>` inside the block as the end of the element. User-generated
 * thread content often includes code samples with that sequence, which truncates
 * the JSON and breaks Google Search parsing ("Bad escape sequence", "Incorrect
 * value type").
 */
export function stringifyJsonLd(data: unknown): string {
  return JSON.stringify(data)
    .replace(/</g, '\\u003c')
    .replace(/\u2028/g, '\\u2028')
    .replace(/\u2029/g, '\\u2029')
}
