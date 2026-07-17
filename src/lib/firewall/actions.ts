import { WafRuleAction, type Models } from '@appwrite.io/console'

/** Actions that can be created via the Console SDK today. */
export const FIREWALL_CREATABLE_ACTIONS = [
  WafRuleAction.Deny,
  WafRuleAction.Bypass,
  WafRuleAction.RateLimit,
  WafRuleAction.Redirect,
] as const

export type FirewallCreatableAction =
  (typeof FIREWALL_CREATABLE_ACTIONS)[number]

export function getFirewallActionLabel(action: string): string {
  switch (action) {
    case WafRuleAction.Deny:
      return 'Deny'
    case WafRuleAction.Bypass:
      return 'Bypass'
    case WafRuleAction.RateLimit:
      return 'Rate limit'
    case WafRuleAction.Redirect:
      return 'Redirect'
    case WafRuleAction.Challenge:
      return 'Challenge'
    default:
      return action
  }
}

export function getFirewallActionDescription(action: string): string {
  switch (action) {
    case WafRuleAction.Deny:
      return 'Reject matching requests before they reach your project.'
    case WafRuleAction.Bypass:
      return 'Skip remaining firewall checks for matching requests.'
    case WafRuleAction.RateLimit:
      return 'Throttle matching requests that exceed a request quota.'
    case WafRuleAction.Redirect:
      return 'Send matching requests to another location.'
    case WafRuleAction.Challenge:
      return 'Challenge matching requests before allowing them through.'
    default:
      return ''
  }
}

export function isRateLimitRule(
  rule: Models.WafRule,
): rule is Models.WafRuleRateLimit {
  return rule.action === WafRuleAction.RateLimit
}

export function isRedirectRule(
  rule: Models.WafRule,
): rule is Models.WafRuleRedirect {
  return rule.action === WafRuleAction.Redirect
}

export function getRuleRateLimit(
  rule: Models.WafRule,
): { limit: number; interval: number } | null {
  if (
    isRateLimitRule(rule) &&
    typeof rule.limit === 'number' &&
    typeof rule.interval === 'number'
  ) {
    return { limit: rule.limit, interval: rule.interval }
  }

  const config = readRuleConfig(rule)
  if (!config) return null

  const limit = toFiniteNumber(config.limit)
  const interval = toFiniteNumber(config.interval)
  if (limit == null || interval == null) return null

  return { limit, interval }
}

export function getRuleRedirect(
  rule: Models.WafRule,
): { location: string; statusCode: number } | null {
  if (
    isRedirectRule(rule) &&
    typeof rule.location === 'string' &&
    rule.location.length > 0 &&
    typeof rule.statusCode === 'number'
  ) {
    return { location: rule.location, statusCode: rule.statusCode }
  }

  // API returns redirect settings nested under config (location, statusCode).
  const config = readRuleConfig(rule)
  if (!config) return null

  const location =
    typeof config.location === 'string' ? config.location.trim() : ''
  const statusCode = toFiniteNumber(config.statusCode)
  if (!location || statusCode == null) return null

  return { location, statusCode }
}

function readRuleConfig(
  rule: Models.WafRule,
): Record<string, unknown> | null {
  const config = rule.config
  if (!config || typeof config !== 'object' || Array.isArray(config)) {
    return null
  }
  return config as Record<string, unknown>
}

function toFiniteNumber(value: unknown): number | null {
  if (typeof value === 'number' && Number.isFinite(value)) return value
  if (typeof value === 'string' && value.trim() !== '') {
    const parsed = Number(value)
    if (Number.isFinite(parsed)) return parsed
  }
  return null
}
