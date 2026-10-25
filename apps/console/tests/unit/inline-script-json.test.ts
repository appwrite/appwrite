import { describe, expect, test } from 'bun:test'
import {
  readRuntimeConfigFromEnv,
  serializeInlineScriptJson,
  serializeRuntimeConfig,
} from '@/lib/runtime-config-shared'

describe('serializeInlineScriptJson', () => {
  test('cannot close the surrounding script element', () => {
    const snapshot = {
      ip: '</script><script>alert(1)</script>',
      headers: { 'x-forwarded-for': '1.1.1.1, <!--<b>&amp;</b>' },
      separators: 'line\u2028paragraph\u2029',
    }
    const json = serializeInlineScriptJson(snapshot)
    for (const unsafe of ['<', '>', '&', '\u2028', '\u2029']) {
      expect(json).not.toContain(unsafe)
    }
    // Still JSON that round-trips to the same value.
    expect(JSON.parse(json)).toEqual(snapshot)
  })

  test('serializes null and undefined as null', () => {
    expect(serializeInlineScriptJson(null)).toBe('null')
    expect(serializeInlineScriptJson(undefined)).toBe('null')
  })

  test('is what the runtime config uses', () => {
    const config = readRuntimeConfigFromEnv({
      VITE_APPWRITE_ENDPOINT: 'https://cloud.appwrite.io/v1</script>',
    })
    expect(serializeRuntimeConfig(config)).toBe(
      serializeInlineScriptJson(config),
    )
    expect(serializeRuntimeConfig(config)).not.toContain('</script>')
  })
})
