/**
 * Distribution (App Store Pipeline) mock data layer
 *
 * POC fixtures modeled on the `/v1/distribution` RFC resources. This module is
 * the single isolated place that fabricates data; the react-query hooks in
 * `@/lib/react-query/hooks/distribution` consume these fetchers and can be
 * pointed at the real SDK once it exists, without touching the page components.
 */

export type DistributionPlatform = 'android' | 'ios' | 'windows'

export type DistributionFramework =
  | 'flutter'
  | 'react-native'
  | 'expo'
  | 'android'
  | 'ios'
  | 'maui'
  | 'other'

export type DistributionArtifactType = 'aab' | 'apk' | 'ipa' | 'msix'

export type DistributionBuildStatus =
  | 'building'
  | 'ready'
  | 'failed'
  | 'canceled'

export type DistributionProvider =
  | 'google-play'
  | 'app-store-connect'
  | 'microsoft-store'

export type DistributionSubmissionStatus =
  | 'queued'
  | 'processing'
  | 'in_review'
  | 'approved'
  | 'published'
  | 'rejected'
  | 'failed'

export interface DistributionRepository {
  provider: string
  owner: string
  name: string
  branch: string
  url: string
}

export interface DistributionApp {
  $id: string
  name: string
  platforms: DistributionPlatform[]
  framework: DistributionFramework
  applicationId?: string
  bundleId?: string
  packageIdentity?: string
  repository: DistributionRepository
  autoSubmit: boolean
  defaultTrack: string
  enabled: boolean
  $createdAt: string
  $updatedAt: string
}

export interface DistributionBuild {
  $id: string
  appId: string
  platform: DistributionPlatform
  artifactType: DistributionArtifactType
  status: DistributionBuildStatus
  versionName: string
  versionCode: number
  buildDuration: number
  providerCommitHash: string
  providerCommitMessage: string
  providerBranch: string
  $createdAt: string
}

export interface DistributionSubmission {
  $id: string
  appId: string
  provider: DistributionProvider
  track: string
  status: DistributionSubmissionStatus
  storeReleaseId?: string
  submittedAt: string
}

export interface DistributionAppList {
  apps: DistributionApp[]
  total: number
}

export interface DistributionBuildList {
  builds: DistributionBuild[]
  total: number
}

export interface DistributionSubmissionList {
  submissions: DistributionSubmission[]
  total: number
}

const APPS: DistributionApp[] = [
  {
    $id: 'app-mero-flame',
    name: 'Mero Nepali Flame',
    platforms: ['android', 'ios'],
    framework: 'flutter',
    applicationId: 'io.appwrite.meroflame',
    bundleId: 'io.appwrite.meroflame',
    repository: {
      provider: 'github',
      owner: 'appwrite',
      name: 'mero-nepali-flame',
      branch: 'main',
      url: 'https://github.com/appwrite/mero-nepali-flame',
    },
    autoSubmit: true,
    defaultTrack: 'production',
    enabled: true,
    $createdAt: '2026-05-02T09:14:00.000Z',
    $updatedAt: '2026-06-15T17:42:00.000Z',
  },
  {
    $id: 'app-pulse-mobile',
    name: 'Pulse',
    platforms: ['ios'],
    framework: 'expo',
    bundleId: 'io.appwrite.pulse',
    repository: {
      provider: 'github',
      owner: 'appwrite',
      name: 'pulse-mobile',
      branch: 'release',
      url: 'https://github.com/appwrite/pulse-mobile',
    },
    autoSubmit: false,
    defaultTrack: 'testflight',
    enabled: true,
    $createdAt: '2026-04-21T11:30:00.000Z',
    $updatedAt: '2026-06-12T08:05:00.000Z',
  },
  {
    $id: 'app-ledger-desktop',
    name: 'Ledger',
    platforms: ['windows'],
    framework: 'maui',
    packageIdentity: 'Appwrite.Ledger',
    repository: {
      provider: 'github',
      owner: 'appwrite',
      name: 'ledger-desktop',
      branch: 'main',
      url: 'https://github.com/appwrite/ledger-desktop',
    },
    autoSubmit: false,
    defaultTrack: 'production',
    enabled: false,
    $createdAt: '2026-03-18T14:50:00.000Z',
    $updatedAt: '2026-06-01T10:22:00.000Z',
  },
  {
    $id: 'app-courier-rn',
    name: 'Courier',
    platforms: ['android', 'ios'],
    framework: 'react-native',
    applicationId: 'io.appwrite.courier',
    bundleId: 'io.appwrite.courier',
    repository: {
      provider: 'github',
      owner: 'appwrite',
      name: 'courier-app',
      branch: 'main',
      url: 'https://github.com/appwrite/courier-app',
    },
    autoSubmit: true,
    defaultTrack: 'internal',
    enabled: true,
    $createdAt: '2026-02-09T07:00:00.000Z',
    $updatedAt: '2026-06-16T19:11:00.000Z',
  },
]

