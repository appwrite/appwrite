import { describe, expect, test } from 'bun:test'
import type { DistributionCheckIssue } from '@/lib/appwrite/distribution'
import {
  artifactVersion,
  checkKey,
  hasBlockingIssues,
  isPublishComplete,
  isReleaseValid,
  isSourceValid,
  publishOrder,
  publishTrack,
  releaseNotes,
  targetTrack,
  targetsState,
  type ReleaseDraft,
  type TargetCheck,
  type TargetSource,
} from '@/lib/distribution/publish'

function issue(severity: 'error' | 'warning'): DistributionCheckIssue {
  return {
    code: severity === 'error' ? 'signing_missing' : 'store_review_in_progress',
    severity,
    platform: 'ios',
    field: '',
    message: 'Message.',
    remediation: '',
    manualActionRequired: false,
  }
}

function done(...severities: ('error' | 'warning')[]): TargetCheck {
  const issues = severities.map(issue)
  return {
    status: 'done',
    check: { ready: !hasBlockingIssues({ issues }), issues },
  }
}

const IOS = { targetId: 'ios', track: 'testflight' }
const ANDROID = { targetId: 'android', track: 'internal' }

describe('targetsState', () => {
  test('is empty without a selected target', () => {
    expect(targetsState([], {})).toBe('empty')
  })

  test('is unchecked until every selected target has a check for its track', () => {
    const checks = { [checkKey('ios', 'testflight')]: done() }
    expect(targetsState([IOS, ANDROID], checks)).toBe('unchecked')
    expect(
      targetsState([{ targetId: 'ios', track: 'production' }], checks),
    ).toBe('unchecked')
  })

  test('is checking while any check runs', () => {
    expect(
      targetsState([IOS, ANDROID], {
        [checkKey('ios', 'testflight')]: { status: 'running' },
      }),
    ).toBe('checking')
  })

  test('is failed when a check request failed', () => {
    expect(
      targetsState([IOS], {
        [checkKey('ios', 'testflight')]: { status: 'failed', error: 'Nope' },
      }),
    ).toBe('failed')
  })

  test('blocks on error issues and allows warnings', () => {
    const checks = {
      [checkKey('ios', 'testflight')]: done('warning'),
      [checkKey('android', 'internal')]: done('error', 'warning'),
    }
    expect(targetsState([IOS, ANDROID], checks)).toBe('blocked')
    expect(targetsState([IOS], checks)).toBe('ready')
  })
})

describe('release draft', () => {
  const draft = (changes: Partial<ReleaseDraft>): ReleaseDraft => ({
    mode: 'new',
    releaseId: '',
    version: '',
    notes: '',
    ...changes,
  })

  test('a new release needs a version of 1 to 64 characters', () => {
    expect(isReleaseValid(draft({ version: '  ' }))).toBe(false)
    expect(isReleaseValid(draft({ version: '1.4.0' }))).toBe(true)
    expect(isReleaseValid(draft({ version: 'a'.repeat(64) }))).toBe(true)
    expect(isReleaseValid(draft({ version: 'a'.repeat(65) }))).toBe(false)
  })

  test('an existing release needs a release ID', () => {
    expect(isReleaseValid(draft({ mode: 'existing' }))).toBe(false)
    expect(
      isReleaseValid(draft({ mode: 'existing', releaseId: 'release1' })),
    ).toBe(true)
  })

  test('notes are sent as en-US only when present', () => {
    expect(releaseNotes(draft({ notes: '   ' }))).toBeUndefined()
    expect(releaseNotes(draft({ notes: ' Bug fixes. ' }))).toEqual({
      'en-US': 'Bug fixes.',
    })
  })
})

