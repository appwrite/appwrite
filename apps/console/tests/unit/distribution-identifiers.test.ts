import { describe, expect, test } from 'bun:test'
import { frameworkPlatforms } from '@/components/pages/projects/$projectId/distribution/_components/platform'
import { isValidIdentifier } from '@/lib/distribution/identifiers'

describe('isValidIdentifier', () => {
  test.each([
    'com.acme.shop',
    'com.acme.Shop-Beta',
    'io.example.app2',
    'single',
  ])('accepts the bundle ID %s', (identifier) => {
    expect(isValidIdentifier('ios', identifier)).toBe(true)
  })

  test.each([
    '',
    'com..acme',
    '.com.acme',
    'com.acme.',
    'com.acme_shop',
    'com.acme shop',
    `com.${'a'.repeat(256)}`,
  ])('rejects the bundle ID "%s"', (identifier) => {
    expect(isValidIdentifier('ios', identifier)).toBe(false)
  })

  test.each(['com.acme.shop', 'com.acme.field_notes', 'io.example.App2'])(
    'accepts the package name %s',
    (identifier) => {
      expect(isValidIdentifier('android', identifier)).toBe(true)
    },
  )

  test.each([
    '',
    'single',
    'com.2acme.shop',
    'com._acme',
    'com.acme-shop',
    'com..acme',
    'com.acme.',
  ])('rejects the package name "%s"', (identifier) => {
    expect(isValidIdentifier('android', identifier)).toBe(false)
  })
})

describe('frameworkPlatforms', () => {
  test.each([
    ['flutter', ['ios', 'android']],
    ['react-native', ['ios', 'android']],
    ['android', ['android']],
    ['ios', ['ios']],
  ] as const)('%s builds %p', (framework, platforms) => {
    expect(frameworkPlatforms(framework)).toEqual([...platforms])
  })
})
