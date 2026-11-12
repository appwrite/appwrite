/**
 * Environment variable key and value validation.
 *
 * The pattern these assert is the API's, not ours: a variable key becomes an
 * environment variable name at build and runtime, so anything that is not a
 * C-style identifier is rejected server-side. The cases below are the ones a
 * `.env` file realistically carries -- dashes, dots, spaces, a leading digit,
 * a non-ASCII letter -- since the parser accepts all of them.
 *
 * getVariableValueError is covered separately because it is the only check the
 * update paths run, and the JSON bulk editor can hand it a parsed value that is
 * not a string.
 */

import { describe, expect, test } from 'bun:test'
import {
  getVariableKeyError,
  getVariableValueError,
  validateVariables,
  VARIABLE_KEY_MAX_LENGTH,
  VARIABLE_VALUE_MAX_LENGTH,
} from '@/lib/variables'

describe('getVariableKeyError', () => {
  test('accepts keys that are valid environment variable names', () => {
    expect(getVariableKeyError('APP_TEST')).toBeNull()
    expect(getVariableKeyError('_PRIVATE')).toBeNull()
    expect(getVariableKeyError('key1')).toBeNull()
    expect(getVariableKeyError('a'.repeat(VARIABLE_KEY_MAX_LENGTH))).toBeNull()
  })

  test('rejects keys that cannot be used as environment variable names', () => {
    for (const key of ['MY-KEY', 'MY.KEY', 'MY KEY', '9KEY', 'KÉY', 'KEY\t']) {
      expect(getVariableKeyError(key)).not.toBeNull()
    }
  })

  test('reports a missing key separately from an invalid one', () => {
    expect(getVariableKeyError('')).toEqual('Variable key is required')
    expect(getVariableKeyError('   ')).toEqual('Variable key is required')
    expect(getVariableKeyError('MY-KEY')).toContain('can only contain')
    expect(
      getVariableKeyError('a'.repeat(VARIABLE_KEY_MAX_LENGTH + 1)),
    ).toContain('longer than')
  })
})

describe('getVariableValueError', () => {
  test('accepts values up to the limit', () => {
    expect(getVariableValueError('APP_TEST', '')).toBeNull()
    expect(
      getVariableValueError('APP_TEST', 'v'.repeat(VARIABLE_VALUE_MAX_LENGTH)),
    ).toBeNull()
  })

  test('rejects an oversized value and names the key', () => {
    expect(
      getVariableValueError(
        'APP_TEST',
        'v'.repeat(VARIABLE_VALUE_MAX_LENGTH + 1),
      ),
    ).toContain('APP_TEST')
  })

  test('coerces the non-string values the JSON editor can produce', () => {
    expect(getVariableValueError('APP_TEST', undefined)).toBeNull()
    expect(getVariableValueError('APP_TEST', null)).toBeNull()
    expect(getVariableValueError('APP_TEST', 42)).toBeNull()
    expect(getVariableValueError('APP_TEST', { nested: true })).toBeNull()
  })
})

describe('validateVariables', () => {
  test('accepts a valid list, including empty values', () => {
    expect(validateVariables([])).toBeNull()
    expect(validateVariables([{ key: 'APP_TEST', value: 'value' }])).toBeNull()
    expect(validateVariables([{ key: 'APP_TEST', value: '' }])).toBeNull()
  })

  test('names the offending key rather than failing anonymously', () => {
    expect(
      validateVariables([
        { key: 'APP_TEST', value: 'value' },
        { key: 'MY-KEY', value: 'value' },
      ]),
    ).toContain('MY-KEY')

    expect(
      validateVariables([
        { key: 'APP_TEST', value: 'v'.repeat(VARIABLE_VALUE_MAX_LENGTH + 1) },
      ]),
    ).toContain('APP_TEST')
  })
})
