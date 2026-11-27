import { describe, expect, test } from 'bun:test'
import type {
  DistributionApp,
  DistributionBuild,
  DistributionTarget,
} from '@/lib/appwrite/distribution'
import {
  GOOGLE_PLAY_VERSION_CODE_MAX,
  buildDuration,
  buildSource,
  buildVersion,
  cancelBlocker,
  createBlocker,
  deleteBlocker,
  formatDuration,
  hasRepository,
  parseVersionCode,
  retryBlocker,
  retryBuildParams,
  shortCommit,
  stageLabel,
  triggerLabel,
  type BuildPermissions,
} from '@/lib/distribution/builds'

const HASH = '9f1c2e7a4b3d5c6e8f0a1b2c3d4e5f60718293a4'

function build(overrides: Partial<DistributionBuild> = {}): DistributionBuild {
  return {
    $id: 'build0000000000000000001',
    $createdAt: '2026-11-20T12:00:00.000+00:00',
    $updatedAt: '2026-11-20T12:00:00.000+00:00',
    appId: 'app00000000000000000001',
    targetId: '00000001android',
    platform: 'android',
    type: 'manual',
    status: 'building',
    stage: 'build',
    attempt: 1,
    providerCommitHash: HASH,
    providerBranch: 'main',
    providerTag: '',
    providerCommitMessage: 'Add saved carts',
    providerCommitAuthor: 'Demo',
    providerCommitAuthorUrl: '',
    providerCommitUrl: `https://github.com/acme/shop/commit/${HASH}`,
    providerBranchUrl: 'https://github.com/acme/shop/tree/main',
    providerRepositoryName: 'shop',
    providerRepositoryOwner: 'acme',
    providerRepositoryUrl: 'https://github.com/acme/shop',
    providerRootDirectory: './',
    versionName: '1.5.0',
    versionCode: 18,
    buildNumber: '',
    toolchain: {},
    specification: '',
    cpus: 2,
    memory: 4096,
    timeout: 3600,
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

function target(
  versionStrategy: DistributionTarget['versionStrategy'],
): DistributionTarget {
  return {
    $id: '00000001android',
    $createdAt: '',
    $updatedAt: '',
    appId: 'app00000000000000000001',
    platform: 'android',
    identifier: 'com.acme.shop',
    connectionId: '',
    signingIdentityId: '',
    storeAppId: '',
    track: 'internal',
    automatic: false,
    versionStrategy,
    versionCode: 17,
    installCommand: '',
    buildCommand: '',
    outputPath: '',
    configuration: {},
    specification: '',
    timeout: 0,
  }
}

function app(): DistributionApp {
  return {
    $id: 'app00000000000000000001',
    $createdAt: '',
    $updatedAt: '',
    name: 'Shop',
    enabled: true,
    framework: 'flutter',
    installationId: 'installation',
    providerRepositoryId: '1001',
    repositoryId: 'repository',
    providerBranch: 'main',
    providerRootDirectory: './',
    providerBranches: [],
    providerPaths: [],
    providerTags: [],
    providerSilentMode: false,
    providerComments: false,
    providerDeployments: false,
    providerStatuses: false,
    latestBuildId: '',
    latestBuildStatus: '',
    latestBuildCreatedAt: '',
  }
}

const WRITER: BuildPermissions = { canWrite: true, hasRepository: true }

const READER: BuildPermissions = { canWrite: false, hasRepository: true }

const UNLINKED: BuildPermissions = { canWrite: true, hasRepository: false }

const NO_PERMISSION = "You don't have permission to perform this action."

describe('buildSource', () => {
  test('prefers the tag over the branch', () => {
    expect(buildSource(build({ providerTag: 'v1.5.0' }))).toEqual({
      type: 'tag',
      name: 'v1.5.0',
      url: '',
    })
  })

  test('uses the branch and its URL', () => {
    expect(buildSource(build())).toEqual({
      type: 'branch',
      name: 'main',
      url: 'https://github.com/acme/shop/tree/main',
    })
  })

  test('falls back to the short commit', () => {
    expect(buildSource(build({ providerBranch: '' }))).toEqual({
      type: 'commit',
      name: '9f1c2e7',
      url: `https://github.com/acme/shop/commit/${HASH}`,
    })
  })

  test('returns null without any reference', () => {
    expect(
      buildSource(build({ providerBranch: '', providerCommitHash: '' })),
    ).toBeNull()
  })
})

describe('buildVersion', () => {
  test.each([
    [{ versionName: '1.5.0', versionCode: 18, buildNumber: '' }, '1.5.0 (18)'],
    [{ versionName: '1.4.0', versionCode: 0, buildNumber: '42' }, '1.4.0 (42)'],
    [{ versionName: '1.4.0', versionCode: 0, buildNumber: '' }, '1.4.0'],
    [{ versionName: '', versionCode: 7, buildNumber: '' }, '7'],
    [{ versionName: '', versionCode: 0, buildNumber: '' }, ''],
  ])('formats %o as "%s"', (overrides, expected) => {
    expect(buildVersion(build(overrides))).toBe(expected)
  })
})

describe('buildDuration', () => {
  const started = '2026-11-20T12:00:00.000Z'
  const now = Date.parse('2026-11-20T12:02:05.900Z')

  test('uses the recorded duration first', () => {
    expect(
      buildDuration(build({ duration: 612, startedAt: started }), now),
    ).toBe(612)
  })

  test('counts from the start until now while running', () => {
    expect(buildDuration(build({ startedAt: started }), now)).toBe(125)
  })

  test('counts until the end once finished', () => {
    expect(
      buildDuration(
        build({ startedAt: started, endedAt: '2026-11-20T12:00:30.000Z' }),
        now,
      ),
    ).toBe(30)
  })

  test('is zero before the build starts or with a bad date', () => {
    expect(buildDuration(build(), now)).toBe(0)
    expect(buildDuration(build({ startedAt: 'never' }), now)).toBe(0)
  })
})

describe('formatDuration', () => {
  test.each([
    [0, '0s'],
    [45.7, '45s'],
    [65, '1m 05s'],
    [3599, '59m 59s'],
    [3720, '1h 02m'],
    [86399, '23h 59m'],
    [86400, '1d 00h'],
    [90000, '1d 01h'],
    [601800, '6d 23h'],
    [-3, '0s'],
  ])('formats %d seconds as %s', (seconds, expected) => {
    expect(formatDuration(seconds)).toBe(expected)
  })
})

describe('retryBuildParams', () => {
  test('rebuilds the same commit', () => {
    expect(retryBuildParams(build(), target('automatic'))).toEqual({
      appId: 'app00000000000000000001',
      targetId: '00000001android',
      type: 'commit',
      reference: HASH,
    })
  })

  test('keeps the version code of a manual target and the release', () => {
    expect(
      retryBuildParams(
        build({ releaseId: 'release0000000000000001' }),
        target('manual'),
      ),
    ).toEqual({
      appId: 'app00000000000000000001',
      targetId: '00000001android',
      type: 'commit',
      reference: HASH,
      versionCode: 18,
      releaseId: 'release0000000000000001',
    })
  })

  test('cannot retry a build without a commit', () => {
    expect(
      retryBuildParams(build({ providerCommitHash: '' }), target('manual')),
    ).toBeNull()
  })
})

describe('parseVersionCode', () => {
  test.each([
    ['18', 18],
    [' 7 ', 7],
    ['', null],
    ['0', null],
    ['-4', null],
    ['1.5', null],
    ['1e3', null],
    ['0x10', null],
    ['abc', null],
    [String(GOOGLE_PLAY_VERSION_CODE_MAX), GOOGLE_PLAY_VERSION_CODE_MAX],
    [String(GOOGLE_PLAY_VERSION_CODE_MAX + 1), null],
    ['02100000000', null],
  ])('parses "%s" as %p', (value, expected) => {
    expect(parseVersionCode(value)).toBe(expected)
  })
})

describe('labels', () => {
  test('names known triggers and stages and leaves others unnamed', () => {
    expect(triggerLabel('vcs')).toBe('Git push')
    expect(triggerLabel('manual')).toBe('Manual')
    expect(triggerLabel('schedule')).toBeNull()
    expect(stageLabel('sign')).toBe('Signing')
    expect(stageLabel('custom')).toBeNull()
    expect(shortCommit(HASH)).toBe('9f1c2e7')
  })
})

describe('hasRepository', () => {
  test('needs both the installation and the repository', () => {
    expect(
      hasRepository({ installationId: 'one', providerRepositoryId: '1001' }),
    ).toBe(true)
    expect(
      hasRepository({ installationId: '', providerRepositoryId: '1001' }),
    ).toBe(false)
    expect(
      hasRepository({ installationId: 'one', providerRepositoryId: '' }),
    ).toBe(false)
  })
})

describe('blockers', () => {
  test('creating a build needs write access, a repository and a target', () => {
    expect(createBlocker(app(), [target('automatic')], WRITER)).toBeNull()
    expect(createBlocker(app(), [target('automatic')], READER)).toBe(
      NO_PERMISSION,
    )
    expect(createBlocker(undefined, [], READER)).toBe(NO_PERMISSION)
    expect(createBlocker(undefined, [], WRITER)).toBeNull()
    expect(createBlocker(app(), [target('automatic')], UNLINKED)).toBe(
      'Connect a repository to the app to start builds.',
    )
    expect(createBlocker(app(), [], WRITER)).toBe(
      'Add a target to the app to start builds.',
    )
  })

  test('retrying a build needs write access, a repository and a commit', () => {
    expect(retryBlocker(build(), WRITER)).toBeNull()
    expect(retryBlocker(build(), READER)).toBe(NO_PERMISSION)
    expect(retryBlocker(build(), UNLINKED)).toBe(
      'Connect a repository to the app to start builds.',
    )
    expect(retryBlocker(build({ providerCommitHash: '' }), WRITER)).toBe(
      'This build has no commit to build again.',
    )
  })

  test('deleting a build needs write access and a finished build', () => {
    expect(deleteBlocker(build({ status: 'ready' }), WRITER)).toBeNull()
    expect(deleteBlocker(build({ status: 'ready' }), READER)).toBe(
      NO_PERMISSION,
    )
    expect(deleteBlocker(build({ status: 'building' }), WRITER)).toBe(
      'Cancel the build before you delete it.',
    )
  })

  test('canceling a build needs write access only', () => {
    expect(cancelBlocker(WRITER)).toBeNull()
    expect(cancelBlocker(UNLINKED)).toBeNull()
    expect(cancelBlocker(READER)).toBe(NO_PERMISSION)
  })
})
