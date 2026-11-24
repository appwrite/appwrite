import { describe, expect, test } from 'bun:test'
import {
  isValidKey,
  isValidKeyForVariant,
  variantKeyIsAppName,
} from '@/lib/add-app-wizard/platform-map'
import { getKeyTooltip } from '@/components/pages/projects/$projectId/apps/add/_components/field-hints'

describe('isValidKey', () => {
  test('accepts reverse-DNS package and bundle ids', () => {
    expect(isValidKey('com.example')).toBe(true)
    expect(isValidKey('io.appwrite.my_app-1')).toBe(true)
    expect(isValidKey('a.1b.c')).toBe(true)
  })

  test('rejects ids without a dotted segment that starts with a letter', () => {
    expect(isValidKey('example')).toBe(false)
    expect(isValidKey('1com.example')).toBe(false)
    expect(isValidKey('com.1')).toBe(false)
    expect(isValidKey('com.example!')).toBe(false)
    expect(isValidKey('a.')).toBe(false)
  })

  test('rejects a long invalid id without backtracking', () => {
    const started = performance.now()

    expect(isValidKey('A' + '.A'.repeat(20_000) + '!')).toBe(false)
    expect(performance.now() - started).toBeLessThan(1_000)
  })
})

describe('isValidKeyForVariant', () => {
  test('accepts a bare executable name on desktop variants', () => {
    for (const variant of [
      'flutter-windows',
      'windows',
      'flutter-linux',
      'linux',
    ]) {
      expect(isValidKeyForVariant(variant, 'TNEDO')).toBe(true)
      expect(isValidKeyForVariant(variant, 'tnedo')).toBe(true)
      expect(isValidKeyForVariant(variant, 'com.tnedo.app')).toBe(true)
    }
  })

  test('still requires a reverse-DNS id on mobile and Apple variants', () => {
    expect(isValidKeyForVariant('flutter-android', 'tnedo')).toBe(false)
    expect(isValidKeyForVariant('android', 'tnedo')).toBe(false)
    expect(isValidKeyForVariant('apple-ios', 'tnedo')).toBe(false)
    expect(isValidKeyForVariant('flutter-android', 'com.tnedo.app')).toBe(true)
  })

  test('rejects blank or spaced desktop names', () => {
    expect(isValidKeyForVariant('flutter-windows', '')).toBe(false)
    expect(isValidKeyForVariant('flutter-windows', '   ')).toBe(false)
    expect(isValidKeyForVariant('flutter-windows', 'My App')).toBe(false)
  })
})

describe('variantKeyIsAppName', () => {
  test('is true only for desktop variants', () => {
    expect(variantKeyIsAppName('flutter-windows')).toBe(true)
    expect(variantKeyIsAppName('windows')).toBe(true)
    expect(variantKeyIsAppName('flutter-linux')).toBe(true)
    expect(variantKeyIsAppName('linux')).toBe(true)
    expect(variantKeyIsAppName('flutter-macos')).toBe(false)
    expect(variantKeyIsAppName('apple-macos')).toBe(false)
    expect(variantKeyIsAppName('flutter-android')).toBe(false)
    expect(variantKeyIsAppName('web')).toBe(false)
  })
})

describe('getKeyTooltip', () => {
  test('describes the key as an app name on every desktop variant', () => {
    const appName = 'Your application name as it appears on the device.'
    expect(getKeyTooltip('flutter-windows')).toBe(appName)
    expect(getKeyTooltip('windows')).toBe(appName)
    expect(getKeyTooltip('flutter-linux')).toBe(appName)
    expect(getKeyTooltip('linux')).toBe(appName)
    expect(getKeyTooltip('flutter-android')).not.toBe(appName)
  })
})
