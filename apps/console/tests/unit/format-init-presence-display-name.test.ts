import { describe, expect, test } from 'bun:test'
import { formatInitPresenceDisplayName } from '@/lib/init/format-init-presence-display-name'

describe('formatInitPresenceDisplayName', () => {
  test('uses the first token from email-like names', () => {
    expect(formatInitPresenceDisplayName('eldad.fux@example.com')).toBe('Eldad')
    expect(formatInitPresenceDisplayName('  john_doe@company.io  ')).toBe(
      'John',
    )
  })

  test('keeps the full display name', () => {
    expect(formatInitPresenceDisplayName('Eldad Fux')).toBe('Eldad Fux')
    expect(formatInitPresenceDisplayName('  Eldad Fux  ')).toBe('Eldad Fux')
  })

  test('returns empty names unchanged', () => {
    expect(formatInitPresenceDisplayName('   ')).toBe('')
  })
})
