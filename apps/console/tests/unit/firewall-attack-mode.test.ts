import { describe, expect, test } from 'bun:test'
import { WafRuleAction } from '@appwrite.io/console'
import {
  ATTACK_MODE_PRIORITY,
  ATTACK_MODE_RULE_NAME,
  getAttackModeConditions,
  getAttackModeRuleId,
  isAttackModeRuleForResource,
  isAttackModeScope,
} from '@/lib/firewall/attack-mode'

describe('isAttackModeScope', () => {
  test('is only true for a selected site', () => {
    expect(isAttackModeScope('api')).toBe(false)
    expect(isAttackModeScope('functions', 'fn_1')).toBe(false)
    expect(isAttackModeScope('sites')).toBe(false)
    expect(isAttackModeScope('sites', '   ')).toBe(false)
    expect(isAttackModeScope('sites', 'site_1')).toBe(true)
  })
})

describe('getAttackModeRuleId', () => {
  test('is stable and unique per site', () => {
    const siteId = 'siteabcdefghijklmnopqrst'
    const otherSiteId = 'siteotherabcdefghijklmn'

    const siteRuleId = getAttackModeRuleId(siteId)
    const otherRuleId = getAttackModeRuleId(otherSiteId)

    expect(siteRuleId).toBe(getAttackModeRuleId(siteId))
    expect(siteRuleId).not.toBe(otherRuleId)
    expect(siteRuleId.length).toBeLessThanOrEqual(36)
    expect(siteRuleId.startsWith('atkS')).toBe(true)
  })
})

describe('isAttackModeRuleForResource', () => {
  test('matches the deterministic site rule', () => {
    const siteId = 'siteA'
    expect(
      isAttackModeRuleForResource(
        {
          $id: getAttackModeRuleId(siteId),
          action: WafRuleAction.Challenge,
          name: ATTACK_MODE_RULE_NAME,
          resourceType: 'sites',
          resourceId: siteId,
        },
        'sites',
        siteId,
      ),
    ).toBe(true)
  })

  test('rejects API, functions, and other sites', () => {
    const siteId = 'siteA'
    expect(
      isAttackModeRuleForResource(
        {
          $id: 'attackMode',
          action: WafRuleAction.Challenge,
          name: ATTACK_MODE_RULE_NAME,
          resourceType: 'api',
          resourceId: '',
        },
        'api',
      ),
    ).toBe(false)
    expect(
      isAttackModeRuleForResource(
        {
          $id: getAttackModeRuleId(siteId),
          action: WafRuleAction.Challenge,
          name: ATTACK_MODE_RULE_NAME,
          resourceType: 'sites',
          resourceId: siteId,
        },
        'sites',
        'siteB',
      ),
    ).toBe(false)
  })
})

describe('getAttackModeConditions', () => {
  test('serializes a path prefix that matches every request', () => {
    const conditions = getAttackModeConditions()
    expect(conditions).toHaveLength(1)
    expect(conditions[0]).toContain('path')
    expect(conditions[0]).toContain('/')
  })

  test('keeps Attack mode ahead of the default create priority', () => {
    expect(ATTACK_MODE_PRIORITY).toBeLessThan(100)
  })
})
