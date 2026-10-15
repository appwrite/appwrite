/**
 * The periodic password change card shows its maximum age as a value in a unit
 * the user picks while the API stores one number of seconds, so the conversion
 * both ways, the range the API accepts, and the PATCH body are pinned here.
 */

import { describe, expect, test } from 'bun:test'
import { createRequire } from 'node:module'
import {
  hasPasswordRotationChanges,
  MAX_PASSWORD_MAX_AGE_SECONDS,
  maxAgeRangeInUnit,
  maxAgeSecondsFromValue,
  maxAgeUnitPair,
  maxAgeValueInUnit,
  MIN_PASSWORD_MAX_AGE_SECONDS,
  PASSWORD_MAX_AGE_UNITS,
  parsePasswordRotationPolicy,
  passwordMaxAgeRangeError,
  passwordRotationUpdate,
  type PasswordMaxAgeUnit,
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
    // The schema carries the range as prose only — no machine-readable
    // `minimum`/`maximum` — so matching the bare numbers is as tight as this
    // gets without breaking on a reworded sentence.
    const spec = require('@appwrite.io/console/spec.json')
    const maxAge =
      spec.paths['/project/policies/password-rotation'].patch.requestBody
        .content['application/json'].schema.properties.maxAge
    expect(maxAge.description).toContain(String(MIN_PASSWORD_MAX_AGE_SECONDS))
    expect(maxAge.description).toContain(String(MAX_PASSWORD_MAX_AGE_SECONDS))
  })

  test('leaves out units that cannot reach a day', () => {
    // Every offered unit can write an age the API accepts; 'hours' and below
    // cannot, which is why the list stops at days.
    for (const unit of PASSWORD_MAX_AGE_UNITS) {
      expect(toSeconds(1, unit)).toBeGreaterThanOrEqual(
        MIN_PASSWORD_MAX_AGE_SECONDS,
      )
    }
    expect(toSeconds(1, 'hours')).toBeLessThan(MIN_PASSWORD_MAX_AGE_SECONDS)
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

  test('reports no policy when maxAge is not a number', () => {
    // A numeric `maxAge` is the discriminator, so a response that carries the
    // key without one is not the rotation policy: the card is hidden rather
    // than rendered around an age the server did not give.
    expect(
      parsePasswordRotationPolicy({
        $id: 'password-rotation',
        enabled: false,
        maxAge: undefined,
      } as never),
    ).toBeNull()
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

describe('maxAgeRangeInUnit', () => {
  test('is the API range written in the chosen unit', () => {
    expect(maxAgeRangeInUnit('years')).toEqual({ min: 0.0027, max: 10 })
    expect(maxAgeRangeInUnit('months')).toEqual({ min: 0.033, max: 121.67 })
    expect(maxAgeRangeInUnit('weeks')).toEqual({ min: 0.14, max: 521.43 })
    expect(maxAgeRangeInUnit('days')).toEqual({ min: 1, max: 3650 })
  })

  test('never writes a minimum that reads as 0', () => {
    // The field used to hardcode `min={1}` while two decimals wrote the stored
    // day as `0` years, so a valid policy rendered as out of range with no
    // error. A unit added above years must not bring that back.
    for (const unit of PASSWORD_MAX_AGE_UNITS) {
      expect(maxAgeRangeInUnit(unit).min).toBeGreaterThan(0)
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

  test('writes a short age in a large unit without reading as 0', () => {
    // 1 day is the smallest age the API takes, and the example the spec gives.
    // Two decimals wrote it as `0` years, which the card showed with no error
    // while Update stayed disabled.
    expect(maxAgeValueInUnit(ONE_DAY, 'years')).toBe(0.0027)
    expect(maxAgeValueInUnit(ONE_DAY, 'months')).toBe(0.033)
    expect(maxAgeValueInUnit(ONE_DAY, 'weeks')).toBe(0.14)
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

  test('loses the age it cannot write, which is why the card keeps seconds', () => {
    // Writing the number on screen back into seconds is a one-way loss where
    // two decimals cannot hold the age: 1 year read back from months gains
    // 8,640s and from weeks loses 1,728s. The card derives the display from
    // the stored seconds, so only a typed value is ever read back this way.
    const readBack = (unit: PasswordMaxAgeUnit) =>
      maxAgeSecondsFromValue(maxAgeValueInUnit(ONE_YEAR, unit), unit)
    expect(readBack('months')).toBe(ONE_YEAR + 8_640)
    expect(readBack('weeks')).toBe(ONE_YEAR - 1_728)
    // Days and years divide a year exactly, so only those two survive it.
    expect(readBack('days')).toBe(ONE_YEAR)
    expect(readBack('years')).toBe(ONE_YEAR)
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
