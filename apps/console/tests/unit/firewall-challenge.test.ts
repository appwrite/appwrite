import { describe, expect, test } from 'bun:test'
import {
  challengeRuleFields,
  isChallengePasswordValid,
} from '@/lib/firewall/actions'

describe('challengeRuleFields', () => {
  test('a compute rule sends difficulty and ttl, never a password', () => {
    expect(
      challengeRuleFields({
        challengeType: 'compute',
        difficulty: 4,
        ttl: 3600,
        password: 'left-over-secret',
      }),
    ).toEqual({ challengeType: 'compute', difficulty: 4, ttl: 3600 })
  })

  test('a password rule sends the password and ttl, never difficulty', () => {
    expect(
      challengeRuleFields({
        challengeType: 'password',
        difficulty: 3,
        ttl: 1800,
        password: 'correct horse',
      }),
    ).toEqual({
      challengeType: 'password',
      ttl: 1800,
      password: 'correct horse',
    })
  })

  test('an empty password is left out so an update keeps the stored one', () => {
    const fields = challengeRuleFields({
      challengeType: 'password',
      difficulty: 3,
      ttl: 1800,
      password: '',
    })
    expect(fields).toEqual({
      challengeType: 'password',
      ttl: 1800,
      password: undefined,
    })
    expect(JSON.parse(JSON.stringify(fields))).not.toHaveProperty('password')
  })

  test('no challenge type leaves it to the API, as attack mode does', () => {
    expect(challengeRuleFields({ difficulty: 3, ttl: 1800 })).toEqual({
      challengeType: undefined,
      difficulty: 3,
      ttl: 1800,
    })
  })
})

describe('isChallengePasswordValid', () => {
  test('accepts 8 to 256 bytes', () => {
    expect(isChallengePasswordValid('a'.repeat(7), true)).toBe(false)
    expect(isChallengePasswordValid('a'.repeat(8), true)).toBe(true)
    expect(isChallengePasswordValid('a'.repeat(256), true)).toBe(true)
    expect(isChallengePasswordValid('a'.repeat(257), true)).toBe(false)
  })

  test('counts bytes like the API', () => {
    // 86 three-byte characters are 258 bytes.
    expect(isChallengePasswordValid('密'.repeat(86), true)).toBe(false)
    expect(isChallengePasswordValid('密'.repeat(3), true)).toBe(true)
  })

  test('empty passes only when a password is not required', () => {
    expect(isChallengePasswordValid('', true)).toBe(false)
    expect(isChallengePasswordValid('', false)).toBe(true)
    expect(isChallengePasswordValid('short', false)).toBe(false)
  })
})
