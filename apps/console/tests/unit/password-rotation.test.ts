/**
 * The periodic password change card shows its maximum age as a value in a unit
 * the user picks while the API stores one number of seconds, so the conversion
 * both ways, the range the API accepts, and the PATCH body are pinned here.
 */

import { describe, expect, test } from 'bun:test'
import { createRequire } from 'node:module'
import {
  DEFAULT_PASSWORD_MAX_AGE_SECONDS,
  hasPasswordRotationChanges,
  MAX_PASSWORD_MAX_AGE_SECONDS,
  maxAgeSecondsFromValue,
  maxAgeUnitPair,
  maxAgeValueForUnit,
  maxAgeValueInUnit,
  MIN_PASSWORD_MAX_AGE_SECONDS,
  PASSWORD_MAX_AGE_UNITS,
  parsePasswordRotationPolicy,
  passwordMaxAgeRangeError,
  passwordRotationUpdate,
} from '@/lib/password-rotation'
import { toSeconds } from '@/lib/utils/time-unit-converter'

const require = createRequire(import.meta.url)

const ONE_DAY = 86_400
const ONE_YEAR = 31_536_000
const TEN_YEARS = 315_360_000
/** 3 months as the policy stores it, and as 90 days reads back. */
const THREE_MONTHS_IN_DAYS = 7_776_000

describe('the range the API accepts', () => {
  test('mirrors what the endpoint documents', () => {
    // `Range(86400, 315360000)` in
    // src/Appwrite/Platform/Modules/Project/Http/Project/Policies/PasswordRotation/Update.php,
    // which reaches the console as the spec the SDK is generated from.
    const spec = require('@appwrite.io/console/spec.json')
    const maxAge =
      spec.paths['/project/policies/password-rotation'].patch.requestBody
        .content['application/json'].schema.properties.maxAge
    expect(maxAge.description).toContain(
      `${MIN_PASSWORD_MAX_AGE_SECONDS} seconds`,
    )
    expect(maxAge.description).toContain(
      `${MAX_PASSWORD_MAX_AGE_SECONDS} seconds`,
    )
    expect(maxAge.description).toContain(
      `${DEFAULT_PASSWORD_MAX_AGE_SECONDS} seconds`,
    )
  })

  test('leaves out units that cannot reach a day', () => {
    // 'hours' and below can only express a maximum age the API refuses.
    expect(PASSWORD_MAX_AGE_UNITS).toEqual(['years', 'months', 'weeks', 'days'])
  })
})

describe('parsePasswordRotationPolicy', () => {
  test('reads the policy the server reports', () => {
    expect(
      parsePasswordRotationPolicy({
        $id: 'password-rotation',
        enabled: true,
        maxAge: 7_776_000,
      } as never),
    ).toEqual({ enabled: true, maxAge: 7_776_000 })
  })

  test('falls back to the stored default when maxAge is absent', () => {
    expect(
      parsePasswordRotationPolicy({
        $id: 'password-rotation',
        enabled: false,
        maxAge: undefined,
      } as never),
    ).toEqual({ enabled: false, maxAge: DEFAULT_PASSWORD_MAX_AGE_SECONDS })
  })

  test('reports no policy on a server that does not have one', () => {
    // This is the older-server path: `listPolicies` returns no
    // `password-rotation` entry, and the card is hidden rather than offering
    // defaults the server cannot save.
    expect(parsePasswordRotationPolicy(undefined)).toBeNull()
  })

  test('does not mistake another policy for the rotation policy', () => {
    // `maxAge` is the type guard, so a policy that happens to carry `enabled`
    // must not read back as a rotation policy.
    expect(
      parsePasswordRotationPolicy({
        $id: 'password-pwned',
        enabled: true,
        sessions: true,
        users: false,
      } as never),
    ).toBeNull()
  })
})

