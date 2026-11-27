import { describe, expect, test } from 'bun:test'
import {
  ACTIVE_SUBMISSION_STATUSES,
  ARTIFACT_STATUS,
  BUILD_STATUS,
  CHECK_SEVERITY,
  CONNECTION_STATUS,
  RELEASE_STATUS,
  SIGNING_IDENTITY_STATUS,
  SUBMISSION_STATUS,
  isArtifactPending,
  isBuildActive,
  isSubmissionActive,
  rolloutPercent,
  statusAppearance,
  submissionActions,
} from '@/lib/distribution/status'
import type {
  StatusAppearance,
  StatusVariant,
  SubmissionActions,
} from '@/lib/distribution/status'
import { heDictionary } from '@/lib/i18n/dictionaries/he'
import { jaDictionary } from '@/lib/i18n/dictionaries/ja'

type Expectation = [status: string, label: string, variant: StatusVariant]

const MAPS: [
  name: string,
  map: Record<string, StatusAppearance>,
  expected: Expectation[],
][] = [
  [
    'build',
    BUILD_STATUS,
    [
      ['waiting', 'Waiting', 'pending'],
      ['processing', 'Processing', 'processing'],
      ['building', 'Building', 'processing'],
      ['signing', 'Signing', 'processing'],
      ['ready', 'Ready', 'success'],
      ['failed', 'Failed', 'error'],
      ['canceled', 'Canceled', 'inactive'],
    ],
  ],
  [
    'connection',
    CONNECTION_STATUS,
    [
      ['active', 'Active', 'success'],
      ['invalid', 'Invalid', 'error'],
    ],
  ],
  [
    'signing identity',
    SIGNING_IDENTITY_STATUS,
    [
      ['active', 'Active', 'success'],
      ['expiring', 'Expiring', 'warning'],
      ['expired', 'Expired', 'error'],
      ['revoked', 'Revoked', 'inactive'],
    ],
  ],
  [
    'artifact',
    ARTIFACT_STATUS,
    [
      ['uploading', 'Uploading', 'processing'],
      ['processing', 'Processing', 'processing'],
      ['ready', 'Ready', 'success'],
      ['invalid', 'Invalid', 'error'],
    ],
  ],
  [
    'submission',
    SUBMISSION_STATUS,
    [
      ['waiting', 'Waiting', 'pending'],
      ['validating', 'Validating', 'processing'],
      ['uploading', 'Uploading', 'processing'],
      ['processing', 'Processing', 'processing'],
      ['pending', 'Pending', 'warning'],
      ['reviewing', 'In review', 'processing'],
      ['approved', 'Approved', 'info'],
      ['published', 'Published', 'success'],
      ['rejected', 'Rejected', 'error'],
      ['halted', 'Halted', 'warning'],
      ['failed', 'Failed', 'error'],
      ['canceled', 'Canceled', 'inactive'],
    ],
  ],
  [
    'release',
    RELEASE_STATUS,
    [
      ['draft', 'Draft', 'inactive'],
      ['active', 'Active', 'processing'],
      ['published', 'Published', 'success'],
      ['attention', 'Needs attention', 'error'],
    ],
  ],
  [
    'check severity',
    CHECK_SEVERITY,
    [
      ['error', 'Blocker', 'error'],
      ['warning', 'Warning', 'warning'],
    ],
  ],
]

describe('status maps', () => {
  describe.each(MAPS)('%s', (_name, map, expected) => {
    test('covers exactly the server enum values', () => {
      expect(Object.keys(map).sort()).toEqual(
        expected.map(([status]) => status).sort(),
      )
    })

    test.each(expected)('maps %s to %s / %s', (status, label, variant) => {
      expect(statusAppearance(status, map)).toEqual({ label, variant })
    })

    test.each(expected)('translates the %s label', (_status, label) => {
      expect(heDictionary[label]).toBeString()
      expect(jaDictionary[label]).toBeString()
    })
  })
})

