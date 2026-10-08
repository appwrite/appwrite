const LOWER = 'abcdefghijklmnopqrstuvwxyz'
const UPPER = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'
const DIGIT = '0123456789'
const OTHER = `${LOWER}${UPPER}${DIGIT}`

function nextRandomUnit(): number {
  if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') {
    const buf = new Uint32Array(1)
    crypto.getRandomValues(buf)
    return (buf[0] ?? 0) / 0x100000000
  }
  return Math.random()
}

function randomChar(alphabet: string): string {
  return alphabet[Math.floor(nextRandomUnit() * alphabet.length)] ?? alphabet[0]!
}

/**
 * Replace sensitive text with random characters of the same length before CSS blur.
 * Keeps whitespace so layout width stays stable; scrambles everything else so the
 * original value is not present in the DOM (blur alone can be reversed).
 */
export function scrambleSensitiveText(value: string): string {
  let out = ''
  for (const ch of value) {
    if (/\s/u.test(ch)) {
      out += ch
      continue
    }
    if (ch >= 'a' && ch <= 'z') {
      out += randomChar(LOWER)
      continue
    }
    if (ch >= 'A' && ch <= 'Z') {
      out += randomChar(UPPER)
      continue
    }
    if (ch >= '0' && ch <= '9') {
      out += randomChar(DIGIT)
      continue
    }
    out += randomChar(OTHER)
  }
  return out
}
