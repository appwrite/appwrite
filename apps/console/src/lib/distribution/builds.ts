import type {
  CreateDistributionBuildParams,
  DistributionApp,
  DistributionBuild,
  DistributionBuildReferenceType,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { isBuildActive } from '@/lib/distribution/status'

export const REFERENCE_TYPES = [
  'branch',
  'tag',
  'commit',
] as const satisfies readonly DistributionBuildReferenceType[]

export const REFERENCE_TYPE_LABELS: Record<
  DistributionBuildReferenceType,
  string
> = {
  branch: 'Branch',
  tag: 'Tag',
  commit: 'Commit',
}

const TRIGGER_LABELS: Record<string, string> = {
  manual: 'Manual',
  vcs: 'Git push',
}

const STAGE_LABELS: Record<string, string> = {
  source: 'Fetching source',
  inspect: 'Inspecting',
  build: 'Building',
  sign: 'Signing',
  store: 'Uploading to the store',
}

const COMMIT_LENGTH = 7

export const GOOGLE_PLAY_VERSION_CODE_MAX = 2_100_000_000

const VERSION_CODE_MAX_DIGITS = String(GOOGLE_PLAY_VERSION_CODE_MAX).length

const NO_PERMISSION = "You don't have permission to perform this action."

const NO_REPOSITORY = 'Connect a repository to the app to start builds.'

const NO_COMMIT = 'This build has no commit to build again.'

const ACTIVE_DELETE = 'Cancel the build before you delete it.'

const NO_TARGETS = 'Add a target to the app to start builds.'

export type BuildPermissions = {
  canWrite: boolean
  hasRepository: boolean
}

export type BuildSource = {
  type: DistributionBuildReferenceType
  name: string
  url: string
}

export function hasRepository(
  app: Pick<DistributionApp, 'installationId' | 'providerRepositoryId'>,
): boolean {
  return app.installationId !== '' && app.providerRepositoryId !== ''
}

export function triggerLabel(type: string): string | null {
  return TRIGGER_LABELS[type] ?? null
}

export function stageLabel(stage: string): string | null {
  return STAGE_LABELS[stage] ?? null
}

export function shortCommit(hash: string): string {
  return hash.slice(0, COMMIT_LENGTH)
}

export function buildSource(build: DistributionBuild): BuildSource | null {
  if (build.providerTag) {
    return { type: 'tag', name: build.providerTag, url: '' }
  }
  if (build.providerBranch) {
    return {
      type: 'branch',
      name: build.providerBranch,
      url: build.providerBranchUrl,
    }
  }
  if (build.providerCommitHash) {
    return {
      type: 'commit',
      name: shortCommit(build.providerCommitHash),
      url: build.providerCommitUrl,
    }
  }
  return null
}

export function buildNumber(build: DistributionBuild): string {
  if (build.buildNumber) return build.buildNumber
  return build.versionCode > 0 ? String(build.versionCode) : ''
}

export function buildVersion(build: DistributionBuild): string {
  const number = buildNumber(build)
  if (build.versionName && number) return `${build.versionName} (${number})`
  return build.versionName || number
}

export function buildDuration(build: DistributionBuild, now: number): number {
  if (build.duration > 0) return build.duration
  if (!build.startedAt) return 0
  const started = Date.parse(build.startedAt)
  const ended = build.endedAt ? Date.parse(build.endedAt) : now
  if (Number.isNaN(started) || Number.isNaN(ended)) return 0
  return Math.max(0, Math.floor((ended - started) / 1000))
}

function pad(value: number): string {
  return String(value).padStart(2, '0')
}

export function formatDuration(seconds: number): string {
  const whole = Math.max(0, Math.floor(seconds))
  if (whole < 60) return `${whole}s`
  const minutes = Math.floor(whole / 60)
  if (minutes < 60) return `${minutes}m ${pad(whole % 60)}s`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ${pad(minutes % 60)}m`
  return `${Math.floor(hours / 24)}d ${pad(hours % 24)}h`
}

export function retryBuildParams(
  build: DistributionBuild,
  target: DistributionTarget | undefined,
): CreateDistributionBuildParams | null {
  if (!build.providerCommitHash) return null
  const manual = target?.versionStrategy === 'manual'
  return {
    appId: build.appId,
    targetId: build.targetId,
    type: 'commit',
    reference: build.providerCommitHash,
    ...(manual && build.versionCode > 0
      ? { versionCode: build.versionCode }
      : {}),
    ...(build.releaseId ? { releaseId: build.releaseId } : {}),
  }
}

export function parseVersionCode(value: string): number | null {
  const trimmed = value.trim()
  if (trimmed === '' || trimmed.length > VERSION_CODE_MAX_DIGITS) return null
  for (const character of trimmed) {
    if (character < '0' || character > '9') return null
  }
  const code = Number(trimmed)
  return code > 0 && code <= GOOGLE_PLAY_VERSION_CODE_MAX ? code : null
}

export function createBlocker(
  app: DistributionApp | undefined,
  targets: readonly DistributionTarget[],
  { canWrite, hasRepository }: BuildPermissions,
): string | null {
  if (!canWrite) return NO_PERMISSION
  if (!app) return null
  if (!hasRepository) return NO_REPOSITORY
  if (targets.length === 0) return NO_TARGETS
  return null
}

export function retryBlocker(
  build: DistributionBuild,
  { canWrite, hasRepository }: BuildPermissions,
): string | null {
  if (!canWrite) return NO_PERMISSION
  if (!hasRepository) return NO_REPOSITORY
  if (!build.providerCommitHash) return NO_COMMIT
  return null
}

export function deleteBlocker(
  build: DistributionBuild,
  { canWrite }: BuildPermissions,
): string | null {
  if (!canWrite) return NO_PERMISSION
  if (isBuildActive(build.status)) return ACTIVE_DELETE
  return null
}

export function cancelBlocker({ canWrite }: BuildPermissions): string | null {
  return canWrite ? null : NO_PERMISSION
}
