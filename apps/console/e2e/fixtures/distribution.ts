import { mkdir, writeFile } from 'node:fs/promises'
import { join } from 'node:path'
import type { Locator, Page, Request, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import type {
  DistributionApp,
  DistributionArtifact,
  DistributionBuild,
  DistributionCheck,
  DistributionCheckIssue,
  DistributionConnection,
  DistributionGoogleServiceAccount,
  DistributionLog,
  DistributionPlatform,
  DistributionProvider,
  DistributionRelease,
  DistributionReleaseStatus,
  DistributionSigningIdentity,
  DistributionSigningIdentityDownload,
  DistributionSubmission,
  DistributionSubmissionStatus,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { expect, test } from '../fixtures'
import { distributionEvents } from './distribution-events'
import {
  mockDistributionRealtime,
  type DistributionRealtime,
} from './distribution-realtime'

export { distributionEvents, expect, test }

export const NOW = '2026-11-20T12:00:00.000+00:00'

const DAY = 24 * 60 * 60 * 1000

function daysFromNow(days: number): string {
  return new Date(Date.parse(NOW) + days * DAY).toISOString()
}

function minutesFromNow(minutes: number): string {
  return new Date(Date.parse(NOW) + minutes * 60 * 1000).toISOString()
}

export const ACCOUNT = {
  $id: 'user000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Demo Operator',
  registration: NOW,
  status: true,
  labels: [],
  passwordUpdate: NOW,
  email: 'demo@example.com',
  phone: '',
  emailVerification: true,
  phoneVerification: false,
  mfa: false,
  prefs: {},
  targets: [],
  accessedAt: NOW,
} satisfies Partial<Models.User<Models.Preferences>>

export const ORGANIZATION = {
  $id: 'org00000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Personal projects',
  billingPlan: 'tier-0',
  billingEmail: ACCOUNT.email,
  status: 'active',
} satisfies Partial<Models.Organization<Models.Preferences>>

export const PROJECT = {
  $id: 'proj0000000000000000001',
  $createdAt: NOW,
  $updatedAt: NOW,
  name: 'Acme',
  teamId: ORGANIZATION.$id,
  region: 'fra',
  status: 'active',
} satisfies Partial<Models.Project>

export function distributionPath(path: string = ''): string {
  return `/projects/${PROJECT.$id}/distribution${path}`
}

const INSTALLATION_ID = 'installation0001'
const ORGANIZATION_LOGIN = 'acme'

const APP_SHOP = 'app00000000000000000001'
const APP_REWARDS = 'app00000000000000000002'
const APP_FIELD_NOTES = 'app00000000000000000003'

const CONNECTION_APPLE = 'connection0000000000001'
const CONNECTION_GOOGLE = 'connection0000000000002'
const CONNECTION_GOOGLE_INVALID = 'connection0000000000003'

const SIGNING_IOS_IMPORTED = 'signing000000000000001'
const SIGNING_IOS_MANAGED = 'signing000000000000002'
const SIGNING_ANDROID_MANAGED = 'signing000000000000003'
const SIGNING_ANDROID_IMPORTED = 'signing000000000000004'

const BUILD_SHOP_READY = 'build0000000000000000001'
const BUILD_FIELD_NOTES_FAILED = 'build0000000000000000002'
const BUILD_SHOP_ACTIVE = 'build0000000000000000003'

const ARTIFACT_SHOP_IOS = 'artifact000000000000001'
const ARTIFACT_SHOP_ANDROID = 'artifact000000000000002'
const ARTIFACT_PROCESSING = 'artifact000000000000003'
const ARTIFACT_INVALID = 'artifact000000000000004'

const RELEASE_ACTIVE = 'release0000000000000001'
const RELEASE_PUBLISHED = 'release0000000000000002'
const RELEASE_DRAFT = 'release0000000000000003'
const RELEASE_ATTENTION = 'release0000000000000004'

const SUBMISSION_TESTFLIGHT = 'submission0000000000001'
const SUBMISSION_PRODUCTION = 'submission0000000000002'
const SUBMISSION_APPROVED = 'submission0000000000003'
const SUBMISSION_HALTED = 'submission0000000000004'
const SUBMISSION_REVIEWING = 'submission0000000000005'
const SUBMISSION_REJECTED = 'submission0000000000006'

function targetId(appId: string, platform: DistributionPlatform): string {
  return `${appId.slice(-8)}${platform}`
}

export const DISTRIBUTION_IDS = {
  apps: {
    shop: APP_SHOP,
    rewards: APP_REWARDS,
    fieldNotes: APP_FIELD_NOTES,
  },
  targets: {
    shopIos: targetId(APP_SHOP, 'ios'),
    shopAndroid: targetId(APP_SHOP, 'android'),
    rewardsIos: targetId(APP_REWARDS, 'ios'),
    rewardsAndroid: targetId(APP_REWARDS, 'android'),
    fieldNotesAndroid: targetId(APP_FIELD_NOTES, 'android'),
  },
  connections: {
    apple: CONNECTION_APPLE,
    google: CONNECTION_GOOGLE,
    googleInvalid: CONNECTION_GOOGLE_INVALID,
  },
  signingIdentities: {
    iosImported: SIGNING_IOS_IMPORTED,
    iosManaged: SIGNING_IOS_MANAGED,
    androidManaged: SIGNING_ANDROID_MANAGED,
    androidImported: SIGNING_ANDROID_IMPORTED,
  },
  builds: {
    shopReady: BUILD_SHOP_READY,
    fieldNotesFailed: BUILD_FIELD_NOTES_FAILED,
    shopActive: BUILD_SHOP_ACTIVE,
  },
  artifacts: {
    shopIos: ARTIFACT_SHOP_IOS,
    shopAndroid: ARTIFACT_SHOP_ANDROID,
    processing: ARTIFACT_PROCESSING,
    invalid: ARTIFACT_INVALID,
  },
  releases: {
    active: RELEASE_ACTIVE,
    published: RELEASE_PUBLISHED,
    draft: RELEASE_DRAFT,
    attention: RELEASE_ATTENTION,
  },
  submissions: {
    testflight: SUBMISSION_TESTFLIGHT,
    production: SUBMISSION_PRODUCTION,
    approved: SUBMISSION_APPROVED,
    halted: SUBMISSION_HALTED,
    reviewing: SUBMISSION_REVIEWING,
    rejected: SUBMISSION_REJECTED,
  },
} as const

export const ARTIFACT_CHUNK_SIZE = 5 * 1024 * 1024

const TRACKS: Record<DistributionProvider, string[]> = {
  apple: ['testflight', 'testflight-external', 'production'],
  google: ['internal', 'alpha', 'beta', 'production'],
}

const DEFAULT_TRACKS: Record<DistributionPlatform, string> = {
  ios: 'testflight',
  android: 'internal',
}

const APPLE_TEAM_ID = 'A1B2C3D4E5'

const IDENTIFIERS: Record<string, string> = {
  [APP_SHOP]: 'com.acme.shop',
  [APP_REWARDS]: 'com.acme.rewards',
  [APP_FIELD_NOTES]: 'com.acme.fieldnotes',
}

const PROVIDERS: Record<DistributionPlatform, DistributionProvider> = {
  ios: 'apple',
  android: 'google',
}

function app(
  overrides: Partial<DistributionApp> &
    Pick<DistributionApp, '$id' | 'name' | 'framework'>,
): DistributionApp {
  return {
    $createdAt: NOW,
    $updatedAt: NOW,
    enabled: true,
    installationId: '',
    providerRepositoryId: '',
    repositoryId: '',
    providerBranch: '',
    providerRootDirectory: '',
    providerBranches: [],
    providerPaths: [],
    providerTags: [],
    providerSilentMode: false,
    providerComments: true,
    providerDeployments: true,
    providerStatuses: true,
    latestBuildId: '',
    latestBuildStatus: '',
    latestBuildCreatedAt: '',
    ...overrides,
  }
}

export function appsFixtures(): DistributionApp[] {
  return [
    app({
      $id: APP_SHOP,
      $createdAt: daysFromNow(-2),
      name: 'Acme Shop',
      framework: 'flutter',
      installationId: INSTALLATION_ID,
      providerRepositoryId: '1001',
      repositoryId: 'repository0001',
      providerBranch: 'main',
      providerRootDirectory: './',
      providerBranches: ['main', 'release/*'],
      providerPaths: ['lib/**', 'pubspec.yaml'],
      providerTags: ['v*'],
      latestBuildId: 'build0000000000000000001',
      latestBuildStatus: 'ready',
      latestBuildCreatedAt: daysFromNow(-1),
    }),
    app({
      $id: APP_REWARDS,
      $createdAt: daysFromNow(-5),
      name: 'Acme Rewards',
      framework: 'react-native',
    }),
    app({
      $id: APP_FIELD_NOTES,
      $createdAt: daysFromNow(-9),
      name: 'Field Notes',
      framework: 'android',
      installationId: INSTALLATION_ID,
      providerRepositoryId: '1002',
      repositoryId: 'repository0002',
      providerBranch: 'develop',
      providerRootDirectory: 'android',
      providerSilentMode: true,
      providerComments: false,
      latestBuildId: 'build0000000000000000002',
      latestBuildStatus: 'failed',
      latestBuildCreatedAt: daysFromNow(-3),
    }),
  ]
}

export function targetsFixtures(
  appId: string,
  platforms: DistributionPlatform[] = ['ios', 'android'],
): DistributionTarget[] {
  const identifier = IDENTIFIERS[appId] ?? `com.example.${appId}`
  const targets: Record<DistributionPlatform, DistributionTarget> = {
    ios: {
      $id: targetId(appId, 'ios'),
      $createdAt: NOW,
      $updatedAt: NOW,
      appId,
      platform: 'ios',
      identifier,
      connectionId: CONNECTION_APPLE,
      signingIdentityId: SIGNING_IOS_IMPORTED,
      storeAppId: '6740000001',
      track: 'testflight',
      automatic: true,
      versionStrategy: 'automatic',
      versionCode: 42,
      installCommand: '',
      buildCommand: '',
      outputPath: '',
      configuration: {
        scheme: 'Runner',
        workspace: 'ios/Runner.xcworkspace',
        configuration: 'Release',
      },
      specification: 's-4vcpu-8gb',
      timeout: 1800,
    },
    android: {
      $id: targetId(appId, 'android'),
      $createdAt: NOW,
      $updatedAt: NOW,
      appId,
      platform: 'android',
      identifier,
      connectionId: CONNECTION_GOOGLE,
      signingIdentityId: SIGNING_ANDROID_MANAGED,
      storeAppId: '',
      track: 'internal',
      automatic: false,
      versionStrategy: 'manual',
      versionCode: 17,
      installCommand: '',
      buildCommand: '',
      outputPath: '',
      configuration: {
        flavor: 'production',
        gradleTask: 'bundleProductionRelease',
      },
      specification: 's-2vcpu-4gb',
      timeout: 0,
    },
  }
  return platforms.map((platform) => targets[platform])
}

export function variablesFixtures(appId: string): Models.Variable[] {
  const variable = (
    suffix: string,
    key: string,
    value: string,
    secret: boolean,
  ): Models.Variable => ({
    $id: `${appId.slice(-8)}variable${suffix}`,
    $createdAt: NOW,
    $updatedAt: NOW,
    key,
    description: '',
    value: secret ? '' : value,
    secret,
    resourceType: 'distributionApps',
    resourceId: appId,
  })
  return [
    variable('1', 'API_ENDPOINT', 'https://api.acme.dev/v1', false),
    variable('2', 'FLUTTER_BUILD_MODE', 'release', false),
    variable('3', 'SENTRY_DSN', 'https://public@sentry.acme.dev/4', true),
  ]
}

export function connectionsFixtures(): DistributionConnection[] {
  return [
    {
      $id: CONNECTION_APPLE,
      $createdAt: daysFromNow(-30),
      $updatedAt: daysFromNow(-1),
      provider: 'apple',
      name: 'Acme App Store Connect',
      account: {
        teamId: APPLE_TEAM_ID,
        teamName: 'Acme Inc.',
        issuerId: '69a6de7e-0b3c-47e3-e053-5b8c7c11a4d1',
        keyId: '2X9R4HXF34',
      },
      status: 'active',
      error: '',
      checkedAt: daysFromNow(-1),
    },
    {
      $id: CONNECTION_GOOGLE,
      $createdAt: daysFromNow(-28),
      $updatedAt: daysFromNow(-1),
      provider: 'google',
      name: 'Acme Google Play',
      account: {
        clientEmail: 'distribution@acme-mobile.iam.gserviceaccount.com',
        projectId: 'acme-mobile',
      },
      status: 'active',
      error: '',
      checkedAt: daysFromNow(-1),
    },
    {
      $id: CONNECTION_GOOGLE_INVALID,
      $createdAt: daysFromNow(-90),
      $updatedAt: daysFromNow(-6),
      provider: 'google',
      name: 'Legacy Play account',
      account: {
        clientEmail: 'publisher@acme-legacy.iam.gserviceaccount.com',
        projectId: 'acme-legacy',
      },
      status: 'invalid',
      error:
        'The service account has no access to the Google Play Console developer account.',
      checkedAt: daysFromNow(-6),
    },
  ]
}

export function signingFixtures(): DistributionSigningIdentity[] {
  return [
    {
      $id: SIGNING_IOS_IMPORTED,
      $createdAt: daysFromNow(-60),
      $updatedAt: daysFromNow(-60),
      platform: 'ios',
      name: 'Acme Distribution 2026',
      mode: 'imported',
      fingerprint:
        '3f2a9c1d8e7b6a5f4e3d2c1b0a9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a19',
      teamId: APPLE_TEAM_ID,
      identifiers: ['com.acme.shop', 'com.acme.rewards'],
      connectionId: '',
      externalId: '',
      expiresAt: daysFromNow(300),
      status: 'active',
    },
    {
      $id: SIGNING_IOS_MANAGED,
      $createdAt: daysFromNow(-353),
      $updatedAt: daysFromNow(-1),
      platform: 'ios',
      name: 'Managed by Appwrite',
      mode: 'managed',
      fingerprint:
        '9b8a7f6e5d4c3b2a193f2a9c1d8e7b6a5f4e3d2c1b0a9f8e7d6c5b4a3f2e1d0c',
      teamId: APPLE_TEAM_ID,
      identifiers: ['com.acme.fieldnotes'],
      connectionId: CONNECTION_APPLE,
      externalId: 'R7K2M9P4Q1',
      expiresAt: daysFromNow(12),
      status: 'expiring',
    },
    {
      $id: SIGNING_ANDROID_MANAGED,
      $createdAt: daysFromNow(-40),
      $updatedAt: daysFromNow(-40),
      platform: 'android',
      name: 'Upload key',
      mode: 'managed',
      fingerprint:
        'c5b4a3f2e1d0c9b8a7f6e5d4c3b2a193f2a9c1d8e7b6a5f4e3d2c1b0a9f8e7d6',
      teamId: '',
      identifiers: [],
      connectionId: '',
      externalId: '',
      expiresAt: daysFromNow(30 * 365),
      status: 'active',
    },
    {
      $id: SIGNING_ANDROID_IMPORTED,
      $createdAt: daysFromNow(-800),
      $updatedAt: daysFromNow(-20),
      platform: 'android',
      name: 'Legacy keystore',
      mode: 'imported',
      fingerprint:
        'e1d0c9b8a7f6e5d4c3b2a193f2a9c1d8e7b6a5f4e3d2c1b0a9f8e7d6c5b4a3f2',
      teamId: '',
      identifiers: [],
      connectionId: '',
      externalId: '',
      expiresAt: daysFromNow(-20),
      status: 'expired',
    },
  ]
}

export type SigningDownloadOptions = {
  uploadCertificate?: boolean
}

export function signingDownloadFixture(
  signingIdentity: DistributionSigningIdentity,
  { uploadCertificate = false }: SigningDownloadOptions = {},
): DistributionSigningIdentityDownload {
  const content = (text: string) => Buffer.from(text).toString('base64')
  if (signingIdentity.platform === 'ios') {
    return {
      files: [
        { name: 'certificate.p12', content: content('pkcs12') },
        ...signingIdentity.identifiers.map((identifier) => ({
          name: `${identifier}.mobileprovision`,
          content: content(`profile ${identifier}`),
        })),
      ],
      passwords: { certificate: 'p12-password-0001' },
      alias: '',
    }
  }
  const extension = signingIdentity.mode === 'imported' ? 'jks' : 'p12'
  return {
    files: [
      { name: `keystore.${extension}`, content: content('keystore') },
      ...(uploadCertificate
        ? [
            {
              name: 'upload-certificate.pem',
              content: content(
                '-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n',
              ),
            },
          ]
        : []),
    ],
    passwords: {
      storePassword: 'keystore-password-0001',
      keyPassword: 'keystore-password-0001',
    },
    alias: 'upload',
  }
}

const REPOSITORY_SHOP = 'shop-app'
const REPOSITORY_FIELD_NOTES = 'field-notes'

function commit(
  repository: string,
  branch: string,
  hash: string,
  message: string,
): Partial<DistributionBuild> {
  const url = `https://github.com/${ORGANIZATION_LOGIN}/${repository}`
  return {
    providerCommitHash: hash,
    providerBranch: branch,
    providerCommitMessage: message,
    providerCommitAuthor: ACCOUNT.name,
    providerCommitAuthorUrl: 'https://github.com/demo-operator',
    providerCommitUrl: `${url}/commit/${hash}`,
    providerBranchUrl: branch ? `${url}/tree/${branch}` : '',
    providerRepositoryName: repository,
    providerRepositoryOwner: ORGANIZATION_LOGIN,
    providerRepositoryUrl: url,
  }
}

function build(
  overrides: Partial<DistributionBuild> &
    Pick<
      DistributionBuild,
      '$id' | 'appId' | 'targetId' | 'platform' | 'status'
    >,
): DistributionBuild {
  return {
    $createdAt: NOW,
    $updatedAt: NOW,
    type: 'manual',
    stage: '',
    attempt: 1,
    providerCommitHash: '',
    providerBranch: '',
    providerTag: '',
    providerCommitMessage: '',
    providerCommitAuthor: '',
    providerCommitAuthorUrl: '',
    providerCommitUrl: '',
    providerBranchUrl: '',
    providerRepositoryName: '',
    providerRepositoryOwner: '',
    providerRepositoryUrl: '',
    providerRootDirectory: '',
    versionName: '',
    versionCode: 0,
    buildNumber: '',
    toolchain: {},
    specification: '',
    cpus: 0,
    memory: 0,
    timeout: 0,
    artifactId: '',
    releaseId: '',
    logsSize: 0,
    dispatchedAt: null,
    startedAt: null,
    endedAt: null,
    duration: 0,
    errorCode: '',
    errorMessage: '',
    ...overrides,
  }
}

export function buildsFixtures(): DistributionBuild[] {
  return [
    build({
      $id: BUILD_SHOP_ACTIVE,
      $createdAt: minutesFromNow(-6),
      $updatedAt: minutesFromNow(-1),
      appId: APP_SHOP,
      targetId: targetId(APP_SHOP, 'android'),
      platform: 'android',
      status: 'building',
      stage: 'build',
      ...commit(
        REPOSITORY_SHOP,
        'main',
        '9f1c2e7a4b3d5c6e8f0a1b2c3d4e5f60718293a4',
        'Add saved carts',
      ),
      providerRootDirectory: './',
      versionName: '1.5.0',
      versionCode: 18,
      buildNumber: '18',
      toolchain: { flutter: '3.27.1', java: '17' },
      specification: 's-2vcpu-4gb',
      cpus: 2,
      memory: 4096,
      timeout: 3600,
      logsSize: 9_600,
      dispatchedAt: minutesFromNow(-6),
      startedAt: minutesFromNow(-5),
    }),
    build({
      $id: BUILD_SHOP_READY,
      $createdAt: daysFromNow(-1),
      $updatedAt: daysFromNow(-1),
      appId: APP_SHOP,
      targetId: targetId(APP_SHOP, 'ios'),
      platform: 'ios',
      type: 'vcs',
      status: 'ready',
      stage: 'sign',
      ...commit(
        REPOSITORY_SHOP,
        'main',
        'a1b2c3d4e5f60718293a4b5c6d7e8f9012345678',
        'Release 1.4.0',
      ),
      providerRootDirectory: './',
      versionName: '1.4.0',
      buildNumber: '42',
      toolchain: { flutter: '3.27.1', xcode: '16.2' },
      specification: 's-4vcpu-8gb',
      cpus: 4,
      memory: 8192,
      timeout: 1800,
      artifactId: ARTIFACT_SHOP_IOS,
      releaseId: RELEASE_ACTIVE,
      logsSize: 182_344,
      dispatchedAt: daysFromNow(-1),
      startedAt: daysFromNow(-1),
      endedAt: daysFromNow(-1),
      duration: 612,
    }),
    build({
      $id: BUILD_FIELD_NOTES_FAILED,
      $createdAt: daysFromNow(-3),
      $updatedAt: daysFromNow(-3),
      appId: APP_FIELD_NOTES,
      targetId: targetId(APP_FIELD_NOTES, 'android'),
      platform: 'android',
      type: 'vcs',
      status: 'failed',
      stage: 'build',
      ...commit(
        REPOSITORY_FIELD_NOTES,
        'develop',
        '0f9e8d7c6b5a49382716f5e4d3c2b1a098765432',
        'Upgrade the Android Gradle plugin',
      ),
      providerRootDirectory: 'android',
      versionName: '2.4.0',
      versionCode: 31,
      buildNumber: '31',
      toolchain: { java: '17' },
      specification: 's-2vcpu-4gb',
      cpus: 2,
      memory: 4096,
      timeout: 3600,
      logsSize: 4_096,
      dispatchedAt: daysFromNow(-3),
      startedAt: daysFromNow(-3),
      endedAt: daysFromNow(-3),
      duration: 148,
      errorCode: 'build_failed',
      errorMessage:
        "Gradle task 'bundleProductionRelease' failed with exit code 1.",
    }),
  ]
}

const LOG_SEQUENCE = 1_763_640_000_000_000_000n
const LOG_SEQUENCE_STEP = 1n

const LOG_LINES = [
  '\u001b[36m$\u001b[0m flutter pub get',
  'Resolving dependencies...',
  '\u001b[32m+\u001b[0m http 1.2.2',
  'Running Gradle task \u001b[1mbundleProductionRelease\u001b[0m...',
  '\u001b[33mwarning:\u001b[0m [options] source value 8 is obsolete',
  '\u001b[32m✓\u001b[0m Built build/app/outputs/bundle/productionRelease',
]

export function logsFixtures(
  buildId: string,
  count: number,
): DistributionLog[] {
  return Array.from({ length: count }, (_, index) => ({
    $id: `${buildId.slice(-4)}log${String(index + 1).padStart(6, '0')}`,
    $createdAt: new Date(
      Date.parse(NOW) - (count - index) * 1000,
    ).toISOString(),
    buildId,
    sequence: (LOG_SEQUENCE + BigInt(index) * LOG_SEQUENCE_STEP).toString(),
    chunk: 0,
    stage: index < count / 5 ? 'source' : 'build',
    content: `\u001b[90m[${String(index + 1).padStart(3, '0')}]\u001b[0m ${LOG_LINES[index % LOG_LINES.length]}\n`,
  }))
}

function artifact(
  overrides: Partial<DistributionArtifact> &
    Pick<
      DistributionArtifact,
      '$id' | 'type' | 'source' | 'status' | 'name' | 'size'
    >,
): DistributionArtifact {
  const chunksTotal = Math.ceil(overrides.size / ARTIFACT_CHUNK_SIZE)
  return {
    $createdAt: NOW,
    $updatedAt: NOW,
    appId: APP_SHOP,
    targetId: '',
    platform: '',
    buildId: '',
    chunksTotal,
    chunksUploaded: chunksTotal,
    digest: '',
    identifier: '',
    versionName: '',
    versionCode: 0,
    buildNumber: '',
    signed: false,
    fingerprint: '',
    manifest: {},
    errorCode: '',
    errorMessage: '',
    expiresAt: daysFromNow(90),
    ...overrides,
  }
}

export function artifactsFixtures(): DistributionArtifact[] {
  return [
    artifact({
      $id: ARTIFACT_PROCESSING,
      $createdAt: minutesFromNow(-2),
      $updatedAt: minutesFromNow(-2),
      type: 'aab',
      source: 'upload',
      status: 'processing',
      name: 'app-release.aab',
      size: 22_020_096,
    }),
    artifact({
      $id: ARTIFACT_SHOP_IOS,
      $createdAt: daysFromNow(-1),
      $updatedAt: daysFromNow(-1),
      targetId: targetId(APP_SHOP, 'ios'),
      platform: 'ios',
      type: 'ipa',
      source: 'build',
      buildId: BUILD_SHOP_READY,
      status: 'ready',
      name: 'Runner.ipa',
      size: 48_234_496,
      digest:
        '5d41402abc4b2a76b9719d911017c592ae2f1b5c3d7e8f9a0b1c2d3e4f506172',
      identifier: 'com.acme.shop',
      versionName: '1.4.0',
      buildNumber: '42',
      signed: true,
      fingerprint:
        '3f2a9c1d8e7b6a5f4e3d2c1b0a9f8e7d6c5b4a3f2e1d0c9b8a7f6e5d4c3b2a19',
      manifest: { minimumOSVersion: '15.0', bundleVersion: '42' },
      expiresAt: daysFromNow(89),
    }),
    artifact({
      $id: ARTIFACT_INVALID,
      $createdAt: daysFromNow(-4),
      $updatedAt: daysFromNow(-4),
      platform: 'ios',
      type: 'ipa',
      source: 'upload',
      status: 'invalid',
      name: 'Unknown.ipa',
      size: 12_582_912,
      identifier: 'com.acme.unknown',
      versionName: '0.9.0',
      buildNumber: '7',
      errorCode: 'artifact_identifier_unknown',
      errorMessage:
        'No target of the app ships the bundle ID com.acme.unknown.',
      expiresAt: daysFromNow(86),
    }),
    artifact({
      $id: ARTIFACT_SHOP_ANDROID,
      $createdAt: daysFromNow(-14),
      $updatedAt: daysFromNow(-14),
      targetId: targetId(APP_SHOP, 'android'),
      platform: 'android',
      type: 'aab',
      source: 'upload',
      status: 'ready',
      name: 'app-production-release.aab',
      size: 31_457_280,
      digest:
        '7c222fb2927d828af22f592134e8932480637c0d4a8e9f1b2c3d4e5f60718293',
      identifier: 'com.acme.shop',
      versionName: '1.3.0',
      versionCode: 17,
      buildNumber: '17',
      signed: true,
      fingerprint:
        'c5b4a3f2e1d0c9b8a7f6e5d4c3b2a193f2a9c1d8e7b6a5f4e3d2c1b0a9f8e7d6',
      manifest: { minSdkVersion: 24, targetSdkVersion: 35 },
      expiresAt: daysFromNow(76),
    }),
  ]
}

function release(
  overrides: Partial<DistributionRelease> &
    Pick<DistributionRelease, '$id' | 'version' | 'status'>,
): DistributionRelease {
  return {
    $createdAt: NOW,
    $updatedAt: NOW,
    appId: APP_SHOP,
    notes: {},
    ...overrides,
  }
}

export function releasesFixtures(): DistributionRelease[] {
  return [
    release({
      $id: RELEASE_DRAFT,
      $createdAt: minutesFromNow(-90),
      $updatedAt: minutesFromNow(-90),
      version: '1.5.0',
      status: 'draft',
    }),
    release({
      $id: RELEASE_ACTIVE,
      $createdAt: daysFromNow(-1),
      $updatedAt: daysFromNow(-1),
      version: '1.4.0',
      notes: { 'en-US': 'Faster checkout and a redesigned cart.' },
      status: 'active',
    }),
    release({
      $id: RELEASE_PUBLISHED,
      $createdAt: daysFromNow(-14),
      $updatedAt: daysFromNow(-12),
      version: '1.3.0',
      notes: {
        'en-US': 'Track your orders.',
        'de-DE': 'Bestellungen verfolgen.',
      },
      status: 'published',
    }),
    release({
      $id: RELEASE_ATTENTION,
      $createdAt: daysFromNow(-30),
      $updatedAt: daysFromNow(-27),
      version: '1.2.0',
      notes: { 'en-US': 'Bug fixes.' },
      status: 'attention',
    }),
  ]
}

function submission(
  overrides: Partial<DistributionSubmission> &
    Pick<
      DistributionSubmission,
      | '$id'
      | 'releaseId'
      | 'targetId'
      | 'artifactId'
      | 'platform'
      | 'track'
      | 'status'
    >,
): DistributionSubmission {
  const platform: DistributionPlatform = overrides.platform
  return {
    $createdAt: NOW,
    $updatedAt: NOW,
    appId: APP_SHOP,
    provider: PROVIDERS[platform],
    rollout: 1,
    automatic: true,
    storeStatus: '',
    step: '',
    external: {},
    key: `${overrides.targetId}:${overrides.track}:${overrides.artifactId}`,
    attempt: 1,
    retryAt: null,
    submittedAt: null,
    reviewedAt: null,
    publishedAt: null,
    errorCode: '',
    errorMessage: '',
    ...overrides,
  }
}

export function submissionsFixtures(): DistributionSubmission[] {
  const ios = {
    targetId: targetId(APP_SHOP, 'ios'),
    artifactId: ARTIFACT_SHOP_IOS,
    platform: 'ios',
  } as const
  const android = {
    targetId: targetId(APP_SHOP, 'android'),
    artifactId: ARTIFACT_SHOP_ANDROID,
    platform: 'android',
  } as const
  return [
    submission({
      ...ios,
      $id: SUBMISSION_TESTFLIGHT,
      $createdAt: minutesFromNow(-20 * 60),
      $updatedAt: minutesFromNow(-18 * 60),
      releaseId: RELEASE_ACTIVE,
      track: 'testflight',
      status: 'published',
      storeStatus: 'IN_BETA_TESTING',
      step: 'settled',
      external: { buildId: 'a8c1e2f4-7b3d-4c5e-9f6a-0b1c2d3e4f50' },
      submittedAt: minutesFromNow(-20 * 60),
      publishedAt: minutesFromNow(-18 * 60),
    }),
    submission({
      ...ios,
      $id: SUBMISSION_APPROVED,
      $createdAt: minutesFromNow(-19 * 60),
      $updatedAt: minutesFromNow(-2 * 60),
      releaseId: RELEASE_ACTIVE,
      track: 'production',
      automatic: false,
      status: 'approved',
      storeStatus: 'PENDING_DEVELOPER_RELEASE',
      step: 'processed',
      submittedAt: minutesFromNow(-19 * 60),
      reviewedAt: minutesFromNow(-2 * 60),
    }),
    submission({
      ...android,
      $id: SUBMISSION_REVIEWING,
      $createdAt: minutesFromNow(-19 * 60),
      $updatedAt: minutesFromNow(-17 * 60),
      releaseId: RELEASE_ACTIVE,
      track: 'production',
      status: 'reviewing',
      storeStatus: 'inReview',
      step: 'processed',
      submittedAt: minutesFromNow(-19 * 60),
    }),
    submission({
      ...android,
      $id: SUBMISSION_PRODUCTION,
      $createdAt: daysFromNow(-13),
      $updatedAt: daysFromNow(-12),
      releaseId: RELEASE_PUBLISHED,
      track: 'production',
      rollout: 0.2,
      status: 'published',
      storeStatus: 'inProgress',
      step: 'released',
      submittedAt: daysFromNow(-13),
      reviewedAt: daysFromNow(-12),
      publishedAt: daysFromNow(-12),
    }),
    submission({
      ...android,
      $id: SUBMISSION_HALTED,
      $createdAt: daysFromNow(-29),
      $updatedAt: daysFromNow(-27),
      releaseId: RELEASE_ATTENTION,
      track: 'production',
      rollout: 0.5,
      status: 'halted',
      storeStatus: 'halted',
      step: 'released',
      submittedAt: daysFromNow(-29),
      reviewedAt: daysFromNow(-28),
      publishedAt: daysFromNow(-28),
    }),
    submission({
      ...ios,
      $id: SUBMISSION_REJECTED,
      $createdAt: daysFromNow(-29),
      $updatedAt: daysFromNow(-27),
      releaseId: RELEASE_ATTENTION,
      track: 'production',
      status: 'rejected',
      storeStatus: 'REJECTED',
      step: 'processed',
      submittedAt: daysFromNow(-29),
      reviewedAt: daysFromNow(-27),
      errorCode: 'store_rejected',
      errorMessage:
        'Guideline 2.1 - Performance - App Completeness: the app crashed on launch.',
    }),
  ]
}

export function checkFixture(
  issues: DistributionCheckIssue[] = [],
): DistributionCheck {
  return {
    ready: !issues.some((issue) => issue.severity === 'error'),
    issues,
  }
}

export type DistributionRepository = Models.ProviderRepositoryFramework

export function vcsFixtures(): {
  installations: Models.Installation[]
  repositories: DistributionRepository[]
  branches: string[]
  directories: string[]
} {
  const repository = (
    id: string,
    name: string,
    framework: string,
    isPrivate: boolean,
  ): DistributionRepository => ({
    id,
    name,
    organization: ORGANIZATION_LOGIN,
    provider: 'github',
    private: isPrivate,
    defaultBranch: 'main',
    providerInstallationId: '41000001',
    authorized: true,
    pushedAt: daysFromNow(-1),
    variables: [],
    framework,
  })
  return {
    installations: [
      {
        $id: INSTALLATION_ID,
        $createdAt: NOW,
        $updatedAt: NOW,
        provider: 'github',
        organization: ORGANIZATION_LOGIN,
        providerInstallationId: '41000001',
        organizationUrl: `https://github.com/${ORGANIZATION_LOGIN}`,
        personal: false,
        reviewNewRepositories: false,
      },
    ],
    repositories: [
      repository('1001', 'shop-app', 'flutter', true),
      repository('1002', 'field-notes', 'other', true),
      repository('1003', 'rewards-mobile', 'other', false),
    ],
    branches: ['main', 'develop', 'release/2.4'],
    directories: ['android', 'ios', 'lib', 'test'],
  }
}

export type DistributionSeed = {
  apps?: DistributionApp[]
  targets?: DistributionTarget[]
  variables?: Models.Variable[]
  connections?: DistributionConnection[]
  signingIdentities?: DistributionSigningIdentity[]
  installations?: Models.Installation[]
  repositories?: DistributionRepository[]
  branches?: string[]
  directories?: string[]
  downloads?: Record<string, DistributionSigningIdentityDownload>
  builds?: DistributionBuild[]
  logs?: DistributionLog[]
  artifacts?: DistributionArtifact[]
  releases?: DistributionRelease[]
  submissions?: DistributionSubmission[]
  checks?: Record<string, DistributionCheck>
}

export type DistributionRole = 'owner' | 'readonly'

export type DistributionMockOptions = {
  enabled?: boolean
  features?: Record<string, boolean>
  role?: DistributionRole
  empty?: boolean
  seed?: DistributionSeed
  language?: string
  scopes?: string[]
}

export type DistributionRequest = {
  method: string
  path: string
  body: Record<string, unknown>
  headers: Record<string, string>
}

export type DistributionStore = Required<DistributionSeed>

export type DistributionChangeKind =
  | 'builds'
  | 'artifacts'
  | 'releases'
  | 'submissions'

export type DistributionLogOptions = {
  stage?: string
  chunks?: number
}

export type DistributionMock = {
  store: DistributionStore
  requests: DistributionRequest[]
  realtime: DistributionRealtime
  change<K extends DistributionChangeKind>(
    kind: K,
    id: string,
    changes: Partial<DistributionStore[K][number]>,
  ): DistributionStore[K][number]
  appendLog(
    buildId: string,
    content: string,
    options?: DistributionLogOptions,
  ): DistributionLog[]
}

export const OWNER_SCOPES = [
  'projects.read',
  'distribution.read',
  'distribution.write',
  'distribution.credentials.read',
  'distribution.credentials.write',
  'distribution.submissions.read',
  'distribution.submissions.write',
]

const READONLY_SCOPES = [
  'projects.read',
  'distribution.read',
  'distribution.credentials.read',
  'distribution.submissions.read',
]

function initialStore(
  empty: boolean,
  seed: DistributionSeed,
): DistributionStore {
  const vcs = vcsFixtures()
  const apps = empty ? [] : appsFixtures()
  const defaults: DistributionStore = {
    apps,
    targets: empty
      ? []
      : [
          ...targetsFixtures(APP_SHOP),
          ...targetsFixtures(APP_REWARDS),
          ...targetsFixtures(APP_FIELD_NOTES, ['android']),
        ],
    variables: empty ? [] : variablesFixtures(APP_SHOP),
    connections: empty ? [] : connectionsFixtures(),
    signingIdentities: empty ? [] : signingFixtures(),
    downloads: {},
    builds: empty ? [] : buildsFixtures(),
    logs: empty
      ? []
      : [
          ...logsFixtures(BUILD_SHOP_ACTIVE, 150),
          ...logsFixtures(BUILD_FIELD_NOTES_FAILED, 12),
        ],
    artifacts: empty ? [] : artifactsFixtures(),
    releases: empty ? [] : releasesFixtures(),
    submissions: empty ? [] : submissionsFixtures(),
    checks: {},
    ...vcs,
  }
  return structuredClone({ ...defaults, ...seed })
}

type Query = { method: string; attribute?: string; values?: unknown[] }

function parseQueries(url: URL): Query[] {
  return [...url.searchParams]
    .filter(([key]) => key.startsWith('queries['))
    .map(([, query]) => JSON.parse(query) as Query)
}

function isDigits(value: string): boolean {
  return (
    value !== '' &&
    [...value].every((character) => character >= '0' && character <= '9')
  )
}

function compareValues(left: unknown, right: unknown): number {
  if (typeof left === 'number' && typeof right === 'number') {
    return left - right
  }
  const first = String(left ?? '')
  const second = String(right ?? '')
  if (isDigits(first) && isDigits(second) && first.length !== second.length) {
    return first.length - second.length
  }
  return first.localeCompare(second)
}

function paginate<T extends object>(
  items: T[],
  url: URL,
  searchable: (item: T) => string[],
): { total: number; rows: T[] } {
  const queries = parseQueries(url)
  const field = (item: T, attribute: string) =>
    (item as Record<string, unknown>)[attribute]
  let rows = items.filter((item) =>
    queries
      .filter((query) => query.method === 'equal' && query.attribute)
      .every((query) =>
        (query.values ?? []).includes(field(item, query.attribute!)),
      ),
  )
  const terms = [
    ...queries
      .filter((query) => query.method === 'search')
      .map((query) => String(query.values?.[0] ?? '')),
    url.searchParams.get('search') ?? '',
  ]
    .map((term) => term.trim().toLowerCase())
    .filter(Boolean)
  rows = rows.filter((item) =>
    terms.every((term) =>
      searchable(item).some((value) => value.toLowerCase().includes(term)),
    ),
  )
  const orders = queries.filter(
    (query) =>
      (query.method === 'orderAsc' || query.method === 'orderDesc') &&
      query.attribute,
  )
  if (orders.length) {
    rows = [...rows].sort((left, right) => {
      for (const order of orders) {
        const result = compareValues(
          field(left, order.attribute!),
          field(right, order.attribute!),
        )
        if (result !== 0) return order.method === 'orderAsc' ? result : -result
      }
      return 0
    })
  }
  const numeric = (method: string) => {
    const query = queries.find((candidate) => candidate.method === method)
    return query ? Number(query.values?.[0]) : undefined
  }
  const total = url.searchParams.get('total') === 'false' ? 0 : rows.length
  const cursor = queries.find((query) => query.method === 'cursorAfter')
    ?.values?.[0]
  if (cursor !== undefined) {
    if (!items.some((item) => field(item, '$id') === cursor)) {
      invalid(
        'general_cursor_not_found',
        `Document '${String(cursor)}' for the 'cursor' value not found.`,
      )
    }
    rows = rows.slice(
      rows.findIndex((item) => field(item, '$id') === cursor) + 1,
    )
  }
  const offset = numeric('offset') ?? 0
  const limit = numeric('limit') ?? 25
  return { total, rows: rows.slice(offset, offset + limit) }
}

function corsHeaders(route: Route): Record<string, string> {
  return {
    'access-control-allow-origin': route.request().headers()['origin'] ?? '*',
    'access-control-allow-credentials': 'true',
    'access-control-allow-headers': '*',
    'access-control-allow-methods': '*',
  }
}

function requestBody(route: Route): Record<string, unknown> {
  try {
    const body: unknown = route.request().postDataJSON()
    return body && typeof body === 'object'
      ? (body as Record<string, unknown>)
      : {}
  } catch {
    return {}
  }
}

function definedEntries(
  body: Record<string, unknown>,
  keys: readonly string[],
): Record<string, unknown> {
  return Object.fromEntries(
    keys
      .filter((key) => body[key] !== undefined)
      .map((key) => [key, body[key]]),
  )
}

function timestamp(): string {
  return new Date().toISOString()
}

function hex(length: number): string {
  return Array.from({ length }, () =>
    Math.floor(Math.random() * 16).toString(16),
  ).join('')
}

function fingerprint(): string {
  return hex(64)
}

function uniqueId(): string {
  return hex(20)
}

const APP_KEYS = [
  'name',
  'enabled',
  'framework',
  'installationId',
  'providerRepositoryId',
  'providerBranch',
  'providerRootDirectory',
  'providerBranches',
  'providerPaths',
  'providerTags',
  'providerSilentMode',
  'providerComments',
  'providerDeployments',
  'providerStatuses',
] as const

const TARGET_KEYS = [
  'identifier',
  'connectionId',
  'signingIdentityId',
  'track',
  'automatic',
  'versionStrategy',
  'installCommand',
  'buildCommand',
  'outputPath',
  'configuration',
  'specification',
  'timeout',
] as const

class Failure {
  constructor(
    readonly code: number,
    readonly type: string,
    readonly message: string,
  ) {}
}

function notFound(type: string, message: string): never {
  throw new Failure(404, type, message)
}

function conflict(type: string, message: string): never {
  throw new Failure(409, type, message)
}

function invalid(type: string, message: string): never {
  throw new Failure(400, type, message)
}

type Reply = {
  status: number
  body?: unknown
  file?: { name: string; content: Buffer }
}

function ok(body: unknown, status: number = 200): Reply {
  return { status, body }
}

const NO_CONTENT: Reply = { status: 204 }

type UploadedFile = { name: string; size: number }

type Publish = (
  params: [string, string][],
  action: string,
  payload: Record<string, unknown>,
) => void

type Flow = {
  headers: Record<string, string>
  file: UploadedFile | undefined
  uploads: Map<string, Set<number>>
  publish: Publish
  later: (callback: () => void) => void
}

function distributionRoutes(
  store: DistributionStore,
  method: string,
  segments: string[],
  url: URL,
  body: Record<string, unknown>,
  flow: Flow,
): Reply {
  const [resource, id, child, childId] = segments

  const findApp = (appId: string) =>
    store.apps.find((candidate) => candidate.$id === appId) ??
    notFound('distribution_app_not_found', 'App not found.')

  const findConnection = (connectionId: string) =>
    store.connections.find((candidate) => candidate.$id === connectionId) ??
    notFound('distribution_connection_not_found', 'Connection not found.')

  const findSigningIdentity = (signingIdentityId: string) =>
    store.signingIdentities.find(
      (candidate) => candidate.$id === signingIdentityId,
    ) ??
    notFound(
      'distribution_signing_identity_not_found',
      'Signing identity not found.',
    )

  const checkTargetReferences = (
    platform: DistributionPlatform,
    changes: Record<string, unknown>,
  ) => {
    const connectionId = changes.connectionId
    if (typeof connectionId === 'string' && connectionId !== '') {
      const connection = findConnection(connectionId)
      if (connection.provider !== PROVIDERS[platform]) {
        invalid(
          'distribution_connection_invalid',
          `A ${platform} target needs a ${PROVIDERS[platform]} connection.`,
        )
      }
    }
    const signingIdentityId = changes.signingIdentityId
    if (typeof signingIdentityId === 'string' && signingIdentityId !== '') {
      if (findSigningIdentity(signingIdentityId).platform !== platform) {
        invalid(
          'distribution_signing_identity_invalid',
          `A ${platform} target needs a ${platform} signing identity.`,
        )
      }
    }
  }

  const withRepository = (
    current: Pick<DistributionApp, 'installationId' | 'providerRepositoryId'>,
  ) =>
    current.installationId && current.providerRepositoryId
      ? `repository${current.providerRepositoryId}`
      : ''

  if (resource === 'apps' && !id) {
    if (method === 'GET') {
      const { total, rows } = paginate(store.apps, url, (item) => [
        item.$id,
        item.name,
      ])
      return ok({ total, apps: rows })
    }
    if (method === 'POST') {
      const appId = String(body.appId ?? '')
      if (store.apps.some((candidate) => candidate.$id === appId)) {
        conflict('distribution_app_already_exists', 'App already exists.')
      }
      const created = app({
        ...(definedEntries(body, APP_KEYS) as Partial<DistributionApp>),
        $id: appId,
        $createdAt: timestamp(),
        $updatedAt: timestamp(),
        name: String(body.name ?? ''),
        framework: body.framework as DistributionApp['framework'],
      })
      created.repositoryId = withRepository(created)
      store.apps.unshift(created)
      return ok(created, 201)
    }
  }

  if (resource === 'apps' && id && !child) {
    const current = findApp(id)
    if (method === 'GET') return ok(current)
    if (method === 'PATCH') {
      Object.assign(current, definedEntries(body, APP_KEYS), {
        $updatedAt: timestamp(),
      })
      current.repositoryId = withRepository(current)
      return ok(current)
    }
    if (method === 'DELETE') {
      store.apps = store.apps.filter((candidate) => candidate.$id !== id)
      store.targets = store.targets.filter((target) => target.appId !== id)
      store.variables = store.variables.filter(
        (variable) => variable.resourceId !== id,
      )
      const builds = new Set(
        store.builds
          .filter((item) => item.appId === id)
          .map((item) => item.$id),
      )
      store.builds = store.builds.filter((item) => item.appId !== id)
      store.logs = store.logs.filter((log) => !builds.has(log.buildId))
      store.artifacts = store.artifacts.filter((item) => item.appId !== id)
      store.releases = store.releases.filter((item) => item.appId !== id)
      store.submissions = store.submissions.filter((item) => item.appId !== id)
      return NO_CONTENT
    }
  }

  if (resource === 'apps' && id && child === 'targets') {
    findApp(id)
    const targets = store.targets.filter((target) => target.appId === id)
    if (!childId && method === 'GET') {
      const { total, rows } = paginate(targets, url, (item) => [
        item.identifier,
        item.platform,
      ])
      return ok({ total, targets: rows })
    }
    if (!childId && method === 'POST') {
      const platform = body.platform as DistributionPlatform
      if (targets.some((target) => target.platform === platform)) {
        conflict(
          'distribution_target_already_exists',
          `The app already has a ${platform} target.`,
        )
      }
      checkTargetReferences(platform, body)
      const created: DistributionTarget = {
        $id: String(body.targetId ?? ''),
        $createdAt: timestamp(),
        $updatedAt: timestamp(),
        appId: id,
        platform,
        identifier: '',
        connectionId: '',
        signingIdentityId: '',
        storeAppId: '',
        track: '',
        automatic: false,
        versionStrategy: 'automatic',
        versionCode: 0,
        installCommand: '',
        buildCommand: '',
        outputPath: '',
        configuration: {},
        specification: '',
        timeout: 0,
        ...(definedEntries(body, TARGET_KEYS) as Partial<DistributionTarget>),
      }
      store.targets.push(created)
      return ok(created, 201)
    }
    if (childId) {
      const current =
        targets.find((target) => target.$id === childId) ??
        notFound('distribution_target_not_found', 'Target not found.')
      if (method === 'GET') return ok(current)
      if (method === 'PATCH') {
        const changes = definedEntries(body, TARGET_KEYS)
        checkTargetReferences(current.platform, changes)
        Object.assign(current, changes, { $updatedAt: timestamp() })
        return ok(current)
      }
      if (method === 'DELETE') {
        store.targets = store.targets.filter((target) => target.$id !== childId)
        return NO_CONTENT
      }
    }
  }

  if (resource === 'apps' && id && child === 'variables') {
    findApp(id)
    const variables = store.variables.filter(
      (variable) => variable.resourceId === id,
    )
    if (!childId && method === 'GET') {
      const { total, rows } = paginate(variables, url, (item) => [item.key])
      return ok({ total, variables: rows })
    }
    if (!childId && method === 'POST') {
      const key = String(body.key ?? '')
      if (variables.some((variable) => variable.key === key)) {
        conflict('variable_already_exists', 'Variable already exists.')
      }
      const secret = body.secret === true
      const created: Models.Variable = {
        $id: String(body.variableId ?? ''),
        $createdAt: timestamp(),
        $updatedAt: timestamp(),
        key,
        description: '',
        value: secret ? '' : String(body.value ?? ''),
        secret,
        resourceType: 'distributionApps',
        resourceId: id,
      }
      store.variables.unshift(created)
      return ok(created, 201)
    }
    if (childId) {
      const current =
        variables.find((variable) => variable.$id === childId) ??
        notFound('variable_not_found', 'Variable not found.')
      if (method === 'GET') return ok(current)
      if (method === 'PATCH') {
        if (current.secret && body.secret === false) {
          invalid(
            'variable_cannot_unset_secret',
            'A secret variable cannot be made visible again.',
          )
        }
        const secret = body.secret === true || current.secret
        Object.assign(current, {
          key: String(body.key ?? current.key),
          secret,
          value: secret
            ? ''
            : body.value !== undefined
              ? String(body.value)
              : current.value,
          $updatedAt: timestamp(),
        })
        return ok(current)
      }
      if (method === 'DELETE') {
        store.variables = store.variables.filter(
          (variable) => variable.$id !== childId,
        )
        return NO_CONTENT
      }
    }
  }

  if (resource === 'connections') {
    const provider = id === 'apple' || id === 'google' ? id : undefined
    if (!id && method === 'GET') {
      const { total, rows } = paginate(store.connections, url, (item) => [
        item.$id,
        item.name,
        item.provider,
        item.account.clientEmail ?? '',
        item.account.teamName ?? '',
      ])
      return ok({ total, connections: rows })
    }
    if (provider && (method === 'POST' || method === 'PATCH')) {
      const credentials =
        provider === 'apple'
          ? appleAccount(body, method === 'POST')
          : googleAccount(body, method === 'POST')
      if (method === 'POST') {
        const connectionId = String(body.connectionId ?? '')
        if (store.connections.some((item) => item.$id === connectionId)) {
          conflict(
            'distribution_connection_already_exists',
            'Connection already exists.',
          )
        }
        const created: DistributionConnection = {
          $id: connectionId,
          $createdAt: timestamp(),
          $updatedAt: timestamp(),
          provider,
          name: String(body.name ?? ''),
          account: credentials ?? {},
          status: 'active',
          error: '',
          checkedAt: timestamp(),
        }
        store.connections.unshift(created)
        return ok(created, 201)
      }
      const current = findConnection(child ?? '')
      if (current.provider !== provider) {
        notFound('distribution_connection_not_found', 'Connection not found.')
      }
      Object.assign(current, {
        ...(body.name !== undefined ? { name: String(body.name) } : {}),
        ...(credentials
          ? {
              account: { ...current.account, ...credentials },
              status: 'active',
              error: '',
              checkedAt: timestamp(),
            }
          : {}),
        $updatedAt: timestamp(),
      })
      return ok(current)
    }
    if (id && !provider) {
      const current = findConnection(id)
      if (method === 'GET') return ok(current)
      if (method === 'DELETE') {
        if (store.targets.some((target) => target.connectionId === id)) {
          conflict(
            'distribution_connection_in_use',
            'A target still uses this connection.',
          )
        }
        store.connections = store.connections.filter(
          (connection) => connection.$id !== id,
        )
        return NO_CONTENT
      }
    }
  }

  if (resource === 'signing-identities') {
    if (!id && method === 'GET') {
      const { total, rows } = paginate(store.signingIdentities, url, (item) => [
        item.$id,
        item.name,
        ...item.identifiers,
      ])
      return ok({ total, signingIdentities: rows })
    }
    if ((id === 'ios' || id === 'android') && method === 'POST') {
      const created = createSigningIdentity(store, id, body)
      store.signingIdentities.unshift(created)
      return ok(created, 201)
    }
    if (id) {
      const current = findSigningIdentity(id)
      if (child === 'download' && method === 'GET') {
        return ok(
          store.downloads[current.$id] ?? signingDownloadFixture(current),
        )
      }
      if (!child && method === 'GET') return ok(current)
      if (!child && method === 'PATCH') {
        if (body.rotate === true && current.mode !== 'managed') {
          invalid(
            'distribution_signing_identity_invalid',
            'Only a managed signing identity can be rotated.',
          )
        }
        Object.assign(current, {
          ...(body.name !== undefined ? { name: String(body.name) } : {}),
          ...(body.rotate === true
            ? {
                fingerprint: fingerprint(),
                expiresAt: daysFromNow(365),
                status: 'active',
              }
            : {}),
          $updatedAt: timestamp(),
        })
        return ok(current)
      }
      if (!child && method === 'DELETE') {
        if (store.targets.some((target) => target.signingIdentityId === id)) {
          conflict(
            'distribution_signing_identity_in_use',
            'A target still uses this signing identity.',
          )
        }
        store.signingIdentities = store.signingIdentities.filter(
          (signingIdentity) => signingIdentity.$id !== id,
        )
        return NO_CONTENT
      }
    }
  }

  if (resource === 'apps' && id && child) {
    const handled = flowRoutes(
      store,
      flow,
      method,
      findApp(id),
      segments.slice(2),
      url,
      body,
    )
    if (handled) return handled
  }

  return notFound('general_route_not_found', 'Route not found.')
}

const ACTIVE_BUILD_STATUSES: readonly string[] = [
  'waiting',
  'processing',
  'building',
  'signing',
]

const SUBMISSION_TRANSITIONS: Record<
  DistributionSubmissionStatus,
  DistributionSubmissionStatus[]
> = {
  waiting: [
    'validating',
    'uploading',
    'processing',
    'pending',
    'reviewing',
    'approved',
    'published',
    'failed',
    'canceled',
  ],
  validating: [
    'uploading',
    'processing',
    'pending',
    'reviewing',
    'approved',
    'published',
    'failed',
    'canceled',
  ],
  uploading: [
    'processing',
    'pending',
    'reviewing',
    'approved',
    'published',
    'failed',
    'canceled',
  ],
  processing: [
    'pending',
    'reviewing',
    'approved',
    'published',
    'rejected',
    'failed',
    'canceled',
  ],
  pending: [
    'reviewing',
    'approved',
    'published',
    'rejected',
    'failed',
    'canceled',
  ],
  reviewing: ['approved', 'published', 'rejected', 'failed', 'canceled'],
  approved: ['published', 'failed', 'canceled'],
  published: ['halted', 'failed'],
  halted: ['published', 'failed'],
  rejected: [],
  failed: [],
  canceled: [],
}

const ACTIVE_SUBMISSION_STATUSES: readonly string[] = [
  'waiting',
  'validating',
  'uploading',
  'processing',
  'pending',
  'reviewing',
  'approved',
  'halted',
]

const REQUESTABLE_SUBMISSION_STATUSES: readonly string[] = [
  'canceled',
  'halted',
  'published',
]

const PUBLISHABLE_SUBMISSION_STATUSES: readonly string[] = [
  'approved',
  'halted',
]

const SUBMISSION_STATUS_DELAY = 100

function isSubmissionActive(
  submission: Pick<DistributionSubmission, 'status' | 'rollout'>,
): boolean {
  return (
    ACTIVE_SUBMISSION_STATUSES.includes(submission.status) ||
    (submission.status === 'published' && submission.rollout < 1)
  )
}

function canRequest(
  submission: Pick<
    DistributionSubmission,
    'status' | 'rollout' | 'provider' | 'step'
  >,
  requested: DistributionSubmissionStatus,
): boolean {
  if (
    !REQUESTABLE_SUBMISSION_STATUSES.includes(requested) ||
    !isSubmissionActive(submission)
  ) {
    return false
  }
  if (
    requested === 'canceled' &&
    submission.provider === 'google' &&
    (submission.step === 'released' || submission.step === 'settled')
  ) {
    return false
  }
  if (
    requested === 'published' &&
    !PUBLISHABLE_SUBMISSION_STATUSES.includes(submission.status)
  ) {
    return false
  }
  return SUBMISSION_TRANSITIONS[submission.status].includes(requested)
}

function releaseStatus(
  submissions: DistributionSubmission[],
): DistributionReleaseStatus {
  if (!submissions.length) return 'draft'
  if (
    submissions.some(
      (item) => item.status === 'rejected' || item.status === 'failed',
    )
  ) {
    return 'attention'
  }
  const targets = new Set(submissions.map((item) => item.targetId))
  const published = [...targets].every((target) =>
    submissions.some(
      (item) =>
        item.targetId === target &&
        item.track === 'production' &&
        item.status === 'published',
    ),
  )
  return published ? 'published' : 'active'
}

function publishRow(
  publish: Publish,
  kind: string,
  row: { $id: string; appId: string },
  action: string,
) {
  publish(
    [
      ['distributionApps', row.appId],
      [kind, row.$id],
    ],
    action,
    row,
  )
}

function refreshRelease(
  store: DistributionStore,
  publish: Publish,
  releaseId: string,
) {
  const current = store.releases.find((item) => item.$id === releaseId)
  if (!current) return
  Object.assign(current, {
    status: releaseStatus(
      store.submissions.filter((item) => item.releaseId === releaseId),
    ),
    $updatedAt: timestamp(),
  })
  publishRow(publish, 'releases', current, 'update')
}

function contentRange(
  header: string | undefined,
): { start: number; end: number; size: number } | undefined {
  if (header === undefined) return undefined
  const prefix = 'bytes '
  const [range = '', size = ''] = header.startsWith(prefix)
    ? header.slice(prefix.length).split('/')
    : []
  const [start = '', end = ''] = range.split('-')
  const values = [start, end, size]
  if (values.some((value) => !isDigits(value))) {
    invalid('storage_invalid_content_range', 'The content range is invalid.')
  }
  return { start: Number(start), end: Number(end), size: Number(size) }
}

function uploadedFile(request: Request): UploadedFile | undefined {
  const type = request.headers()['content-type'] ?? ''
  const marker = 'boundary='
  const data = request.postDataBuffer()
  if (!type.startsWith('multipart/form-data') || !data) return undefined
  const boundary = type
    .slice(type.indexOf(marker) + marker.length)
    .split(';')[0]
    .trim()
    .replaceAll('"', '')
  const nameMarker = 'filename="'
  const nameStart = data.indexOf(nameMarker)
  if (nameStart < 0) return undefined
  const nameEnd = data.indexOf('"', nameStart + nameMarker.length)
  const contentStart = data.indexOf('\r\n\r\n', nameEnd) + 4
  const contentEnd = data.indexOf(`\r\n--${boundary}`, contentStart)
  return {
    name: data.subarray(nameStart + nameMarker.length, nameEnd).toString(),
    size: contentEnd - contentStart,
  }
}

function createBuild(
  store: DistributionStore,
  app: DistributionApp,
  body: Record<string, unknown>,
): DistributionBuild {
  if (!app.installationId || !app.providerRepositoryId) {
    invalid(
      'distribution_repository_missing',
      'Connect a repository to the app before you start a build.',
    )
  }
  const target =
    store.targets.find(
      (item) => item.appId === app.$id && item.$id === body.targetId,
    ) ?? notFound('distribution_target_not_found', 'Target not found.')
  const type = String(body.type ?? 'branch')
  if (!['branch', 'commit', 'tag'].includes(type)) {
    invalid(
      'general_argument_invalid',
      'Invalid `type` param: Value must be one of (branch, commit, tag)',
    )
  }
  if (target.versionStrategy === 'manual' && body.versionCode === undefined) {
    invalid(
      'distribution_version_invalid',
      'The target sets version codes manually. Pass a version code.',
    )
  }
  const releaseId = String(body.releaseId ?? '')
  if (
    releaseId &&
    !store.releases.some(
      (item) => item.appId === app.$id && item.$id === releaseId,
    )
  ) {
    notFound('distribution_release_not_found', 'Release not found.')
  }
  const reference = String(body.reference || app.providerBranch)
  const repository =
    store.repositories.find((item) => item.id === app.providerRepositoryId)
      ?.name ?? ''
  return build({
    $id: uniqueId(),
    $createdAt: timestamp(),
    $updatedAt: timestamp(),
    appId: app.$id,
    targetId: target.$id,
    platform: target.platform,
    status: 'waiting',
    ...commit(
      repository,
      type === 'branch' ? reference : '',
      type === 'commit' ? reference : hex(40),
      '',
    ),
    providerTag: type === 'tag' ? reference : '',
    providerRootDirectory: app.providerRootDirectory,
    versionName: String(body.versionName ?? ''),
    versionCode: Number(body.versionCode ?? 0),
    specification: target.specification,
    timeout: target.timeout,
    releaseId,
  })
}

function uploadArtifact(
  store: DistributionStore,
  flow: Flow,
  app: DistributionApp,
): Reply {
  const file = flow.file ?? invalid('storage_file_empty', 'No file sent')
  const extension = file.name
    .slice(file.name.lastIndexOf('.') + 1)
    .toLowerCase()
  if (
    !file.name.includes('.') ||
    (extension !== 'ipa' && extension !== 'aab')
  ) {
    invalid(
      'storage_file_type_unsupported',
      'Only ipa and aab files can be uploaded.',
    )
  }
  const range = contentRange(flow.headers['content-range']) ?? {
    start: 0,
    end: file.size - 1,
    size: file.size,
  }
  if (range.start > range.end || range.end >= range.size) {
    invalid('storage_invalid_content_range', 'The content range is invalid.')
  }
  const artifactId = flow.headers['x-appwrite-id'] || uniqueId()
  const existing = store.artifacts.find((item) => item.$id === artifactId)
  if (
    existing &&
    (existing.appId !== app.$id || existing.source !== 'upload')
  ) {
    notFound('distribution_artifact_not_found', 'Artifact not found.')
  }
  if (
    existing &&
    (existing.type !== extension || existing.size !== range.size)
  ) {
    invalid(
      'storage_invalid_content_range',
      'Every chunk must carry the file of the first chunk.',
    )
  }
  const current =
    existing ??
    artifact({
      $id: artifactId,
      $createdAt: timestamp(),
      $updatedAt: timestamp(),
      appId: app.$id,
      type: extension === 'ipa' ? 'ipa' : 'aab',
      source: 'upload',
      status: 'uploading',
      name: file.name,
      size: range.size,
      chunksUploaded: 0,
    })
  if (!existing) store.artifacts.unshift(current)
  if (current.chunksUploaded >= current.chunksTotal) return ok(current, 202)
  const chunks = flow.uploads.get(artifactId) ?? new Set<number>()
  chunks.add(Math.floor(range.start / ARTIFACT_CHUNK_SIZE))
  flow.uploads.set(artifactId, chunks)
  Object.assign(current, {
    chunksUploaded: chunks.size,
    $updatedAt: timestamp(),
  })
  if (current.chunksUploaded === current.chunksTotal) {
    current.status = 'processing'
    publishRow(flow.publish, 'artifacts', current, 'create')
  }
  return ok(current, 202)
}

function releaseVersion(value: unknown): string {
  const version = String(value ?? '')
  if (version.length < 1 || version.length > 64) {
    invalid(
      'general_argument_invalid',
      'Invalid `version` param: Value must be a valid string and at least 1 chars and no longer than 64 chars',
    )
  }
  return version
}

function releaseNotes(value: unknown): Record<string, string> {
  if (
    !value ||
    typeof value !== 'object' ||
    Array.isArray(value) ||
    Object.entries(value).some(
      ([locale, text]) => locale === '' || typeof text !== 'string',
    )
  ) {
    invalid(
      'general_argument_invalid',
      'Notes must be an object of locale to text.',
    )
  }
  return value as Record<string, string>
}

function createSubmission(
  store: DistributionStore,
  app: DistributionApp,
  body: Record<string, unknown>,
): { submission: DistributionSubmission; created: boolean } {
  const owned = <T extends { $id: string; appId: string }>(
    rows: T[],
    id: unknown,
  ) => rows.find((item) => item.appId === app.$id && item.$id === id)
  const parent =
    owned(store.releases, body.releaseId) ??
    notFound('distribution_release_not_found', 'Release not found.')
  const target =
    owned(store.targets, body.targetId) ??
    notFound('distribution_target_not_found', 'Target not found.')
  const selected =
    owned(store.artifacts, body.artifactId) ??
    notFound('distribution_artifact_not_found', 'Artifact not found.')
  if (selected.status !== 'ready') {
    conflict(
      'distribution_artifact_not_ready',
      `Artifact '${selected.$id}' is '${selected.status}'. Only a ready artifact can be submitted.`,
    )
  }
  if (
    selected.platform !== target.platform ||
    selected.identifier !== target.identifier
  ) {
    invalid(
      'distribution_artifact_invalid',
      `Artifact '${selected.$id}' was built for '${selected.identifier}' on ${selected.platform}, but target '${target.$id}' ships '${target.identifier}' on ${target.platform}.`,
    )
  }
  const provider = PROVIDERS[target.platform]
  if (!target.connectionId) {
    invalid(
      'distribution_connection_invalid',
      `Target '${target.$id}' has no store connection. Attach one with updateTarget before submitting.`,
    )
  }
  const connection =
    store.connections.find((item) => item.$id === target.connectionId) ??
    notFound('distribution_connection_not_found', 'Connection not found.')
  if (connection.provider !== provider) {
    invalid(
      'distribution_connection_invalid',
      `A '${target.platform}' target needs a '${provider}' connection.`,
    )
  }
  const track = String(body.track || target.track)
  if (!TRACKS[provider].includes(track)) {
    invalid(
      'distribution_track_invalid',
      `Track '${track}' is not a valid track for ${target.platform}. Use one of: ${TRACKS[provider].join(', ')}.`,
    )
  }
  const key = String(body.key ?? '')
  const existing = key
    ? store.submissions.find(
        (item) => item.appId === app.$id && item.key === key,
      )
    : undefined
  if (existing) return { submission: existing, created: false }
  const rollout = body.rollout === undefined ? 1 : Number(body.rollout)
  if (!(rollout > 0 && rollout <= 1)) {
    invalid(
      'general_argument_invalid',
      'Rollout must be greater than 0 and at most 1.',
    )
  }
  const version =
    target.platform === 'ios'
      ? selected.buildNumber
      : String(selected.versionCode || '')
  return {
    created: true,
    submission: submission({
      $id: uniqueId(),
      $createdAt: timestamp(),
      $updatedAt: timestamp(),
      appId: app.$id,
      releaseId: parent.$id,
      targetId: target.$id,
      artifactId: selected.$id,
      platform: target.platform,
      track,
      rollout,
      automatic: body.automatic !== false,
      status: 'waiting',
      key: key || `${target.$id}:${track}:${version || selected.$id}`,
    }),
  }
}

function targetIssues(
  store: DistributionStore,
  target: DistributionTarget,
): DistributionCheckIssue[] {
  const issue = (
    code: string,
    field: string,
    message: string,
    remediation: string,
  ): DistributionCheckIssue => ({
    code,
    severity: 'error',
    platform: target.platform,
    field,
    message,
    remediation,
    manualActionRequired: false,
  })
  const issues: DistributionCheckIssue[] = []
  const connection = store.connections.find(
    (item) => item.$id === target.connectionId,
  )
  if (!connection) {
    issues.push(
      issue(
        'connection_missing',
        'connectionId',
        'The target has no store connection.',
        'Connect an App Store Connect or Google Play account and set it as the connection of the target.',
      ),
    )
  } else if (connection.status === 'invalid') {
    issues.push(
      issue(
        'connection_unauthorized',
        'connectionId',
        `The store rejected the credentials of the connection. ${connection.error}`.trim(),
        'Update the connection with a valid key.',
      ),
    )
  }
  if (
    !store.signingIdentities.some(
      (item) => item.$id === target.signingIdentityId,
    )
  ) {
    issues.push(
      issue(
        'signing_missing',
        'signingIdentityId',
        'The target has no signing identity.',
        'Import a certificate or keystore, or generate a managed identity, and set it as the signing identity of the target.',
      ),
    )
  }
  return issues
}

function flowRoutes(
  store: DistributionStore,
  flow: Flow,
  method: string,
  app: DistributionApp,
  [child, childId, grandchild]: string[],
  url: URL,
  body: Record<string, unknown>,
): Reply | undefined {
  if (child === 'builds') {
    const builds = store.builds.filter((item) => item.appId === app.$id)
    if (!childId && method === 'GET') {
      const { total, rows } = paginate(builds, url, (item) => [
        item.$id,
        item.versionName,
        item.providerBranch,
        item.providerTag,
        item.providerCommitHash,
      ])
      return ok({ total, builds: rows })
    }
    if (!childId && method === 'POST') {
      const created = createBuild(store, app, body)
      store.builds.unshift(created)
      publishRow(flow.publish, 'builds', created, 'create')
      return ok(created, 201)
    }
    if (!childId) return undefined
    const current =
      builds.find((item) => item.$id === childId) ??
      notFound('distribution_build_not_found', 'Build not found.')
    if (!grandchild && method === 'GET') return ok(current)
    if (!grandchild && method === 'DELETE') {
      store.builds = store.builds.filter((item) => item.$id !== childId)
      store.logs = store.logs.filter((log) => log.buildId !== childId)
      publishRow(flow.publish, 'builds', current, 'delete')
      return NO_CONTENT
    }
    if (grandchild === 'status' && method === 'PATCH') {
      if (body.status !== 'canceled') {
        invalid(
          'general_argument_invalid',
          'Invalid `status` param: Value must be one of (canceled)',
        )
      }
      if (!ACTIVE_BUILD_STATUSES.includes(current.status)) {
        conflict(
          'distribution_build_transition_invalid',
          `Build '${current.$id}' cannot change from '${current.status}' to 'canceled'.`,
        )
      }
      Object.assign(current, {
        status: 'canceled',
        endedAt: timestamp(),
        $updatedAt: timestamp(),
      })
      publishRow(flow.publish, 'builds', current, 'update')
      return ok(current)
    }
    if (grandchild === 'logs' && method === 'GET') {
      const logs = store.logs.filter((log) => log.buildId === childId)
      const { total, rows } = paginate(logs, url, (item) => [item.content])
      return ok({ total, logs: rows })
    }
    return undefined
  }

  if (child === 'artifacts') {
    const artifacts = store.artifacts.filter((item) => item.appId === app.$id)
    if (!childId && method === 'GET') {
      const { total, rows } = paginate(artifacts, url, (item) => [
        item.$id,
        item.name,
        item.identifier,
        item.versionName,
      ])
      return ok({ total, artifacts: rows })
    }
    if (!childId && method === 'POST') return uploadArtifact(store, flow, app)
    if (!childId) return undefined
    const current =
      artifacts.find((item) => item.$id === childId) ??
      notFound('distribution_artifact_not_found', 'Artifact not found.')
    if (!grandchild && method === 'GET') return ok(current)
    if (!grandchild && method === 'DELETE') {
      store.artifacts = store.artifacts.filter((item) => item.$id !== childId)
      flow.uploads.delete(childId)
      publishRow(flow.publish, 'artifacts', current, 'delete')
      return NO_CONTENT
    }
    if (grandchild === 'download' && method === 'GET') {
      if (current.status === 'uploading') {
        conflict(
          'distribution_artifact_not_ready',
          'The artifact is still uploading.',
        )
      }
      return {
        status: 200,
        file: { name: current.name, content: Buffer.from(`${current.name}\n`) },
      }
    }
    return undefined
  }

  if (child === 'releases') {
    const releases = store.releases.filter((item) => item.appId === app.$id)
    if (!childId && method === 'GET') {
      const { total, rows } = paginate(releases, url, (item) => [
        item.$id,
        item.version,
      ])
      return ok({ total, releases: rows })
    }
    if (!childId && method === 'POST') {
      const requested = String(body.releaseId ?? '')
      const releaseId = requested === 'unique()' ? uniqueId() : requested
      if (!releaseId) {
        invalid(
          'general_argument_invalid',
          'Param "releaseId" is not optional.',
        )
      }
      const version = releaseVersion(body.version)
      if (store.releases.some((item) => item.$id === releaseId)) {
        conflict(
          'distribution_release_already_exists',
          'Release already exists.',
        )
      }
      const created = release({
        $id: releaseId,
        $createdAt: timestamp(),
        $updatedAt: timestamp(),
        appId: app.$id,
        version,
        notes: releaseNotes(body.notes ?? {}),
        status: 'draft',
      })
      store.releases.unshift(created)
      publishRow(flow.publish, 'releases', created, 'create')
      return ok(created, 201)
    }
    if (!childId || grandchild) return undefined
    const current =
      releases.find((item) => item.$id === childId) ??
      notFound('distribution_release_not_found', 'Release not found.')
    if (method === 'GET') return ok(current)
    if (method === 'PATCH') {
      if (body.version == null && body.notes == null) {
        invalid(
          'general_argument_invalid',
          'At least one of version or notes is required.',
        )
      }
      Object.assign(
        current,
        body.version == null ? {} : { version: releaseVersion(body.version) },
        body.notes == null ? {} : { notes: releaseNotes(body.notes) },
        { $updatedAt: timestamp() },
      )
      publishRow(flow.publish, 'releases', current, 'update')
      return ok(current)
    }
    if (method === 'DELETE') {
      if (
        store.submissions.some(
          (item) => item.releaseId === current.$id && isSubmissionActive(item),
        )
      ) {
        conflict(
          'distribution_release_in_use',
          'The release has a submission in progress. Cancel it or wait for it to finish first.',
        )
      }
      store.releases = store.releases.filter((item) => item.$id !== childId)
      publishRow(flow.publish, 'releases', current, 'delete')
      return NO_CONTENT
    }
    return undefined
  }

  if (child === 'submissions') {
    const submissions = store.submissions.filter(
      (item) => item.appId === app.$id,
    )
    if (!childId && method === 'GET') {
      const { total, rows } = paginate(submissions, url, (item) => [
        item.$id,
        item.track,
        item.status,
      ])
      return ok({ total, submissions: rows })
    }
    if (!childId && method === 'POST') {
      const { submission: result, created } = createSubmission(store, app, body)
      if (!created) return ok(result)
      store.submissions.unshift(result)
      publishRow(flow.publish, 'submissions', result, 'create')
      refreshRelease(store, flow.publish, result.releaseId)
      return ok(result, 201)
    }
    if (!childId) return undefined
    const current =
      submissions.find((item) => item.$id === childId) ??
      notFound('distribution_submission_not_found', 'Submission not found.')
    if (!grandchild && method === 'GET') return ok(current)
    if (!grandchild && method === 'PATCH') {
      if (body.automatic === undefined && body.rollout === undefined) {
        invalid(
          'general_argument_invalid',
          'At least one parameter to update is required.',
        )
      }
      if (
        body.automatic !== undefined &&
        (!isSubmissionActive(current) ||
          current.step === 'released' ||
          current.step === 'settled')
      ) {
        conflict(
          'distribution_submission_transition_invalid',
          `Submission '${current.$id}' was already released to the store, so automatic can no longer change.`,
        )
      }
      const rollout =
        body.rollout === undefined ? undefined : Number(body.rollout)
      if (rollout !== undefined) {
        if (current.provider !== 'google') {
          invalid(
            'general_argument_invalid',
            'Only Google Play submissions have a staged rollout.',
          )
        }
        if (
          !(
            (current.status === 'published' && current.rollout < 1) ||
            current.status === 'halted'
          )
        ) {
          conflict(
            'distribution_submission_transition_invalid',
            `Submission '${current.$id}' has no staged rollout to change.`,
          )
        }
        if (!(rollout > 0 && rollout <= 1)) {
          invalid(
            'general_argument_invalid',
            'Rollout must be greater than 0 and at most 1.',
          )
        }
      }
      Object.assign(
        current,
        definedEntries({ automatic: body.automatic, rollout }, [
          'automatic',
          'rollout',
        ]),
        { $updatedAt: timestamp() },
      )
      publishRow(flow.publish, 'submissions', current, 'update')
      return ok(current)
    }
    if (grandchild === 'status' && method === 'PATCH') {
      const requested = String(body.status ?? '')
      if (!(requested in SUBMISSION_TRANSITIONS)) {
        invalid('general_argument_invalid', 'Invalid `status` param.')
      }
      const status = requested as DistributionSubmissionStatus
      if (!canRequest(current, status)) {
        conflict(
          'distribution_submission_transition_invalid',
          `Submission '${current.$id}' cannot change from '${current.status}' to '${status}'.`,
        )
      }
      const unchanged = structuredClone(current)
      publishRow(flow.publish, 'submissions', unchanged, 'update')
      flow.later(() => {
        Object.assign(current, {
          status,
          $updatedAt: timestamp(),
          ...(status === 'published'
            ? {
                publishedAt: current.publishedAt ?? timestamp(),
                step: 'released',
              }
            : {}),
        })
        publishRow(flow.publish, 'submissions', current, 'update')
        refreshRelease(store, flow.publish, current.releaseId)
      })
      return ok(unchanged)
    }
    return undefined
  }

  if (child === 'checks' && !childId && method === 'POST') {
    const target =
      store.targets.find(
        (item) => item.appId === app.$id && item.$id === body.targetId,
      ) ?? notFound('distribution_target_not_found', 'Target not found.')
    const track = String(
      body.track || target.track || DEFAULT_TRACKS[target.platform],
    )
    return ok(
      store.checks[`${target.$id}:${track}`] ??
        checkFixture(targetIssues(store, target)),
    )
  }

  return undefined
}

function appleAccount(
  request: Record<string, unknown>,
  required: boolean,
): DistributionConnection['account'] | undefined {
  const changed = ['keyId', 'issuerId', 'privateKey'].some(
    (key) => request[key] !== undefined,
  )
  if (!required && !changed) return undefined
  const keyId = String(request.keyId ?? '')
  const issuerId = String(request.issuerId ?? '')
  const privateKey = String(request.privateKey ?? '')
  if (!keyId || !issuerId || !privateKey.includes('PRIVATE KEY')) {
    invalid(
      'distribution_connection_invalid',
      'App Store Connect rejected the key. Use a team key with its issuer ID and the .p8 file.',
    )
  }
  return { keyId, issuerId }
}

function googleAccount(
  request: Record<string, unknown>,
  required: boolean,
): DistributionConnection['account'] | undefined {
  if (!required && request.serviceAccount === undefined) return undefined
  const serviceAccount = (request.serviceAccount ??
    {}) as DistributionGoogleServiceAccount
  if (!serviceAccount.client_email || !serviceAccount.private_key) {
    invalid(
      'distribution_connection_invalid',
      'Google Play rejected the service account. Paste the full JSON key file.',
    )
  }
  return {
    clientEmail: serviceAccount.client_email,
    projectId: serviceAccount.project_id ?? '',
  }
}

function createSigningIdentity(
  store: DistributionStore,
  platform: DistributionPlatform,
  body: Record<string, unknown>,
): DistributionSigningIdentity {
  const signingIdentityId = String(body.signingIdentityId ?? '')
  if (store.signingIdentities.some((item) => item.$id === signingIdentityId)) {
    conflict(
      'distribution_signing_identity_already_exists',
      'Signing identity already exists.',
    )
  }
  const base = {
    $id: signingIdentityId,
    $createdAt: timestamp(),
    $updatedAt: timestamp(),
    platform,
    name: String(body.name ?? ''),
    fingerprint: fingerprint(),
    status: 'active' as const,
  }
  const identifiers = Array.isArray(body.identifiers)
    ? body.identifiers.map(String)
    : []
  if (platform === 'ios') {
    const connectionId = String(body.connectionId ?? '')
    if (connectionId) {
      const connection = store.connections.find(
        (item) => item.$id === connectionId,
      )
      if (connection?.provider !== 'apple' || identifiers.length === 0) {
        invalid(
          'distribution_signing_identity_invalid',
          'Managed signing needs an App Store Connect connection and at least one bundle ID.',
        )
      }
      return {
        ...base,
        mode: 'managed',
        teamId: connection.account.teamId ?? APPLE_TEAM_ID,
        identifiers,
        connectionId,
        externalId: 'R7K2M9P4Q9',
        expiresAt: daysFromNow(365),
      }
    }
    const profiles = Array.isArray(body.profiles) ? body.profiles : []
    if (!body.certificate || body.password === undefined || !profiles.length) {
      invalid(
        'distribution_signing_identity_invalid',
        'Upload the .p12 certificate, its password and at least one provisioning profile.',
      )
    }
    return {
      ...base,
      mode: 'imported',
      teamId: APPLE_TEAM_ID,
      identifiers: identifiers.length ? identifiers : ['com.acme.imported'],
      connectionId: '',
      externalId: '',
      expiresAt: daysFromNow(365),
    }
  }
  const imported = Boolean(body.keystore)
  if (imported && (!body.storePassword || !body.keyAlias)) {
    invalid(
      'distribution_signing_identity_invalid',
      'Upload the keystore with its password and key alias.',
    )
  }
  return {
    ...base,
    mode: imported ? 'imported' : 'managed',
    teamId: '',
    identifiers: [],
    connectionId: '',
    externalId: '',
    expiresAt: daysFromNow(imported ? 25 * 365 : 30 * 365),
  }
}

function vcsRoutes(
  store: DistributionStore,
  method: string,
  segments: string[],
  url: URL,
  body: Record<string, unknown>,
): Reply | undefined {
  if (segments[0] === 'installations') {
    const [, installationId, child] = segments
    if (!installationId && method === 'GET') {
      return ok({
        total: store.installations.length,
        installations: store.installations,
      })
    }
    const installation =
      store.installations.find((item) => item.$id === installationId) ??
      notFound('installation_not_found', 'Installation not found.')
    if (!child && method === 'GET') return ok(installation)
    if (!child && method === 'DELETE') {
      store.installations = store.installations.filter(
        (item) => item.$id !== installationId,
      )
      return NO_CONTENT
    }
    if (child === 'namespaces' && method === 'GET') {
      return ok({
        total: 1,
        namespaces: [
          {
            $id: installation.organization,
            name: installation.organization,
            path: installation.organization,
            type: 'organization',
            avatarUrl: '',
          },
        ] satisfies Models.VcsNamespace[],
      })
    }
    return undefined
  }

  if (segments[0] !== 'github' || segments[1] !== 'installations') {
    return undefined
  }
  const [, , installationId, child, repositoryId, grandchild] = segments
  if (!store.installations.some((item) => item.$id === installationId)) {
    notFound('installation_not_found', 'Installation not found.')
  }

  if (child === 'detections' && method === 'POST') {
    const repository = store.repositories.find(
      (item) => item.id === body.providerRepositoryId,
    )
    return ok({
      type: 'framework',
      framework: repository?.framework ?? 'other',
      installCommand: '',
      buildCommand: '',
      outputDirectory: '',
      variables: [],
    })
  }

  if (child !== 'providerRepositories') return undefined

  if (!repositoryId && method === 'GET') {
    const type = url.searchParams.get('type') ?? 'framework'
    const { total, rows } = paginate(store.repositories, url, (item) => [
      item.name,
    ])
    return ok(
      type === 'runtime'
        ? {
            total,
            type,
            runtimeProviderRepositories: rows.map((repository) => ({
              ...repository,
              runtime: 'node-22',
            })),
          }
        : { total, type, frameworkProviderRepositories: rows },
    )
  }
  if (!repositoryId && method === 'POST') {
    const created: DistributionRepository = {
      id: String(2000 + store.repositories.length),
      name: String(body.name ?? 'new-repository'),
      organization: ORGANIZATION_LOGIN,
      provider: 'github',
      private: body.private !== false,
      defaultBranch: 'main',
      providerInstallationId: '41000001',
      authorized: true,
      pushedAt: timestamp(),
      variables: [],
      framework: 'other',
    }
    store.repositories.unshift(created)
    return ok(created, 201)
  }

  const repository =
    store.repositories.find((item) => item.id === repositoryId) ??
    notFound('repository_not_found', 'Repository not found.')
  if (!grandchild && method === 'GET') return ok(repository)
  if (grandchild === 'branches' && method === 'GET') {
    const branches = store.branches.map((name) => ({ name }))
    const { total, rows } = paginate(branches, url, (item) => [item.name])
    return ok({ total, branches: rows })
  }
  if (grandchild === 'contents' && method === 'GET') {
    const directory = url.searchParams.get('providerRootDirectory') ?? ''
    const atRoot = ['', '.', './', '/'].includes(directory)
    const contents: Models.VcsContent[] = atRoot
      ? [
          ...store.directories.map((name) => ({
            name,
            isDirectory: true,
            size: 0,
          })),
          { name: 'README.md', isDirectory: false, size: 1024 },
        ]
      : []
    return ok({ total: contents.length, contents })
  }
  return undefined
}

export async function mockDistributionApi(
  page: Page,
  {
    enabled = true,
    features = { distribution: true },
    role = 'owner',
    empty = false,
    seed = {},
    language,
    scopes,
  }: DistributionMockOptions = {},
): Promise<DistributionMock> {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const store = initialStore(empty, seed)
  const requests: DistributionRequest[] = []
  const uploads = new Map<string, Set<number>>()
  const realtime = await mockDistributionRealtime(page, {
    user: ACCOUNT,
    channels: ['console', `projects.${PROJECT.$id}`],
  })
  const publish: Publish = (params, action, payload) =>
    realtime.emit(distributionEvents(params, action), payload)
  const timers = new Set<ReturnType<typeof setTimeout>>()
  page.once('close', () => timers.forEach((timer) => clearTimeout(timer)))
  const later = (callback: () => void) => {
    const timer = setTimeout(() => {
      timers.delete(timer)
      callback()
    }, SUBMISSION_STATUS_DELAY)
    timers.add(timer)
  }

  const change = <K extends DistributionChangeKind>(
    kind: K,
    id: string,
    changes: Partial<DistributionStore[K][number]>,
  ): DistributionStore[K][number] => {
    const rows: { $id: string; appId: string }[] = store[kind]
    const row = rows.find((item) => item.$id === id)
    if (!row) throw new Error(`No ${kind} row has the ID ${id}.`)
    Object.assign(row, { $updatedAt: timestamp() }, changes)
    publishRow(publish, kind, row, 'update')
    if (kind === 'submissions') {
      refreshRelease(store, publish, (row as DistributionSubmission).releaseId)
    }
    return row as DistributionStore[K][number]
  }

  const appendLog = (
    buildId: string,
    content: string,
    { stage, chunks = 1 }: DistributionLogOptions = {},
  ): DistributionLog[] => {
    const current = store.builds.find((item) => item.$id === buildId)
    if (!current) throw new Error(`No build has the ID ${buildId}.`)
    const count = Math.max(1, Math.floor(chunks))
    const latest = store.logs
      .filter((log) => log.buildId === buildId)
      .reduce(
        (highest, log) =>
          BigInt(log.sequence) > highest ? BigInt(log.sequence) : highest,
        LOG_SEQUENCE,
      )
    const sequence = (latest + LOG_SEQUENCE_STEP).toString()
    const size = Math.ceil(content.length / count)
    const rows: DistributionLog[] = Array.from(
      { length: count },
      (_, chunk) => ({
        $id: uniqueId(),
        $createdAt: timestamp(),
        buildId,
        sequence,
        chunk,
        stage: stage ?? (current.stage || 'build'),
        content: content.slice(chunk * size, (chunk + 1) * size),
      }),
    )
    store.logs.push(...rows)
    current.logsSize += content.length
    const last = rows[rows.length - 1]
    publish(
      [
        ['distributionApps', current.appId],
        ['builds', buildId],
        ['logs', last.$id],
      ],
      'create',
      { ...last, chunks: count },
    )
    return rows
  }

  await page.addInitScript(
    ({ profileFeatures, activeLanguage }) => {
      localStorage.setItem(
        'debug:consoleProfile',
        JSON.stringify({ id: 'cloud', features: profileFeatures }),
      )
      if (activeLanguage) {
        localStorage.setItem('debug:language', activeLanguage)
      }
    },
    { profileFeatures: features, activeLanguage: language ?? '' },
  )

  await page.route('**/*', async (route) => {
    const request = route.request()
    const url = new URL(request.url())
    const apiPath = url.pathname.startsWith('/v1/')
      ? url.pathname.slice('/v1'.length)
      : undefined
    if (url.origin === localOrigin && !apiPath) return route.continue()

    const headers = corsHeaders(route)
    if (!apiPath || request.method() === 'OPTIONS') {
      return route.fulfill({ status: 204, headers })
    }
    const reply = ({ status, body, file }: Reply) =>
      file
        ? route.fulfill({
            status,
            headers: {
              ...headers,
              'content-type': 'application/octet-stream',
              'content-disposition': `attachment; filename="${file.name}"`,
            },
            body: file.content,
          })
        : body === undefined
          ? route.fulfill({ status, headers })
          : route.fulfill({
              status,
              headers: { ...headers, 'content-type': 'application/json' },
              body: JSON.stringify(body),
            })
    const json = (body: unknown) => reply(ok(body))
    const failure = (code: number, type: string, message: string) =>
      reply({ status: code, body: { message, code, type, version: '2.0.0' } })

    if (apiPath === '/account') return json(ACCOUNT)
    if (apiPath === '/account/prefs') {
      return request.method() === 'PATCH'
        ? json({ ...ACCOUNT, prefs: requestBody(route).prefs })
        : json({})
    }
    if (apiPath === '/account/sessions') return json({ total: 0, sessions: [] })
    if (apiPath === '/console/variables') {
      return json({
        _APP_DISTRIBUTION_ENABLED: enabled,
        _APP_VCS_ENABLED: true,
        _APP_VCS_PROVIDERS: ['github'],
        _APP_VCS_PROVIDERS_WITH_REPOSITORY_CREATION: ['github'],
        _APP_VCS_PROVIDERS_WITH_PUBLIC_REPOSITORIES: ['github'],
      })
    }
    if (apiPath === '/health/version') return json({ version: '2.0.0' })
    if (
      apiPath === '/console/scopes/project' ||
      apiPath === `/organizations/${ORGANIZATION.$id}/roles`
    ) {
      return json(
        role === 'owner'
          ? { roles: ['owner'], scopes: scopes ?? OWNER_SCOPES }
          : { roles: ['developer'], scopes: scopes ?? READONLY_SCOPES },
      )
    }
    if (apiPath === '/organizations' || apiPath === '/teams') {
      return json({ total: 1, teams: [ORGANIZATION] })
    }
    if (
      apiPath === `/organizations/${ORGANIZATION.$id}` ||
      apiPath === `/teams/${ORGANIZATION.$id}`
    ) {
      return json(ORGANIZATION)
    }
    if (apiPath.endsWith('/memberships')) {
      return json({ total: 0, memberships: [] })
    }
    if (apiPath === '/projects' || apiPath === '/organization/projects') {
      return json({ total: 1, projects: [PROJECT] })
    }
    if (
      apiPath === '/project' ||
      apiPath === `/projects/${PROJECT.$id}` ||
      apiPath === `/projects/${PROJECT.$id}/console-access`
    ) {
      return json(PROJECT)
    }

    const segments = apiPath.split('/').filter(Boolean).map(decodeURIComponent)
    const file = request.method() === 'GET' ? undefined : uploadedFile(request)
    const body =
      request.method() === 'GET' ? {} : file ? { file } : requestBody(route)
    const requestHeaders = request.headers()

    try {
      if (segments[0] === 'distribution') {
        requests.push({
          method: request.method(),
          path: apiPath,
          body,
          headers: requestHeaders,
        })
        if (!enabled) {
          return failure(
            503,
            'general_service_disabled',
            'The requested service is disabled.',
          )
        }
        return reply(
          distributionRoutes(
            store,
            request.method(),
            segments.slice(1),
            url,
            body,
            {
              headers: requestHeaders,
              file,
              uploads,
              publish,
              later,
            },
          ),
        )
      }
      if (segments[0] === 'vcs') {
        const handled = vcsRoutes(
          store,
          request.method(),
          segments.slice(1),
          url,
          body,
        )
        if (handled) return reply(handled)
      }
    } catch (error) {
      if (error instanceof Failure) {
        return failure(error.code, error.type, error.message)
      }
      throw error
    }

    return failure(404, 'general_route_not_found', 'Route not found.')
  })

  return { store, requests, realtime, change, appendLog }
}

async function keepScreenshot(name: string, image: Buffer) {
  const directory = process.env.SCREENSHOTS_DIR
  if (directory) {
    await mkdir(directory, { recursive: true })
    await writeFile(join(directory, name), image)
  }
}

export async function screenshot(page: Page, name: string) {
  await expect(page.locator('[data-fullscreen-loader]')).toBeHidden()
  const image = await page.screenshot({
    path: test.info().outputPath(name),
    fullPage: true,
  })
  await keepScreenshot(name, image)
}

export async function screenshotElement(locator: Locator, name: string) {
  await expect(locator).toBeVisible()
  const image = await locator.screenshot({
    path: test.info().outputPath(name),
  })
  await keepScreenshot(name, image)
}
