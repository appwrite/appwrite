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