describe('maxAgeUnitPair', () => {
  test('shows a stored age in the largest unit that stays whole', () => {
    expect(maxAgeUnitPair(ONE_DAY)).toEqual({ value: 1, unit: 'days' })
    expect(maxAgeUnitPair(604_800)).toEqual({ value: 1, unit: 'weeks' })
    expect(maxAgeUnitPair(7_776_000)).toEqual({ value: 3, unit: 'months' })
    expect(maxAgeUnitPair(ONE_YEAR)).toEqual({ value: 1, unit: 'years' })
    expect(maxAgeUnitPair(TEN_YEARS)).toEqual({ value: 10, unit: 'years' })
  })

  test('falls back to days for an age no unit divides', () => {
    // Reachable only through the API, e.g. 100000 seconds.
    expect(maxAgeUnitPair(100_000)).toEqual({ value: 1.16, unit: 'days' })
  })
})

describe('maxAgeValueForUnit', () => {
  test('is the largest whole value that stays inside 10 years', () => {
    expect(maxAgeValueForUnit('years')).toBe(10)
    expect(maxAgeValueForUnit('months')).toBe(121)
    expect(maxAgeValueForUnit('weeks')).toBe(521)
    expect(maxAgeValueForUnit('days')).toBe(3650)
  })

  test('never offers a stepper value the API would refuse', () => {
    for (const unit of PASSWORD_MAX_AGE_UNITS) {
      expect(toSeconds(maxAgeValueForUnit(unit), unit)).toBeLessThanOrEqual(
        MAX_PASSWORD_MAX_AGE_SECONDS,
      )
      expect(toSeconds(maxAgeValueForUnit(unit) + 1, unit)).toBeGreaterThan(
        MAX_PASSWORD_MAX_AGE_SECONDS,
      )
    }
  })
})

describe('maxAgeValueInUnit', () => {
  test('writes the same age in whichever unit is chosen', () => {
    expect(maxAgeValueInUnit(ONE_YEAR, 'years')).toBe(1)
    expect(maxAgeValueInUnit(ONE_YEAR, 'days')).toBe(365)
    expect(maxAgeValueInUnit(ONE_YEAR, 'weeks')).toBe(52.14)
    expect(maxAgeValueInUnit(ONE_YEAR, 'months')).toBe(12.17)
    expect(maxAgeValueInUnit(ONE_DAY, 'days')).toBe(1)
  })

  test('reads a stored age that no unit divides', () => {
    // Reachable through the API: 100000 seconds reads as 1.16 days without the
    // card treating the rounding as an edit, because the age itself is untouched.
    expect(maxAgeValueInUnit(100_000, 'days')).toBe(1.16)
    expect(
      hasPasswordRotationChanges(
        { enabled: true, maxAgeSeconds: 100_000 },
        { enabled: true, maxAge: 100_000 },
      ),
    ).toBe(false)
  })
})

describe('maxAgeSecondsFromValue', () => {
  test('reads a typed value in the chosen unit', () => {
    expect(maxAgeSecondsFromValue(90, 'days')).toBe(THREE_MONTHS_IN_DAYS)
    expect(maxAgeSecondsFromValue(1, 'years')).toBe(ONE_YEAR)
    expect(maxAgeSecondsFromValue(1, 'days')).toBe(ONE_DAY)
  })

  test('holds the typed value to what the field can show', () => {
    // So the number on screen and the age behind it never disagree.
    expect(maxAgeSecondsFromValue(52.146, 'weeks')).toBe(
      toSeconds(52.15, 'weeks'),
    )
  })

  test('round trips through the display without drifting', () => {
    for (const unit of PASSWORD_MAX_AGE_UNITS) {
      const seconds = maxAgeSecondsFromValue(
        maxAgeValueInUnit(ONE_YEAR, unit),
        unit,
      )
      expect(maxAgeValueInUnit(seconds, unit)).toBe(
        maxAgeValueInUnit(ONE_YEAR, unit),
      )
    }
  })
})

