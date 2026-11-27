import type { DistributionCheck } from '@/lib/appwrite/distribution'
import { parseVersionCode } from '@/lib/distribution/builds'
import {
  RELEASE_NOTES_LOCALE,
  RELEASE_VERSION_MAX_LENGTH,
} from '@/lib/distribution/releases'

export const PUBLISH_STEPS = [
  'targets',
  'release',
  'sources',
  'review',
] as const

export type PublishStep = (typeof PUBLISH_STEPS)[number]

export const PUBLISH_STEP_LABELS: Record<PublishStep, string> = {
  targets: 'Targets',
  release: 'Release',
  sources: 'Source',
  review: 'Summary',
}

export type TargetSelection = {
  selected: boolean
}

type CheckIssue = { severity: string }

type CheckResult = { issues: readonly CheckIssue[] }

export type TargetCheck =
  | { status: 'running' }
  | { status: 'done'; check: DistributionCheck }
  | { status: 'failed'; error: string }

export type ReleaseMode = 'new' | 'existing'

export type ReleaseDraft = {
  mode: ReleaseMode
  releaseId: string
  version: string
  notes: string
}

export const PUBLISH_SOURCES = ['build', 'artifact'] as const

export type SourceKind = (typeof PUBLISH_SOURCES)[number]

export type TargetSource = {
  kind: SourceKind
  artifactId: string
  versionCode: string
  track: string
  automatic: boolean
}

export type TargetProgress =
  | { status: 'pending' }
  | { status: 'running' }
  | { status: 'done' }
  | { status: 'failed'; error: string }

export function checkKey(targetId: string, track: string): string {
  return `${targetId}:${track}`
}

export function hasBlockingIssues(check: CheckResult): boolean {
  return check.issues.some((issue) => issue.severity === 'error')
}

export type TargetsState =
  | 'empty'
  | 'unchecked'
  | 'checking'
  | 'failed'
  | 'blocked'
  | 'ready'

export function targetsState(
  selected: readonly { targetId: string; track: string }[],
  checks: Readonly<Record<string, TargetCheck>>,
): TargetsState {
  if (selected.length === 0) return 'empty'
  const results = selected.map(
    ({ targetId, track }) => checks[checkKey(targetId, track)],
  )
  if (results.some((result) => result?.status === 'running')) return 'checking'
  if (results.some((result) => result === undefined)) return 'unchecked'
  if (results.some((result) => result.status === 'failed')) return 'failed'
  if (
    results.some(
      (result) => result.status === 'done' && hasBlockingIssues(result.check),
    )
  ) {
    return 'blocked'
  }
  return 'ready'
}

export function isReleaseValid(release: ReleaseDraft): boolean {
  if (release.mode === 'existing') return release.releaseId !== ''
  const version = release.version.trim()
  return version.length > 0 && version.length <= RELEASE_VERSION_MAX_LENGTH
}

export function releaseNotes(
  release: ReleaseDraft,
): Record<string, string> | undefined {
  const notes = release.notes.trim()
  return notes ? { [RELEASE_NOTES_LOCALE]: notes } : undefined
}

export function isSourceValid(
  source: TargetSource,
  manualVersion: boolean,
  branch: string,
): boolean {
  if (source.kind === 'artifact') return source.artifactId !== ''
  if (branch.trim() === '') return false
  return !manualVersion || parseVersionCode(source.versionCode) !== null
}

export function targetTrack(track: string, tracks: readonly string[]): string {
  return tracks.includes(track) ? track : tracks[0]
}

export function publishTrack(
  source: TargetSource | undefined,
  defaultTrack: string,
): string {
  return source?.kind === 'artifact' ? source.track : defaultTrack
}

export function publishOrder<T extends { $id: string }>(
  targets: readonly T[],
  sources: Readonly<Record<string, TargetSource>>,
): T[] {
  const kind = (target: T) => sources[target.$id]?.kind
  return [
    ...targets.filter((target) => kind(target) === 'artifact'),
    ...targets.filter((target) => kind(target) !== 'artifact'),
  ]
}

export function isPublishComplete(
  targetIds: readonly string[],
  progress: Readonly<Record<string, TargetProgress>>,
): boolean {
  return (
    targetIds.length > 0 &&
    targetIds.every((targetId) => progress[targetId]?.status === 'done')
  )
}

export function artifactVersion(artifact: {
  versionName: string
  versionCode: number
  buildNumber: string
}): string {
  const build =
    artifact.buildNumber ||
    (artifact.versionCode > 0 ? String(artifact.versionCode) : '')
  return [artifact.versionName, build ? `(${build})` : '']
    .filter((part) => part !== '')
    .join(' ')
}
