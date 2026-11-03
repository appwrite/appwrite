import { describe, expect, test } from 'bun:test'
import { canRetryRule, canViewRuleLogs } from '@/lib/utils/proxy-domains'
import {
  getDomainStatusBadgeConfig,
  getRuleStatusBadgeConfig,
} from '@/lib/utils/status-badge'

const ISSUED = 'SSL certificate successfully issued.'
const WARNING =
  'SSL certificate expires soon, and the certificate provider has not renewed it yet.'

const DAY_MS = 24 * 60 * 60 * 1000
const NOW = new Date('2026-11-03T12:00:00.000Z')

function expiresIn(ms: number): string {
  return new Date(NOW.getTime() + ms).toISOString()
}

describe('getRuleStatusBadgeConfig', () => {
  test('keeps Verified when the server reports no certificate state', () => {
    expect(getRuleStatusBadgeConfig({ status: 'verified' }, NOW)).toEqual({
      variant: 'success',
      label: 'Verified',
    })
    expect(
      getRuleStatusBadgeConfig(
        { status: 'verified', expireAt: '', certificateStatus: '' },
        NOW,
      ),
    ).toEqual({ variant: 'success', label: 'Verified' })
    expect(
      getRuleStatusBadgeConfig(
        { status: 'verified', expireAt: 'not a date', certificateStatus: '' },
        NOW,
      ),
    ).toEqual({ variant: 'success', label: 'Verified' })
  })

  test('keeps Verified with the expiry of a healthy certificate', () => {
    const expireAt = expiresIn(20 * DAY_MS)
    for (const certificateStatus of ['issued', 'renewing', 'unknown', '']) {
      const config = getRuleStatusBadgeConfig(
        { status: 'verified', expireAt, certificateStatus },
        NOW,
      )
      expect(config.variant).toBe('success')
      expect(config.label).toBe('Verified')
      expect(config.hint).toBeUndefined()
      expect(config.expireAt?.toISOString()).toBe(expireAt)
    }
  })

  test('warns from 7 days before the certificate expires', () => {
    const outside = getRuleStatusBadgeConfig(
      { status: 'verified', expireAt: expiresIn(7 * DAY_MS + 1) },
      NOW,
    )
    expect(outside.label).toBe('Verified')

    for (const remaining of [7 * DAY_MS, DAY_MS, 0]) {
      const config = getRuleStatusBadgeConfig(
        { status: 'verified', expireAt: expiresIn(remaining) },
        NOW,
      )
      expect(config.variant).toBe('warning')
      expect(config.label).toBe('Expires soon')
      expect(config.hint).toBeDefined()
      expect(config.expireAt).toBeUndefined()
    }
  })

  test('warns when the provider has not renewed the certificate yet', () => {
    const config = getRuleStatusBadgeConfig(
      {
        status: 'verified',
        expireAt: expiresIn(20 * DAY_MS),
        certificateStatus: 'expiring',
      },
      NOW,
    )
    expect(config.variant).toBe('warning')
    expect(config.label).toBe('Expires soon')
  })

  test('shows an expired certificate as an error, over any other state', () => {
    for (const certificateStatus of ['failed', 'blocked', 'expiring', '']) {
      const config = getRuleStatusBadgeConfig(
        { status: 'verified', expireAt: expiresIn(-1), certificateStatus },
        NOW,
      )
      expect(config.variant).toBe('error')
      expect(config.label).toBe('Certificate expired')
      expect(config.hint).toBeDefined()
    }
  })

  test('shows a failed renewal over a blocked or expiring one', () => {
    expect(
      getRuleStatusBadgeConfig(
        {
          status: 'verified',
          expireAt: expiresIn(DAY_MS),
          certificateStatus: 'failed',
        },
        NOW,
      ),
    ).toMatchObject({ variant: 'error', label: 'Renewal failed' })
    expect(
      getRuleStatusBadgeConfig(
        { status: 'verified', certificateStatus: 'failed' },
        NOW,
      ),
    ).toMatchObject({ variant: 'error', label: 'Renewal failed' })
  })

  test('shows a blocked renewal over an expiring one', () => {
    expect(
      getRuleStatusBadgeConfig(
        {
          status: 'verified',
          expireAt: expiresIn(DAY_MS),
          certificateStatus: 'blocked',
        },
        NOW,
      ),
    ).toMatchObject({ variant: 'warning', label: 'Renewal blocked' })
  })

  test('keeps the domain status badge for a rule that is not verified', () => {
    for (const status of ['verifying', 'created', 'unverified']) {
      expect(
        getRuleStatusBadgeConfig(
          {
            status,
            expireAt: expiresIn(-DAY_MS),
            certificateStatus: 'failed',
          },
          NOW,
        ),
      ).toEqual(getDomainStatusBadgeConfig(status))
    }
  })
})

describe('canViewRuleLogs', () => {
  test('hides logs on a verified rule no certificate check has written', () => {
    expect(canViewRuleLogs({ status: 'verified', logs: '' })).toBe(false)
    expect(canViewRuleLogs({ status: 'verified', logs: [] })).toBe(false)
    expect(canViewRuleLogs({ status: 'verified' })).toBe(false)
  })

  test('shows logs on a verified rule a certificate check has written', () => {
    expect(canViewRuleLogs({ status: 'verified', logs: ISSUED })).toBe(true)
    expect(canViewRuleLogs({ status: 'verified', logs: WARNING })).toBe(true)
    expect(canViewRuleLogs({ status: 'verified', logs: [WARNING] })).toBe(true)
  })

  test('shows logs on a rule that is not verified, even when empty', () => {
    expect(canViewRuleLogs({ status: 'verifying', logs: '' })).toBe(true)
    expect(canViewRuleLogs({ status: 'unverified', logs: '' })).toBe(true)
    expect(canViewRuleLogs({ status: 'created', logs: '' })).toBe(true)
  })
})

describe('canRetryRule', () => {
  test('retries only a rule whose verification or certificate failed', () => {
    expect(canRetryRule('created')).toBe(true)
    expect(canRetryRule('unverified')).toBe(true)
    expect(canRetryRule('verifying')).toBe(false)
    expect(canRetryRule('verified')).toBe(false)
  })
})
