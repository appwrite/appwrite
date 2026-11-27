import { isArtifactPending } from './status'

export const RELEASE_VERSION_MAX_LENGTH = 64

export const RELEASE_NOTES_LOCALE = 'en-US'

export const MIN_ROLLOUT_PERCENT = 1

export const MAX_ROLLOUT_PERCENT = 100

export type NoteRow = {
  key: number
  locale: string
  text: string
}

let nextKey = 0

export function noteRow(locale: string, text: string): NoteRow {
  nextKey += 1
  return { key: nextKey, locale, text }
}

export function notesToRows(notes: Record<string, string>): NoteRow[] {
  const rows = Object.entries(notes).map(([locale, text]) =>
    noteRow(locale, text),
  )
  return rows.length > 0 ? rows : [noteRow(RELEASE_NOTES_LOCALE, '')]
}

export function rowsToNotes(rows: NoteRow[]): Record<string, string> {
  return Object.fromEntries(
    rows
      .filter((row) => row.locale.trim() && row.text.trim())
      .map((row) => [row.locale.trim(), row.text.trim()]),
  )
}

export function notesError(rows: NoteRow[]): string | null {
  const filled = rows.filter((row) => row.text.trim())
  if (filled.some((row) => !row.locale.trim())) {
    return 'Enter a locale for every note.'
  }
  const locales = filled.map((row) => row.locale.trim())
  if (new Set(locales).size !== locales.length) {
    return 'Each locale can have only one note.'
  }
  return null
}

export function sameNotes(
  left: Record<string, string>,
  right: Record<string, string>,
): boolean {
  const leftKeys = Object.keys(left)
  return (
    leftKeys.length === Object.keys(right).length &&
    leftKeys.every((key) => right[key] === left[key])
  )
}

export function versionError(version: string): string | null {
  if (version.trim().length > RELEASE_VERSION_MAX_LENGTH) {
    return 'The version can be at most 64 characters.'
  }
  return null
}

export function isRolloutPercent(value: string): boolean {
  const percent = Number(value)
  return (
    value.trim() !== '' &&
    Number.isInteger(percent) &&
    percent >= MIN_ROLLOUT_PERCENT &&
    percent <= MAX_ROLLOUT_PERCENT
  )
}

export const ROLLOUT_MISMATCH_MESSAGE =
  'The store applied a {percent}% rollout instead of the requested {requested}%.'

export type RolloutMismatch = {
  requested: number
  applied: number
}

export function rolloutMismatch(
  requested: number,
  applied: number,
): RolloutMismatch | null {
  const mismatch = {
    requested: Math.round(requested * 100),
    applied: Math.round(applied * 100),
  }
  return mismatch.requested === mismatch.applied ? null : mismatch
}

export function rolloutMismatchMessage(
  template: string,
  { requested, applied }: RolloutMismatch,
): string {
  return template
    .replace('{percent}', String(applied))
    .replace('{requested}', String(requested))
}

export type BuildSubmission = {
  allowed: boolean
  targetExists: boolean
  artifactStatus: string | null
}

export function buildSubmissionBlocker({
  allowed,
  targetExists,
  artifactStatus,
}: BuildSubmission): string | null {
  if (!allowed) return "You don't have permission to manage submissions."
  if (!targetExists) return "This build's target no longer exists."
  if (artifactStatus === 'ready') return null
  if (artifactStatus === null || isArtifactPending(artifactStatus)) {
    return "This build's artifact is still processing."
  }
  return "This build's artifact is not available for submission."
}
