import type { UploadProgress } from '@appwrite.io/console'

export type DistributionPlatform = 'ios' | 'android'

export type DistributionFramework =
  | 'flutter'
  | 'react-native'
  | 'android'
  | 'ios'

export type DistributionProvider = 'apple' | 'google'

export type DistributionSigningMode = 'imported' | 'managed'

export type DistributionSigningIdentityStatus =
  | 'active'
  | 'expiring'
  | 'expired'
  | 'revoked'

export type DistributionConnectionStatus = 'active' | 'invalid'

export type DistributionVersionStrategy = 'automatic' | 'manual'

export type DistributionBuildStatus =
  | 'waiting'
  | 'processing'
  | 'building'
  | 'signing'
  | 'ready'
  | 'failed'
  | 'canceled'

export type DistributionApp = {
  $id: string
  $createdAt: string
  $updatedAt: string
  name: string
  enabled: boolean
  framework: DistributionFramework
  installationId: string
  providerRepositoryId: string
  repositoryId: string
  providerBranch: string
  providerRootDirectory: string
  providerBranches: string[]
  providerPaths: string[]
  providerTags: string[]
  providerSilentMode: boolean
  providerComments: boolean
  providerDeployments: boolean
  providerStatuses: boolean
  latestBuildId: string
  latestBuildStatus: DistributionBuildStatus | ''
  latestBuildCreatedAt: string
}

export type DistributionAppList = {
  total: number
  apps: DistributionApp[]
}

export type DistributionTargetConfiguration = {
  scheme?: string
  workspace?: string
  project?: string
  configuration?: string
  flavor?: string
  gradleTask?: string
  xcode?: string
  flutter?: string
  node?: string
  java?: string
}

export type DistributionTarget = {
  $id: string
  $createdAt: string
  $updatedAt: string
  appId: string
  platform: DistributionPlatform
  identifier: string
  connectionId: string
  signingIdentityId: string
  storeAppId: string
  track: string
  automatic: boolean
  versionStrategy: DistributionVersionStrategy
  versionCode: number
  installCommand: string
  buildCommand: string
  outputPath: string
  configuration: DistributionTargetConfiguration
  specification: string
  timeout: number
}

export type DistributionTargetList = {
  total: number
  targets: DistributionTarget[]
}

export type DistributionConnectionAccount = {
  teamId?: string
  teamName?: string
  issuerId?: string
  keyId?: string
  clientEmail?: string
  projectId?: string
}

export type DistributionConnection = {
  $id: string
  $createdAt: string
  $updatedAt: string
  provider: DistributionProvider
  name: string
  account: DistributionConnectionAccount
  status: DistributionConnectionStatus
  error: string
  checkedAt: string
}

export type DistributionConnectionList = {
  total: number
  connections: DistributionConnection[]
}

export type DistributionSigningIdentity = {
  $id: string
  $createdAt: string
  $updatedAt: string
  platform: DistributionPlatform
  name: string
  mode: DistributionSigningMode
  fingerprint: string
  teamId: string
  identifiers: string[]
  connectionId: string
  externalId: string
  expiresAt: string
  status: DistributionSigningIdentityStatus
}

export type DistributionSigningIdentityList = {
  total: number
  signingIdentities: DistributionSigningIdentity[]
}

export type DistributionSigningIdentityFile = {
  name: string
  content: string
}

export type DistributionSigningIdentityPasswords = {
  certificate?: string
  storePassword?: string
  keyPassword?: string
}

export type DistributionSigningIdentityDownload = {
  files: DistributionSigningIdentityFile[]
  passwords: DistributionSigningIdentityPasswords
  alias: string
}

export type DistributionListParams = {
  queries?: string[]
  total?: boolean
}

export type DistributionAppRepositoryParams = {
  installationId?: string
  providerRepositoryId?: string
  providerBranch?: string
  providerRootDirectory?: string
  providerBranches?: string[]
  providerPaths?: string[]
  providerTags?: string[]
  providerSilentMode?: boolean
  providerComments?: boolean
  providerDeployments?: boolean
  providerStatuses?: boolean
}

export type CreateDistributionAppParams = DistributionAppRepositoryParams & {
  appId: string
  name: string
  framework: DistributionFramework
  enabled?: boolean
}