describe('sources', () => {
  const source = (changes: Partial<TargetSource>): TargetSource => ({
    kind: 'build',
    artifactId: '',
    versionCode: '',
    track: 'internal',
    automatic: true,
    ...changes,
  })

  test('an artifact source needs an artifact', () => {
    expect(isSourceValid(source({ kind: 'artifact' }), false, 'main')).toBe(
      false,
    )
    expect(
      isSourceValid(source({ kind: 'artifact', artifactId: 'a1' }), true, ''),
    ).toBe(true)
  })

  test('a build source needs a version code only for manual targets', () => {
    expect(isSourceValid(source({}), false, 'main')).toBe(true)
    expect(isSourceValid(source({}), true, 'main')).toBe(false)
    expect(isSourceValid(source({ versionCode: '18' }), true, 'main')).toBe(
      true,
    )
  })

  test('a build source needs a branch to build from', () => {
    expect(isSourceValid(source({}), false, '')).toBe(false)
    expect(isSourceValid(source({}), false, '  ')).toBe(false)
    expect(isSourceValid(source({ versionCode: '18' }), true, '')).toBe(false)
  })

  test('orders artifact submissions before builds and keeps target order', () => {
    const targets = [{ $id: 'ios' }, { $id: 'android' }, { $id: 'web' }]
    const ordered = publishOrder(targets, {
      ios: source({ kind: 'build' }),
      android: source({ kind: 'artifact', artifactId: 'a1' }),
      web: source({ kind: 'artifact', artifactId: 'a2' }),
    })
    expect(ordered.map((target) => target.$id)).toEqual([
      'android',
      'web',
      'ios',
    ])
  })
})

describe('isPublishComplete', () => {
  test('needs every target done', () => {
    expect(isPublishComplete([], {})).toBe(false)
    expect(
      isPublishComplete(['ios', 'android'], {
        ios: { status: 'done' },
        android: { status: 'failed', error: 'Version invalid' },
      }),
    ).toBe(false)
    expect(
      isPublishComplete(['ios', 'android'], {
        ios: { status: 'done' },
        android: { status: 'done' },
      }),
    ).toBe(true)
  })
})

describe('tracks', () => {
  const APPLE = ['testflight', 'testflight-external', 'production']
  const source = (kind: TargetSource['kind'], track: string): TargetSource => ({
    kind,
    artifactId: '',
    versionCode: '',
    track,
    automatic: true,
  })

  test('keeps a target track the store accepts', () => {
    expect(targetTrack('production', APPLE)).toBe('production')
  })

  test('falls back to the first track for an empty or unknown target track', () => {
    expect(targetTrack('', APPLE)).toBe('testflight')
    expect(targetTrack('beta', APPLE)).toBe('testflight')
  })

  test('artifacts use the track chosen for their source', () => {
    expect(publishTrack(source('artifact', 'production'), 'testflight')).toBe(
      'production',
    )
  })

  test('builds use the default track even after an artifact track was chosen', () => {
    expect(publishTrack(source('build', 'production'), 'testflight')).toBe(
      'testflight',
    )
  })

  test('uses the default track before a source exists', () => {
    expect(publishTrack(undefined, 'testflight')).toBe('testflight')
  })
})

describe('artifactVersion', () => {
  test('omits the name when it is empty, without a leading space', () => {
    expect(
      artifactVersion({ versionName: '', versionCode: 17, buildNumber: '' }),
    ).toBe('(17)')
    expect(
      artifactVersion({ versionName: '', versionCode: 0, buildNumber: '42' }),
    ).toBe('(42)')
    expect(
      artifactVersion({ versionName: '', versionCode: 0, buildNumber: '' }),
    ).toBe('')
  })

  test('shows the build number, else the version code', () => {
    expect(
      artifactVersion({
        versionName: '1.4.0',
        versionCode: 0,
        buildNumber: '42',
      }),
    ).toBe('1.4.0 (42)')
    expect(
      artifactVersion({
        versionName: '1.3.0',
        versionCode: 17,
        buildNumber: '',
      }),
    ).toBe('1.3.0 (17)')
    expect(
      artifactVersion({
        versionName: '1.0.0',
        versionCode: 0,
        buildNumber: '',
      }),
    ).toBe('1.0.0')
  })
})
