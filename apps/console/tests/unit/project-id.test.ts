import { describe, expect, test } from 'bun:test'
import { ID } from '@appwrite.io/console'
import { isValidProjectId } from '@/lib/project-id'

describe('isValidProjectId', () => {
  test('accepts the ids the server-side ProjectId validator accepts', () => {
    expect(isValidProjectId('staging')).toBe(true)
    expect(isValidProjectId('staging-2')).toBe(true)
    expect(isValidProjectId('0')).toBe(true)
    expect(isValidProjectId('2026-eu-preview')).toBe(true)
    expect(isValidProjectId('a'.repeat(36))).toBe(true)
  })

  test('treats an empty id as valid, because create auto-generates one', () => {
    // A blank field means `ID.unique()`, so it must not light up as an error
    // before anything has been typed.
    expect(isValidProjectId('')).toBe(true)
  })

  test('rejects uppercase, underscores and periods the default format allows', () => {
    // Project-shaped ids are stricter than Appwrite resource ids: these are all
    // valid document ids but are rejected by `ProjectId`.
    expect(isValidProjectId('Staging')).toBe(false)
    expect(isValidProjectId('stagING')).toBe(false)
    expect(isValidProjectId('my_env')).toBe(false)
    expect(isValidProjectId('my.env')).toBe(false)
  })

  test('rejects a leading hyphen and other leading special characters', () => {
    expect(isValidProjectId('-staging')).toBe(false)
    expect(isValidProjectId('_staging')).toBe(false)
    expect(isValidProjectId('.staging')).toBe(false)
  })

  test('rejects spaces and anything outside a-z, 0-9 and hyphen', () => {
    expect(isValidProjectId('my env')).toBe(false)
    expect(isValidProjectId('staging ')).toBe(false)
    expect(isValidProjectId('env/1')).toBe(false)
    expect(isValidProjectId('env:1')).toBe(false)
    expect(isValidProjectId('sébastien')).toBe(false)
  })

  test('rejects an id longer than the server limit', () => {
    expect(isValidProjectId('a'.repeat(37))).toBe(false)
  })

  test('accepts the ID.unique() fallback the create hook sends', () => {
    // A blank field sends `ID.unique()`, which the server validates with the
    // same `ProjectId` rule: if that ever stopped being lowercase hex, every
    // auto-generated project and environment would be rejected with a 400.
    for (let attempt = 0; attempt < 50; attempt += 1) {
      expect(isValidProjectId(ID.unique())).toBe(true)
    }
  })
})
