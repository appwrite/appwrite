import { WafRuleAction, type Models } from '@appwrite.io/console'
import {
  serializeFirewallConditions,
  type FirewallConditionDraft,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'

/** Display name stored on the catch-all challenge rule Attack mode creates. */
export const ATTACK_MODE_RULE_NAME = 'Attack mode'

/** Description stored on the catch-all challenge rule Attack mode creates. */
export const ATTACK_MODE_RULE_DESCRIPTION =
  'Automatically created to challenge all requests while attack mode is on.'

/**
 * Priority for the Attack mode rule. Lower numbers run first, so `0` is
 * evaluated before the create-wizard default (`100`) and typical stacked
 * policies (`10`, `20`, …). Bypass rules with a negative priority still apply.
 */
export const ATTACK_MODE_PRIORITY = 0

/**
 * Catch-all condition: every path starts with `/` (empty paths are stored as
 * `/` server-side), so the rule matches all requests for its site.
 */
export const ATTACK_MODE_CONDITION: FirewallConditionDraft = {
  id: 'attack-mode',
  attribute: 'path',
  operator: 'startsWith',
  value: '/',
}

export function getAttackModeConditions(): string[] {
  return serializeFirewallConditions([ATTACK_MODE_CONDITION])
}

export function isAttackModeScope(
  resourceType: FirewallResourceType | undefined,
  resourceId?: string,
): resourceId is string {
  return resourceType === 'sites' && !!resourceId?.trim()
}

/** FNV-1a 32-bit, hex padded to 8 characters. */
function fnv1a32Hex(input: string): string {
  let hash = 0x811c9dc5
  for (let i = 0; i < input.length; i++) {
    hash ^= input.charCodeAt(i)
    hash = Math.imul(hash, 0x01000193)
  }
  return (hash >>> 0).toString(16).padStart(8, '0')
}

/**
 * Stable rule ID for the Attack mode challenge on a site.
 * Hashes the site id so the value stays within Appwrite's 36-character
 * custom-ID limit.
 */
export function getAttackModeRuleId(siteId: string): string {
  const id = siteId.trim()
  return `atkS${fnv1a32Hex(id)}${fnv1a32Hex(`firewall:sites:${id}`)}`
}

export function isAttackModeRuleForResource(
  rule: Pick<
    Models.WafRule,
    '$id' | 'action' | 'name' | 'resourceType' | 'resourceId'
  >,
  resourceType: FirewallResourceType,
  resourceId?: string,
): boolean {
  if (!isAttackModeScope(resourceType, resourceId)) return false
  if (rule.action !== WafRuleAction.Challenge) return false
  if ((rule.resourceType || 'api') !== 'sites') return false

  const expectedId = resourceId?.trim() || ''
  const actualId = rule.resourceId?.trim() || ''
  if (actualId !== expectedId) return false

  return (
    rule.$id === getAttackModeRuleId(expectedId) ||
    rule.name === ATTACK_MODE_RULE_NAME
  )
}