export type UpdateDistributionAppParams = DistributionAppRepositoryParams & {
  appId: string
  name?: string
  framework?: DistributionFramework
  enabled?: boolean
}

export type DistributionTargetSettingsParams = {
  identifier?: string
  connectionId?: string
  signingIdentityId?: string
  track?: string
  automatic?: boolean
  versionStrategy?: DistributionVersionStrategy
  installCommand?: string
  buildCommand?: string
  outputPath?: string
  configuration?: DistributionTargetConfiguration
  specification?: string
  timeout?: number
}

export type CreateDistributionTargetParams =
  DistributionTargetSettingsParams & {
    appId: string
    targetId: string
    platform: DistributionPlatform
    identifier: string
  }

export type UpdateDistributionTargetParams =
  DistributionTargetSettingsParams & {
    appId: string
    targetId: string
  }

export type CreateDistributionVariableParams = {
  appId: string
  variableId: string
  key: string
  value: string
  secret?: boolean
}

export type UpdateDistributionVariableParams = {
  appId: string
  variableId: string
  key: string
  value?: string
  secret?: boolean
}

export type CreateDistributionAppleConnectionParams = {
  connectionId: string
  name: string
  keyId: string
  issuerId: string
  privateKey: string
}

export type UpdateDistributionAppleConnectionParams = {
  connectionId: string
  name?: string
  keyId?: string
  issuerId?: string
  privateKey?: string
}

export type DistributionGoogleServiceAccount = {
  client_email: string
  private_key: string
  project_id?: string
}

export type CreateDistributionGoogleConnectionParams = {
  connectionId: string
  name: string
  serviceAccount: DistributionGoogleServiceAccount
}

export type UpdateDistributionGoogleConnectionParams = {
  connectionId: string
  name?: string
  serviceAccount?: DistributionGoogleServiceAccount
}

export type CreateDistributionIosSigningIdentityParams = {
  signingIdentityId: string
  name: string
  certificate?: string
  password?: string
  profiles?: string[]
  connectionId?: string
  identifiers?: string[]
}

export type CreateDistributionAndroidSigningIdentityParams = {
  signingIdentityId: string
  name: string
  keystore?: string
  storePassword?: string
  keyAlias?: string
  keyPassword?: string
}

export type UpdateDistributionSigningIdentityParams = {
  signingIdentityId: string
  name?: string
  rotate?: boolean
}

export type DistributionBuildTrigger = 'manual' | 'vcs'

export type DistributionBuildReferenceType = 'branch' | 'commit' | 'tag'

export type DistributionArtifactType = 'ipa' | 'aab'

export type DistributionArtifactSource = 'build' | 'upload'

export type DistributionArtifactStatus =
  | 'uploading'
  | 'processing'
  | 'ready'
  | 'invalid'

export type DistributionReleaseStatus =
  | 'draft'
  | 'active'
  | 'published'
  | 'attention'

export type DistributionSubmissionStatus =
  | 'waiting'
  | 'validating'
  | 'uploading'
  | 'processing'
  | 'pending'
  | 'reviewing'
  | 'approved'
  | 'published'
  | 'rejected'
  | 'halted'
  | 'failed'
  | 'canceled'

export type DistributionSubmissionStep =
  | 'validated'
  | 'located'
  | 'uploaded'
  | 'processed'
  | 'released'
  | 'settled'

export type DistributionBuildStatusRequest = 'canceled'

export type DistributionSubmissionStatusRequest =
  | 'canceled'
  | 'halted'
  | 'published'

export type DistributionCheckSeverity = 'error' | 'warning'

export type DistributionBuild = {
  $id: string
  $createdAt: string
  $updatedAt: string
  appId: string
  targetId: string
  platform: DistributionPlatform
  type: DistributionBuildTrigger
  status: DistributionBuildStatus
  stage: string
  attempt: number
  providerCommitHash: string
  providerBranch: string
  providerTag: string
  providerCommitMessage: string
  providerCommitAuthor: string
  providerCommitAuthorUrl: string
  providerCommitUrl: string
  providerBranchUrl: string
  providerRepositoryName: string
  providerRepositoryOwner: string
  providerRepositoryUrl: string
  providerRootDirectory: string
  versionName: string
  versionCode: number
  buildNumber: string
  toolchain: Record<string, string>
  specification: string
  cpus: number
  memory: number
  timeout: number
  artifactId: string
  releaseId: string
  logsSize: number
  dispatchedAt: string | null
  startedAt: string | null
  endedAt: string | null
  duration: number
  errorCode: string
  errorMessage: string
}