describe('statusAppearance', () => {
  test('falls back to the capitalized status with the info variant', () => {
    expect(statusAppearance('superseded', SUBMISSION_STATUS)).toEqual({
      label: 'Superseded',
      variant: 'info',
    })
  })

  test('falls back to Unknown for an empty status', () => {
    expect(statusAppearance('', BUILD_STATUS)).toEqual({
      label: 'Unknown',
      variant: 'info',
    })
  })

  test('does not resolve object prototype keys as statuses', () => {
    expect(statusAppearance('toString', RELEASE_STATUS)).toEqual({
      label: 'ToString',
      variant: 'info',
    })
  })
})

describe('isBuildActive', () => {
  test.each([
    ['waiting', true],
    ['processing', true],
    ['building', true],
    ['signing', true],
    ['ready', false],
    ['failed', false],
    ['canceled', false],
    ['', false],
  ])('%s is active: %p', (status, active) => {
    expect(isBuildActive(status)).toBe(active)
  })
})

describe('isArtifactPending', () => {
  test.each([
    ['uploading', true],
    ['processing', true],
    ['ready', false],
    ['invalid', false],
    ['', false],
  ])('%s is pending: %p', (status, pending) => {
    expect(isArtifactPending(status)).toBe(pending)
  })
})

const SUBMISSION_STATUSES = [
  'waiting',
  'validating',
  'uploading',
  'processing',
  'pending',
  'reviewing',
  'approved',
  'published',
  'rejected',
  'halted',
  'failed',
  'canceled',
]

const ROLLOUTS = [0.2, 1]

const AUTOMATIC = [true, false]

const PROVIDERS = ['google', 'apple']

type Action = keyof SubmissionActions

type Combination = `${'partial' | 'full'} ${'automatic' | 'manual'}`

function combination(rollout: number, automatic: boolean): Combination {
  return `${rollout < 1 ? 'partial' : 'full'} ${automatic ? 'automatic' : 'manual'}`
}

const BEFORE_REVIEW: Record<Combination, Action[]> = {
  'partial automatic': ['cancel'],
  'partial manual': ['cancel'],
  'full automatic': ['cancel'],
  'full manual': ['cancel'],
}

const SETTLED: Record<Combination, Action[]> = {
  'partial automatic': [],
  'partial manual': [],
  'full automatic': [],
  'full manual': [],
}

const GOOGLE_ACTIONS: Record<string, Record<Combination, Action[]>> = {
  waiting: BEFORE_REVIEW,
  validating: BEFORE_REVIEW,
  uploading: BEFORE_REVIEW,
  processing: BEFORE_REVIEW,
  pending: BEFORE_REVIEW,
  reviewing: BEFORE_REVIEW,
  approved: {
    'partial automatic': ['cancel'],
    'partial manual': ['cancel', 'publish'],
    'full automatic': ['cancel'],
    'full manual': ['cancel', 'publish'],
  },
  published: {
    'partial automatic': ['halt', 'rollout'],
    'partial manual': ['halt', 'rollout'],
    'full automatic': [],
    'full manual': [],
  },
  rejected: SETTLED,
  halted: {
    'partial automatic': ['resume', 'rollout'],
    'partial manual': ['resume', 'rollout'],
    'full automatic': ['resume', 'rollout'],
    'full manual': ['resume', 'rollout'],
  },
  failed: SETTLED,
  canceled: SETTLED,
}

const ACTIVE: Record<string, Record<'partial' | 'full', boolean>> = {
  waiting: { partial: true, full: true },
  validating: { partial: true, full: true },
  uploading: { partial: true, full: true },
  processing: { partial: true, full: true },
  pending: { partial: true, full: true },
  reviewing: { partial: true, full: true },
  approved: { partial: true, full: true },
  published: { partial: true, full: false },
  rejected: { partial: false, full: false },
  halted: { partial: true, full: true },
  failed: { partial: false, full: false },
  canceled: { partial: false, full: false },
}

function enabled(actions: SubmissionActions): Action[] {
  return (Object.keys(actions) as Action[])
    .filter((action) => actions[action])
    .sort()
}

