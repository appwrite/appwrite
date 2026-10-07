/**
 * UUID generation that also works outside a secure context.
 *
 * `crypto.randomUUID` is only exposed on secure origins, so a self-hosted
 * console served over plain HTTP on a LAN IP or an internal hostname gets
 * `undefined` and every caller throws. `crypto.getRandomValues` carries no such
 * restriction, so the same RFC 4122 v4 value is built from it instead and the
 * ids stay cryptographically random either way.
 */

function uuidFromRandomValues(): string {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)

  // Version 4, variant 10xx. RFC 4122, section 4.4.
  bytes[6] = (bytes[6]! & 0x0f) | 0x40
  bytes[8] = (bytes[8]! & 0x3f) | 0x80

  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0'))

  return [
    hex.slice(0, 4).join(''),
    hex.slice(4, 6).join(''),
    hex.slice(6, 8).join(''),
    hex.slice(8, 10).join(''),
    hex.slice(10, 16).join(''),
  ].join('-')
}

/** An RFC 4122 v4 UUID, on secure and insecure origins alike. */
export function randomUUID(): string {
  if (typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return uuidFromRandomValues()
}
