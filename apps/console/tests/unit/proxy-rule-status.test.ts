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

  test('keeps a plain Verified badge for a healthy certificate, even close to expiry', () => {
    // Renewal is Appwrite's job: nothing to act on, so no warning and no date.
    for (const certificateStatus of [
      'issued',
      'renewing',
      'expiring',
      'unknown',
      '',
    ]) {
      for (const remaining of [20 * DAY_MS, DAY_MS]) {
        expect(
          getRuleStatusBadgeConfig(
            {
              status: 'verified',
              expireAt: expiresIn(remaining),
              certificateStatus,
            },
            NOW,
          ),
        ).toEqual({ variant: 'success', label: 'Verified' })
      }
    }
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

  test('shows a failed renewal with when the certificate expires', () => {
    const expireAt = expiresIn(DAY_MS)
    const config = getRuleStatusBadgeConfig(
      { status: 'verified', expireAt, certificateStatus: 'failed' },
      NOW,
    )
    expect(config).toMatchObject({ variant: 'error', label: 'Renewal failed' })
    expect(config.hint).toBeDefined()
    expect(config.expireAt?.toISOString()).toBe(expireAt)

    const unknownExpiry = getRuleStatusBadgeConfig(
      { status: 'verified', certificateStatus: 'failed' },
      NOW,
    )
    expect(unknownExpiry).toMatchObject({
      variant: 'error',
      label: 'Renewal failed',
    })
    expect(unknownExpiry.expireAt).toBeUndefined()
  })

  test('shows a blocked renewal with when the certificate expires', () => {
    const expireAt = expiresIn(20 * DAY_MS)
    const config = getRuleStatusBadgeConfig(
      { status: 'verified', expireAt, certificateStatus: 'blocked' },
      NOW,
    )
    expect(config).toMatchObject({
      variant: 'warning',
      label: 'Renewal blocked',
    })
    expect(config.hint).toBeDefined()
    expect(config.expireAt?.toISOString()).toBe(expireAt)
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
