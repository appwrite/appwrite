import { describe, expect, test } from 'bun:test'
import {
  MAX_ROLLOUT_PERCENT,
  MIN_ROLLOUT_PERCENT,
  RELEASE_NOTES_LOCALE,
  RELEASE_VERSION_MAX_LENGTH,
  ROLLOUT_MISMATCH_MESSAGE,
  buildSubmissionBlocker,
  isRolloutPercent,
  noteRow,
  notesError,
  notesToRows,
  rolloutMismatch,
  rolloutMismatchMessage,
  rowsToNotes,
  sameNotes,
  versionError,
} from '@/lib/distribution/releases'

describe('release notes rows', () => {
  test('start with one empty row in the default locale', () => {
    const rows = notesToRows({})
    expect(rows).toHaveLength(1)
    expect(rows[0].locale).toBe(RELEASE_NOTES_LOCALE)
    expect(rows[0].text).toBe('')
  })

  test('keep one row per locale with unique keys', () => {
    const rows = notesToRows({
      'en-US': 'Fixes.',
      'de-DE': 'Fehlerbehebungen.',
    })
    expect(rows.map((row) => [row.locale, row.text])).toEqual([
      ['en-US', 'Fixes.'],
      ['de-DE', 'Fehlerbehebungen.'],
    ])
    expect(new Set(rows.map((row) => row.key)).size).toBe(2)
    expect(noteRow('fr-FR', '').key).toBeGreaterThan(rows[1].key)
  })

  test('drop empty rows and trim values', () => {
    const notes = rowsToNotes([
      noteRow(' en-US ', ' Fixes. '),
      noteRow('de-DE', '   '),
      noteRow('', ''),
    ])
    expect(notes).toEqual({ 'en-US': 'Fixes.' })
  })
})

describe('notesError', () => {
  test.each([
    [[noteRow('en-US', 'Fixes.')], null],
    [[noteRow('en-US', '')], null],
    [[noteRow('', '')], null],
    [[noteRow('', 'Fixes.')], 'Enter a locale for every note.'],
    [
      [noteRow('en-US', 'Fixes.'), noteRow(' en-US', 'More fixes.')],
      'Each locale can have only one note.',
    ],
  ])('%#', (rows, expected) => {
    expect(notesError(rows)).toBe(expected)
  })
})

describe('sameNotes', () => {
  test.each([
    [{}, {}, true],
    [{ 'en-US': 'A' }, { 'en-US': 'A' }, true],
    [{ 'en-US': 'A' }, { 'en-US': 'B' }, false],
    [{ 'en-US': 'A' }, { 'en-US': 'A', 'de-DE': 'A' }, false],
    [{ 'en-US': 'A', 'de-DE': 'B' }, { 'de-DE': 'B', 'en-US': 'A' }, true],
  ])('%p and %p are the same: %p', (left, right, expected) => {
    expect(sameNotes(left, right)).toBe(expected)
  })
})

describe('versionError', () => {
  test('accepts a version at the limit, ignoring surrounding spaces', () => {
    expect(versionError(` ${'1'.repeat(RELEASE_VERSION_MAX_LENGTH)} `)).toBe(
      null,
    )
  })

  test('rejects a version over the limit', () => {
    expect(versionError('1'.repeat(RELEASE_VERSION_MAX_LENGTH + 1))).toBe(
      'The version can be at most 64 characters.',
    )
  })
})

describe('isRolloutPercent', () => {
  test.each([
    [String(MIN_ROLLOUT_PERCENT), true],
    ['50', true],
    [String(MAX_ROLLOUT_PERCENT), true],
    ['0', false],
    ['101', false],
    ['12.5', false],
    ['', false],
    ['  ', false],
    ['abc', false],
  ])('%p is a rollout percentage: %p', (value, expected) => {
    expect(isRolloutPercent(value)).toBe(expected)
  })
})

describe('rolloutMismatch', () => {
  test('is null when the store applied the requested rollout', () => {
    expect(rolloutMismatch(0.1, 0.1)).toBe(null)
    expect(rolloutMismatch(0.333, 0.33)).toBe(null)
  })

  test('reports both percentages when the store applied another rollout', () => {
    expect(rolloutMismatch(0.1, 1)).toEqual({ requested: 10, applied: 100 })
  })

  test('formats the warning', () => {
    expect(
      rolloutMismatchMessage(ROLLOUT_MISMATCH_MESSAGE, {
        requested: 10,
        applied: 100,
      }),
    ).toBe('The store applied a 100% rollout instead of the requested 10%.')
  })
})

describe('buildSubmissionBlocker', () => {
  test.each([
    [false, true, 'ready', "You don't have permission to manage submissions."],
    [true, false, 'ready', "This build's target no longer exists."],
    [true, true, null, "This build's artifact is still processing."],
    [true, true, 'uploading', "This build's artifact is still processing."],
    [true, true, 'processing', "This build's artifact is still processing."],
    [
      true,
      true,
      'invalid',
      "This build's artifact is not available for submission.",
    ],
    [true, true, 'ready', null],
  ])(
    'allowed %p, target %p, artifact %p',
    (allowed, targetExists, artifactStatus, expected) => {
      expect(
        buildSubmissionBlocker({ allowed, targetExists, artifactStatus }),
      ).toBe(expected)
    },
  )
})