const BUILDS: Record<string, DistributionBuild[]> = {
  'app-mero-flame': [
    {
      $id: 'build-flame-1',
      appId: 'app-mero-flame',
      platform: 'android',
      artifactType: 'aab',
      status: 'ready',
      versionName: '2.4.1',
      versionCode: 241,
      buildDuration: 312,
      providerCommitHash: 'a1b2c3d',
      providerCommitMessage: 'Fix push notification deep links',
      providerBranch: 'main',
      $createdAt: '2026-06-15T17:42:00.000Z',
    },
    {
      $id: 'build-flame-2',
      appId: 'app-mero-flame',
      platform: 'ios',
      artifactType: 'ipa',
      status: 'building',
      versionName: '2.4.1',
      versionCode: 241,
      buildDuration: 0,
      providerCommitHash: 'a1b2c3d',
      providerCommitMessage: 'Fix push notification deep links',
      providerBranch: 'main',
      $createdAt: '2026-06-15T17:41:00.000Z',
    },
    {
      $id: 'build-flame-3',
      appId: 'app-mero-flame',
      platform: 'android',
      artifactType: 'aab',
      status: 'failed',
      versionName: '2.4.0',
      versionCode: 240,
      buildDuration: 198,
      providerCommitHash: 'f4e5d6c',
      providerCommitMessage: 'Bump dependencies',
      providerBranch: 'main',
      $createdAt: '2026-06-09T12:05:00.000Z',
    },
  ],
  'app-pulse-mobile': [
    {
      $id: 'build-pulse-1',
      appId: 'app-pulse-mobile',
      platform: 'ios',
      artifactType: 'ipa',
      status: 'ready',
      versionName: '1.8.0',
      versionCode: 80,
      buildDuration: 421,
      providerCommitHash: '9a8b7c6',
      providerCommitMessage: 'Add offline sync',
      providerBranch: 'release',
      $createdAt: '2026-06-12T08:05:00.000Z',
    },
  ],
  'app-courier-rn': [
    {
      $id: 'build-courier-1',
      appId: 'app-courier-rn',
      platform: 'android',
      artifactType: 'apk',
      status: 'ready',
      versionName: '3.1.2',
      versionCode: 312,
      buildDuration: 276,
      providerCommitHash: 'c0ffee1',
      providerCommitMessage: 'Improve route ETA accuracy',
      providerBranch: 'main',
      $createdAt: '2026-06-16T19:11:00.000Z',
    },
    {
      $id: 'build-courier-2',
      appId: 'app-courier-rn',
      platform: 'ios',
      artifactType: 'ipa',
      status: 'canceled',
      versionName: '3.1.1',
      versionCode: 311,
      buildDuration: 64,
      providerCommitHash: 'b4dc0de',
      providerCommitMessage: 'Hotfix crash on cold start',
      providerBranch: 'main',
      $createdAt: '2026-06-14T10:48:00.000Z',
    },
  ],
}

const SUBMISSIONS: Record<string, DistributionSubmission[]> = {
  'app-mero-flame': [
    {
      $id: 'sub-flame-1',
      appId: 'app-mero-flame',
      provider: 'google-play',
      track: 'production',
      status: 'published',
      storeReleaseId: 'rel-9921',
      submittedAt: '2026-06-15T18:30:00.000Z',
    },
    {
      $id: 'sub-flame-2',
      appId: 'app-mero-flame',
      provider: 'app-store-connect',
      track: 'production',
      status: 'in_review',
      storeReleaseId: 'rel-9920',
      submittedAt: '2026-06-15T18:31:00.000Z',
    },
  ],
  'app-pulse-mobile': [
    {
      $id: 'sub-pulse-1',
      appId: 'app-pulse-mobile',
      provider: 'app-store-connect',
      track: 'testflight',
      status: 'approved',
      storeReleaseId: 'rel-7741',
      submittedAt: '2026-06-12T09:00:00.000Z',
    },
  ],
  'app-courier-rn': [
    {
      $id: 'sub-courier-1',
      appId: 'app-courier-rn',
      provider: 'google-play',
      track: 'internal',
      status: 'processing',
      submittedAt: '2026-06-16T19:40:00.000Z',
    },
    {
      $id: 'sub-courier-2',
      appId: 'app-courier-rn',
      provider: 'app-store-connect',
      track: 'production',
      status: 'rejected',
      storeReleaseId: 'rel-5510',
      submittedAt: '2026-06-10T15:12:00.000Z',
    },
  ],
}

const NETWORK_DELAY_MS = 120

function delay<T>(value: T): Promise<T> {
  return new Promise((resolve) =>
    setTimeout(() => resolve(value), NETWORK_DELAY_MS),
  )
}

export function fetchDistributionApps(
  _projectId: string,
  page: number = 0,
  limit: number = 10,
  search?: string,
): Promise<DistributionAppList> {
  const term = search?.trim().toLowerCase()
  const filtered = term
    ? APPS.filter((app) => app.name.toLowerCase().includes(term))
    : APPS
  const start = page * limit
  return delay({
    apps: filtered.slice(start, start + limit),
    total: filtered.length,
  })
}

export function fetchDistributionApp(
  _projectId: string,
  appId: string,
): Promise<DistributionApp> {
  const app = APPS.find((item) => item.$id === appId)
  if (!app) {
    return Promise.reject(new Error('Distribution app not found'))
  }
  return delay(app)
}

export function fetchDistributionBuilds(
  _projectId: string,
  appId: string,
  page: number = 0,
  limit: number = 10,
): Promise<DistributionBuildList> {
  const all = BUILDS[appId] ?? []
  const start = page * limit
  return delay({
    builds: all.slice(start, start + limit),
    total: all.length,
  })
}

export function fetchDistributionSubmissions(
  _projectId: string,
  appId: string,
  page: number = 0,
  limit: number = 10,
): Promise<DistributionSubmissionList> {
  const all = SUBMISSIONS[appId] ?? []
  const start = page * limit
  return delay({
    submissions: all.slice(start, start + limit),
    total: all.length,
  })
}
