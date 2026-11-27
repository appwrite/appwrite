import { describe, expect, test } from 'bun:test'
import { WafRuleAction } from '@appwrite.io/console'
import {
  challengeRuleFields,
  formatChallengeAttempts,
  getRuleChallenge,
  isChallengePasswordValid,
  isChallengeRuleInputValid,
} from '@/lib/firewall/actions'
import type { FirewallRule } from '@/lib/firewall/types'

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

  test('a password rule sends the password, attempts, attemptsInterval and ttl, never difficulty', () => {
    expect(
      challengeRuleFields({
        challengeType: 'password',
        difficulty: 3,
        ttl: 1800,
        password: 'correct horse',
        attempts: 5,
        attemptsInterval: 600,
      }),
    ).toEqual({
      challengeType: 'password',
      ttl: 1800,
      password: 'correct horse',
      attempts: 5,
      attemptsInterval: 600,
    })
  })

  test('a password rule falls back to the API defaults for the guess limit', () => {
    expect(
      challengeRuleFields({
        challengeType: 'password',
        ttl: 1800,
        password: 'correct horse',
      }),
    ).toMatchObject({ attempts: 10, attemptsInterval: 300 })
  })

  test('a compute rule never sends attempts or attemptsInterval', () => {
    const fields = challengeRuleFields({
      challengeType: 'compute',
      difficulty: 2,
      ttl: 900,
      password: 'left-over-secret',
      attempts: 5,
      attemptsInterval: 600,
    })
    expect(fields).not.toHaveProperty('password')
    expect(fields).not.toHaveProperty('attempts')
    expect(fields).not.toHaveProperty('attemptsInterval')
  })

  test('switching a password rule to compute drops the password fields', () => {
    // The update drawer keeps the password draft around; compute must not send it.
    expect(
      challengeRuleFields({
        challengeType: 'compute',
        difficulty: 3,
        ttl: 1800,
        password: 'typed before switching',
        attempts: 10,
        attemptsInterval: 300,
      }),
    ).toEqual({ challengeType: 'compute', difficulty: 3, ttl: 1800 })
  })

  test('switching a compute rule to password sends the new password', () => {
    expect(
      challengeRuleFields({
        challengeType: 'password',
        difficulty: 3,
        ttl: 1800,
        password: 'brand new secret',
      }),
    ).toEqual({
      challengeType: 'password',
      ttl: 1800,
      password: 'brand new secret',
      attempts: 10,
      attemptsInterval: 300,
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
      attempts: 10,
      attemptsInterval: 300,
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

describe('isChallengeRuleInputValid', () => {
  const password = {
    challengeType: 'password',
    ttl: 1800,
    password: 'correct horse',
    attempts: 10,
    attemptsInterval: 300,
    resourceType: 'sites',
    passwordRequired: true,
  }

  test('accepts a complete password rule', () => {
    expect(isChallengeRuleInputValid(password)).toBe(true)
  })

  test('creating a password rule requires a password', () => {
    expect(isChallengeRuleInputValid({ ...password, password: '' })).toBe(false)
  })

  test('updating a password rule may leave the password blank', () => {
    expect(
      isChallengeRuleInputValid({
        ...password,
        password: '',
        passwordRequired: false,
      }),
    ).toBe(true)
  })

  test('switching a compute rule to password requires a password', () => {
    // A compute rule has no stored password, so the update drawer requires one.
    expect(
      isChallengeRuleInputValid({
        ...password,
        password: '',
        passwordRequired: true,
      }),
    ).toBe(false)
  })

  test('password rules apply to sites and functions only', () => {
    expect(
      isChallengeRuleInputValid({ ...password, resourceType: 'api' }),
    ).toBe(false)
    expect(
      isChallengeRuleInputValid({ ...password, resourceType: 'functions' }),
    ).toBe(true)
  })

  test('attempts must be an integer from 1 to 100', () => {
    for (const attempts of [1, 100]) {
      expect(isChallengeRuleInputValid({ ...password, attempts })).toBe(true)
    }
    for (const attempts of [0, 101, 2.5]) {
      expect(isChallengeRuleInputValid({ ...password, attempts })).toBe(false)
    }
  })

  test('attemptsInterval must be 60 to 86400 seconds', () => {
    for (const attemptsInterval of [60, 86400]) {
      expect(isChallengeRuleInputValid({ ...password, attemptsInterval })).toBe(
        true,
      )
    }
    for (const attemptsInterval of [59, 86401]) {
      expect(isChallengeRuleInputValid({ ...password, attemptsInterval })).toBe(
        false,
      )
    }
  })

  test('ttl must be 900 to 86400 seconds', () => {
    expect(isChallengeRuleInputValid({ ...password, ttl: 899 })).toBe(false)
    expect(isChallengeRuleInputValid({ ...password, ttl: 86401 })).toBe(false)
  })

  test('compute rules check difficulty and ignore the password fields', () => {
    const compute = {
      challengeType: 'compute',
      difficulty: 3,
      ttl: 1800,
      password: '',
      attempts: 0,
      attemptsInterval: 0,
      resourceType: 'api',
      passwordRequired: true,
    }
    expect(isChallengeRuleInputValid(compute)).toBe(true)
    expect(isChallengeRuleInputValid({ ...compute, difficulty: 6 })).toBe(false)
    expect(isChallengeRuleInputValid({ ...compute, difficulty: 0 })).toBe(false)
  })
})

describe('getRuleChallenge', () => {
  const rule = (config: Record<string, unknown>) =>
    ({
      $id: 'rule',
      action: WafRuleAction.Challenge,
      config,
    }) as unknown as FirewallRule

  test('reads a password rule from its config', () => {
    expect(
      getRuleChallenge(
        rule({
          type: 'password',
          ttl: 3600,
          attempts: 5,
          attemptsInterval: 600,
        }),
      ),
    ).toMatchObject({
      challengeType: 'password',
      ttl: 3600,
      attempts: 5,
      attemptsInterval: 600,
    })
  })

  test('reads a compute rule from its config', () => {
    expect(
      getRuleChallenge(rule({ type: 'compute', ttl: 1800, difficulty: 4 })),
    ).toMatchObject({ challengeType: 'compute', ttl: 1800, difficulty: 4 })
  })
})

describe('formatChallengeAttempts', () => {
  const t = (text: string) => text

  test('uses the largest whole unit', () => {
    expect(formatChallengeAttempts(10, 300, t)).toBe('10 guesses / 5 min')
    expect(formatChallengeAttempts(10, 7200, t)).toBe('10 guesses / 2 h')
    expect(formatChallengeAttempts(3, 90, t)).toBe('3 guesses / 90 sec')
  })

  test('says guess for one', () => {
    expect(formatChallengeAttempts(1, 60, t)).toBe('1 guess / 1 min')
  })
})
