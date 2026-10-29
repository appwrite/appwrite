import { describe, expect, test } from 'bun:test'
import { canRetryRule, canViewRuleLogs } from '@/lib/utils/proxy-domains'

const ISSUED = 'SSL certificate successfully issued.'
const WARNING =
  'SSL certificate expires soon, and the certificate provider has not renewed it yet.'

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
