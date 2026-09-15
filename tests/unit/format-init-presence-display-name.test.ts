import { describe, expect, test } from 'bun:test'
import { formatInitPresenceDisplayName } from '@/lib/init/format-init-presence-display-name'

describe('formatInitPresenceDisplayName', () => {
  test('uses the first token from email-like names', () => {
    expect(formatInitPresenceDisplayName('eldad.fux@example.com')).toBe('Eldad')
    expect(formatInitPresenceDisplayName('  john_doe@company.io  ')).toBe('John')
  })

  test('shows only the first name for other users', () => {
    expect(
      formatInitPresenceDisplayName('Eldad Fux', { isSelf: false }),
    ).toBe('Eldad')
  })

  test('keeps the full name for the signed-in user', () => {
    expect(formatInitPresenceDisplayName('Eldad Fux', { isSelf: true })).toBe(
      'Eldad Fux',
    )
  })
})
