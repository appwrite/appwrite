import { describe, expect, test } from 'bun:test'
import {
  buildFunctionEmailAddress,
  isFunctionEmailEnabled,
  type FunctionWithEmail,
} from '@/lib/function-email'

describe('isFunctionEmailEnabled', () => {
  test('is on only when emailEnabled is true', () => {
    expect(
      isFunctionEmailEnabled({ emailEnabled: true } as FunctionWithEmail),
    ).toBe(true)
    expect(
      isFunctionEmailEnabled({ emailEnabled: false } as FunctionWithEmail),
    ).toBe(false)
    expect(isFunctionEmailEnabled({} as FunctionWithEmail)).toBe(false)
    expect(isFunctionEmailEnabled(null)).toBe(false)
  })
})

describe('buildFunctionEmailAddress', () => {
  test('puts function and project in the host and lowercases them', () => {
    expect(
      buildFunctionEmailAddress('MyProject', 'FnAbc', 'Appwrite.Email'),
    ).toBe('contact@fnabc-myproject.appwrite.email')
  })

  test('keeps hyphens in the project ID', () => {
    expect(
      buildFunctionEmailAddress('my-app', 'sendmail', 'appwrite.email'),
    ).toBe('contact@sendmail-my-app.appwrite.email')
  })

  test('accepts a label of exactly 63 characters', () => {
    expect(
      buildFunctionEmailAddress(
        'p'.repeat(31),
        'f'.repeat(31),
        'appwrite.email',
      ),
    ).toBe(`contact@${'f'.repeat(31)}-${'p'.repeat(31)}.appwrite.email`)
  })

  test('keeps hyphens in the function ID', () => {
    expect(
      buildFunctionEmailAddress('my-app', 'send-mail', 'appwrite.email'),
    ).toBe('contact@send-mail-my-app.appwrite.email')
  })

  test('returns null when the IDs do not fit one DNS label', () => {
    expect(
      buildFunctionEmailAddress('my-app-', 'sendmail', 'appwrite.email'),
    ).toBeNull()
    expect(
      buildFunctionEmailAddress(
        'p'.repeat(31),
        'f'.repeat(32),
        'appwrite.email',
      ),
    ).toBeNull()
    expect(
      buildFunctionEmailAddress('proj', 'Fn.Abc', 'appwrite.email'),
    ).toBeNull()
    expect(
      buildFunctionEmailAddress('proj', 'fn_abc', 'appwrite.email'),
    ).toBeNull()
    expect(
      buildFunctionEmailAddress(
        'p'.repeat(36),
        'f'.repeat(36),
        'appwrite.email',
      ),
    ).toBeNull()
  })

  test('returns null when any part is missing', () => {
    expect(buildFunctionEmailAddress('p', 'f', undefined)).toBeNull()
    expect(buildFunctionEmailAddress('p', '', 'appwrite.email')).toBeNull()
    expect(buildFunctionEmailAddress(null, 'f', 'appwrite.email')).toBeNull()
  })
})