export type DistributionBuildList = {
  total: number
  builds: DistributionBuild[]
}

export type DistributionLog = {
  $id: string
  $createdAt: string
  buildId: string
  sequence: string
  chunk: number
  stage: string
  content: string
}

export type DistributionLogList = {
  total: number
  logs: DistributionLog[]
}

export type DistributionArtifact = {
  $id: string
  $createdAt: string
  $updatedAt: string
  appId: string
  targetId: string
  platform: DistributionPlatform | ''
  type: DistributionArtifactType
  source: DistributionArtifactSource
  buildId: string
  status: DistributionArtifactStatus
  name: string
  size: number
  chunksTotal: number
  chunksUploaded: number
  digest: string
  identifier: string
  versionName: string
  versionCode: number
  buildNumber: string
  signed: boolean
  fingerprint: string
  manifest: Record<string, unknown>
  errorCode: string
  errorMessage: string
  expiresAt: string | null
}

export type DistributionArtifactList = {
  total: number
  artifacts: DistributionArtifact[]
}

export type DistributionRelease = {
  $id: string
  $createdAt: string
  $updatedAt: string
  appId: string
  version: string
  notes: Record<string, string>
  status: DistributionReleaseStatus
}

export type DistributionReleaseList = {
  total: number
  releases: DistributionRelease[]
}

export type DistributionSubmission = {
  $id: string
  $createdAt: string
  $updatedAt: string
  appId: string
  releaseId: string
  targetId: string
  artifactId: string
  platform: DistributionPlatform
  provider: DistributionProvider
  track: string
  rollout: number
  automatic: boolean
  status: DistributionSubmissionStatus
  storeStatus: string
  step: DistributionSubmissionStep | ''
  external: Record<string, unknown>
  key: string
  attempt: number
  retryAt: string | null
  submittedAt: string | null
  reviewedAt: string | null
  publishedAt: string | null
  errorCode: string
  errorMessage: string
}

export type DistributionSubmissionList = {
  total: number
  submissions: DistributionSubmission[]
}

export type DistributionCheckIssue = {
  code: string
  severity: DistributionCheckSeverity
  platform: DistributionPlatform | ''
  field: string
  message: string
  remediation: string
  manualActionRequired: boolean
}

export type DistributionCheck = {
  ready: boolean
  issues: DistributionCheckIssue[]
}

export type CreateDistributionBuildParams = {
  appId: string
  targetId: string
  type?: DistributionBuildReferenceType
  reference?: string
  versionName?: string
  versionCode?: number
  releaseId?: string
  key?: string
}

export type UpdateDistributionBuildStatusParams = {
  appId: string
  buildId: string
  status: DistributionBuildStatusRequest
}

export type CreateDistributionArtifactParams = {
  appId: string
  file: File
  artifactId?: string
  onProgress?: (progress: UploadProgress) => void
}

export type CreateDistributionReleaseParams = {
  appId: string
  releaseId: string
  version: string
  notes?: Record<string, string>
}

export type UpdateDistributionReleaseParams = {
  appId: string
  releaseId: string
  version?: string
  notes?: Record<string, string>
}

export type CreateDistributionSubmissionParams = {
  appId: string
  releaseId: string
  targetId: string
  artifactId: string
  track?: string
  rollout?: number
  automatic?: boolean
  key?: string
}

export type UpdateDistributionSubmissionParams = {
  appId: string
  submissionId: string
  automatic?: boolean
  rollout?: number
}

export type UpdateDistributionSubmissionStatusParams = {
  appId: string
  submissionId: string
  status: DistributionSubmissionStatusRequest
}

export type CreateDistributionCheckParams = {
  appId: string
  targetId: string
  track?: string
}