const MATRIX = SUBMISSION_STATUSES.flatMap((status) =>
  ROLLOUTS.flatMap((rollout) =>
    AUTOMATIC.flatMap((automatic) =>
      PROVIDERS.map(
        (provider) => [status, rollout, automatic, provider] as const,
      ),
    ),
  ),
)

describe('submission tables', () => {
  test('cover every server submission status', () => {
    expect(Object.keys(SUBMISSION_STATUS).sort()).toEqual(
      [...SUBMISSION_STATUSES].sort(),
    )
    expect(Object.keys(GOOGLE_ACTIONS).sort()).toEqual(
      [...SUBMISSION_STATUSES].sort(),
    )
    expect(Object.keys(ACTIVE).sort()).toEqual([...SUBMISSION_STATUSES].sort())
  })

  test('active statuses match Submissions::ACTIVE on the server', () => {
    expect([...ACTIVE_SUBMISSION_STATUSES]).toEqual([
      'waiting',
      'validating',
      'uploading',
      'processing',
      'pending',
      'reviewing',
      'approved',
      'halted',
    ])
  })
})

describe('isSubmissionActive', () => {
  test.each(
    SUBMISSION_STATUSES.flatMap((status) =>
      ROLLOUTS.map((rollout) => [status, rollout] as const),
    ),
  )('%s at rollout %p', (status, rollout) => {
    const expected = ACTIVE[status][rollout < 1 ? 'partial' : 'full']
    expect(isSubmissionActive(status, rollout)).toBe(expected)
  })
})

describe('submissionActions', () => {
  test.each(MATRIX)(
    '%s at rollout %p, automatic %p, provider %s',
    (status, rollout, automatic, provider) => {
      const google = GOOGLE_ACTIONS[status][combination(rollout, automatic)]
      const expected =
        provider === 'google'
          ? google
          : google.filter((action) => action !== 'rollout')
      const actions = submissionActions({
        status,
        rollout,
        automatic,
        provider,
      })
      expect(enabled(actions)).toEqual([...expected].sort())
    },
  )

  test.each(MATRIX)(
    '%s at rollout %p, automatic %p, provider %s only offers status requests for active submissions',
    (status, rollout, automatic, provider) => {
      const { cancel, halt, resume, publish } = submissionActions({
        status,
        rollout,
        automatic,
        provider,
      })
      if (cancel || halt || resume || publish) {
        expect(isSubmissionActive(status, rollout)).toBe(true)
      }
    },
  )
})

describe('submissionActions after the store released the version', () => {
  const STEPS = ['', 'validated', 'located', 'uploaded', 'processed']
  const RELEASED = ['released', 'settled']

  test.each(RELEASED)(
    'Google Play cannot cancel a submission at step %s',
    (step) => {
      for (const status of SUBMISSION_STATUSES) {
        const actions = submissionActions({
          status,
          rollout: 1,
          automatic: true,
          provider: 'google',
          step,
        })
        expect(actions.cancel).toBe(false)
      }
    },
  )

  test.each(STEPS)('Google Play keeps the cancel rule at step %p', (step) => {
    const actions = submissionActions({
      status: 'reviewing',
      rollout: 1,
      automatic: true,
      provider: 'google',
      step,
    })
    expect(actions.cancel).toBe(true)
  })

  test.each(RELEASED)('the App Store can still cancel at step %s', (step) => {
    const actions = submissionActions({
      status: 'approved',
      rollout: 1,
      automatic: false,
      provider: 'apple',
      step,
    })
    expect(actions.cancel).toBe(true)
  })

  test('a released step keeps the other Google Play controls', () => {
    const actions = submissionActions({
      status: 'halted',
      rollout: 0.5,
      automatic: true,
      provider: 'google',
      step: 'released',
    })
    expect(enabled(actions)).toEqual(['resume', 'rollout'])
  })
})

describe('rolloutPercent', () => {
  test.each([
    [0, 0],
    [0.2, 20],
    [0.005, 1],
    [0.333, 33],
    [1, 100],
  ])('%p is %p percent', (rollout, percent) => {
    expect(rolloutPercent(rollout)).toBe(percent)
  })
})
