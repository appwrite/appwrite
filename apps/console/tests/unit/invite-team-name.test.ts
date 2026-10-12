import { describe, expect, test } from 'bun:test'
import {
  MAX_INVITE_TEAM_NAME_LENGTH,
  unescapeInviteTeamName,
} from '@/lib/auth/invite-team-name'

describe('unescapeInviteTeamName', () => {
  test('decodes a percent-encoded name', () => {
    expect(unescapeInviteTeamName('Acme%20Inc')).toBe('Acme Inc')
  })

  test('treats plus as space', () => {
    expect(unescapeInviteTeamName('Acme+Inc')).toBe('Acme Inc')
  })

  test('keeps angle brackets as text (React must render them escaped)', () => {
    expect(unescapeInviteTeamName('<script>alert(1)</script>')).toBe(
      '<script>alert(1)</script>',
    )
    expect(unescapeInviteTeamName('%3Cimg%20src=x%20onerror=alert(1)%3E')).toBe(
      '<img src=x onerror=alert(1)>',
    )
  })

  test('strips control characters and bidi overrides', () => {
    expect(unescapeInviteTeamName('Acme\u0000Inc')).toBe('AcmeInc')
    expect(unescapeInviteTeamName('\u202EemaC')).toBe('emaC')
  })

  test('returns null for empty or whitespace-only values', () => {
    expect(unescapeInviteTeamName(undefined)).toBeNull()
    expect(unescapeInviteTeamName('')).toBeNull()
    expect(unescapeInviteTeamName('   ')).toBeNull()
    expect(unescapeInviteTeamName('%20')).toBeNull()
  })

  test('caps overly long values', () => {
    const long = 'a'.repeat(MAX_INVITE_TEAM_NAME_LENGTH + 50)
    expect(unescapeInviteTeamName(long)?.length).toBe(MAX_INVITE_TEAM_NAME_LENGTH)
  })

  test('does not throw on malformed percent-encoding', () => {
    expect(unescapeInviteTeamName('100% organic')).toBe('100% organic')
  })
})
