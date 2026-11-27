import type { Page, Route } from '@playwright/test'
import type { Models } from '@appwrite.io/console'
import type {
  DistributionApp,
  DistributionConnection,
  DistributionGoogleServiceAccount,
  DistributionPlatform,
  DistributionProvider,
  DistributionSigningIdentity,
  DistributionSigningIdentityDownload,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import { expect, test } from '../fixtures'

export { expect, test }

export const NOW = '2026-11-20T12:00:00.000+00:00'

const DAY = 24 * 60 * 60 * 1000

function daysFromNow(days: number): string {
  return new Date(Date.parse(NOW) + days * DAY).toISOString()
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
      $id: `${appId.slice(-8)}ios`,
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
      $id: `${appId.slice(-8)}android`,
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
}

export type DistributionRole = 'owner' | 'readonly'

export type DistributionMockOptions = {
  enabled?: boolean
  features?: Record<string, boolean>
  role?: DistributionRole
  empty?: boolean
  seed?: DistributionSeed
  language?: string
}

export type DistributionRequest = {
  method: string
  path: string
  body: Record<string, unknown>
}

export type DistributionStore = Required<DistributionSeed>

export type DistributionMock = {
  store: DistributionStore
  requests: DistributionRequest[]
}

const OWNER_SCOPES = [
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
  const order = queries.find(
    (query) => query.method === 'orderAsc' || query.method === 'orderDesc',
  )
  if (order?.attribute) {
    const direction = order.method === 'orderAsc' ? 1 : -1
    rows = [...rows].sort(
      (left, right) =>
        String(field(left, order.attribute!)).localeCompare(
          String(field(right, order.attribute!)),
        ) * direction,
    )
  }
  const numeric = (method: string) => {
    const query = queries.find((candidate) => candidate.method === method)
    return query ? Number(query.values?.[0]) : undefined
  }
  const total = rows.length
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

function fingerprint(): string {
  return Array.from({ length: 64 }, () =>
    Math.floor(Math.random() * 16).toString(16),
  ).join('')
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

type Reply = { status: number; body?: unknown }

function ok(body: unknown, status: number = 200): Reply {
  return { status, body }
}

const NO_CONTENT: Reply = { status: 204 }

function distributionRoutes(
  store: DistributionStore,
  method: string,
  segments: string[],
  url: URL,
  body: Record<string, unknown>,
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

  return notFound('general_route_not_found', 'Route not found.')
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
  }: DistributionMockOptions = {},
): Promise<DistributionMock> {
  const localOrigin = new URL(String(test.info().project.use.baseURL)).origin
  const store = initialStore(empty, seed)
  const requests: DistributionRequest[] = []

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
    const reply = ({ status, body }: Reply) =>
      body === undefined
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
          ? { roles: ['owner'], scopes: OWNER_SCOPES }
          : { roles: ['developer'], scopes: READONLY_SCOPES },
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
    const body = request.method() === 'GET' ? {} : requestBody(route)

    try {
      if (segments[0] === 'distribution') {
        requests.push({ method: request.method(), path: apiPath, body })
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

  return { store, requests }
}

export async function screenshot(page: Page, name: string) {
  await expect(page.locator('[data-fullscreen-loader]')).toBeHidden()
  await page.screenshot({
    path: test.info().outputPath(name),
    fullPage: true,
  })
}