describe('passwordMaxAgeRangeError', () => {
  test('accepts both ends of the range', () => {
    expect(
      passwordMaxAgeRangeError(true, MIN_PASSWORD_MAX_AGE_SECONDS),
    ).toBeNull()
    expect(
      passwordMaxAgeRangeError(true, MAX_PASSWORD_MAX_AGE_SECONDS),
    ).toBeNull()
    expect(passwordMaxAgeRangeError(true, ONE_YEAR)).toBeNull()
  })

  test('names which end a value is outside', () => {
    expect(passwordMaxAgeRangeError(true, 0)).toBe('below-min')
    expect(passwordMaxAgeRangeError(true, ONE_DAY - 1)).toBe('below-min')
    expect(passwordMaxAgeRangeError(true, TEN_YEARS + 1)).toBe('above-max')
  })

  test('does not block the toggle while the policy is off', () => {
    // The maximum age is not sent while the policy is off, so a value left
    // behind by a disabled policy must not disable Update.
    expect(passwordMaxAgeRangeError(false, 0)).toBeNull()
    expect(passwordMaxAgeRangeError(false, TEN_YEARS * 2)).toBeNull()
  })
})

describe('passwordRotationUpdate', () => {
  test('sends the maximum age while the policy is on', () => {
    expect(passwordRotationUpdate(true, ONE_YEAR)).toEqual({
      enabled: true,
      maxAge: ONE_YEAR,
    })
  })

  test('rounds a fractional age the unit pair produced', () => {
    // 12.17 months is not a whole number of seconds; the API takes an integer.
    expect(passwordRotationUpdate(true, 31_544_640.000000004)).toEqual({
      enabled: true,
      maxAge: 31_544_640,
    })
  })

  test('leaves the maximum age out while the policy is off', () => {
    // The API keeps options it was not sent, so the stored maximum age
    // survives a toggle instead of being overwritten.
    const body = passwordRotationUpdate(false, ONE_YEAR)
    expect(body).toEqual({ enabled: false })
    expect('maxAge' in body).toBe(false)
  })
})

describe('hasPasswordRotationChanges', () => {
  const stored = { enabled: true, maxAge: ONE_YEAR }

  test('sees the toggle move', () => {
    expect(
      hasPasswordRotationChanges(
        { enabled: false, maxAgeSeconds: ONE_YEAR },
        stored,
      ),
    ).toBe(true)
    expect(
      hasPasswordRotationChanges(
        { enabled: true, maxAgeSeconds: ONE_YEAR },
        {
          enabled: false,
          maxAge: ONE_YEAR,
        },
      ),
    ).toBe(true)
  })

  test('sees a new maximum age', () => {
    expect(
      hasPasswordRotationChanges(
        { enabled: true, maxAgeSeconds: THREE_MONTHS_IN_DAYS },
        stored,
      ),
    ).toBe(true)
  })

  test('sees the smallest edit the field can hold', () => {
    // The value field shows two decimals, so 1 -> 1.01 years is a real edit,
    // and so is 365 -> 365.01 days.
    expect(
      hasPasswordRotationChanges(
        { enabled: true, maxAgeSeconds: maxAgeSecondsFromValue(1.01, 'years') },
        stored,
      ),
    ).toBe(true)
    expect(
      hasPasswordRotationChanges(
        {
          enabled: true,
          maxAgeSeconds: maxAgeSecondsFromValue(365.01, 'days'),
        },
        stored,
      ),
    ).toBe(true)
  })

  test('finds nothing to save in an untouched card', () => {
    expect(
      hasPasswordRotationChanges(
        { enabled: true, maxAgeSeconds: ONE_YEAR },
        stored,
      ),
    ).toBe(false)
  })

  test('ignores float noise under the whole second the API stores', () => {
    expect(
      hasPasswordRotationChanges(
        { enabled: true, maxAgeSeconds: ONE_YEAR + 0.000000004 },
        stored,
      ),
    ).toBe(false)
  })

  test('ignores the maximum age while the policy stays off', () => {
    // It is not sent, so a value left behind by a disabled policy is not an edit.
    expect(
      hasPasswordRotationChanges(
        { enabled: false, maxAgeSeconds: THREE_MONTHS_IN_DAYS },
        { enabled: false, maxAge: ONE_YEAR },
      ),
    ).toBe(false)
  })
})
