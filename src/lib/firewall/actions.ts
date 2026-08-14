import { WafRuleAction, type Models } from '@appwrite.io/console'

/** Actions that can be created via the Console SDK today. */
export const FIREWALL_CREATABLE_ACTIONS = [
  WafRuleAction.Deny,
  WafRuleAction.Bypass,
  WafRuleAction.Challenge,
  WafRuleAction.RateLimit,
  WafRuleAction.Redirect,
] as const

export type FirewallCreatableAction =
  (typeof FIREWALL_CREATABLE_ACTIONS)[number]

/** Challenge difficulty bounds (1 easiest → 5 hardest). */
export const CHALLENGE_DIFFICULTY_MIN = 1
export const CHALLENGE_DIFFICULTY_MAX = 5
export const CHALLENGE_DIFFICULTY_DEFAULT = 3

/** Challenge TTL bounds, in seconds (15 minutes → 24 hours). */
export const CHALLENGE_TTL_MIN = 900
export const CHALLENGE_TTL_MAX = 86400
export const CHALLENGE_TTL_DEFAULT = 1800

/** Keys a rate-limit rule can be bucketed by. */
export const FIREWALL_RATE_LIMIT_KEYS = [
  { value: 'ip', label: 'IP address' },
  { value: 'userId', label: 'User ID' },
] as const

export type FirewallRateLimitKey =
  (typeof FIREWALL_RATE_LIMIT_KEYS)[number]['value']

export const FIREWALL_RATE_LIMIT_KEY_DEFAULT: FirewallRateLimitKey = 'ip'

/** Rate-limiting algorithms a rate-limit rule can use. */
export const FIREWALL_RATE_LIMIT_STRATEGIES = [
  { value: 'fixedWindow', label: 'Fixed window' },
  { value: 'slidingWindow', label: 'Sliding window' },
  { value: 'tokenBucket', label: 'Token bucket' },
] as const

export type FirewallRateLimitStrategy =
  (typeof FIREWALL_RATE_LIMIT_STRATEGIES)[number]['value']

export const FIREWALL_RATE_LIMIT_STRATEGY_DEFAULT: FirewallRateLimitStrategy =
  'fixedWindow'

/** Token-bucket burst capacity bounds. */
export const MAX_BUCKET_SIZE_MIN = 1
export const MAX_BUCKET_SIZE_MAX = 1000000

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

/**
 * Single source of truth for firewall action colors. Each action must look
 * the same everywhere it appears: traffic chart series, chart legends,
 * action dropdown dots, and rule badges. Chart colors are the Tailwind 500
 * hex of the same hue used by the dot and badge classes.
 */
type FirewallActionColors = {
  /** Concrete color for chart strokes, gradients, and inline-styled legend dots. */
  chart: string
  /** Solid dot class for dropdown items and menus. */
  dot: string
  /** Tinted badge classes (background + text), matching the status badge design. */
  badge: string
}

const FIREWALL_ACTION_COLORS: Record<string, FirewallActionColors> = {
  [WafRuleAction.Deny]: {
    chart: '#ef4444', // red-500
    dot: 'bg-red-500',
    badge: 'bg-red-500/10 text-red-600 dark:text-red-400',
  },
  [WafRuleAction.Bypass]: {
    chart: '#3b82f6', // blue-500
    dot: 'bg-blue-500',
    badge: 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
  },
  [WafRuleAction.Challenge]: {
    chart: '#8b5cf6', // violet-500
    dot: 'bg-violet-500',
    badge: 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
  },
  [WafRuleAction.RateLimit]: {
    chart: '#f59e0b', // amber-500
    dot: 'bg-amber-500',
    badge: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
  },
  [WafRuleAction.Redirect]: {
    chart: '#64748b', // slate-500
    dot: 'bg-slate-500',
    badge: 'bg-slate-500/10 text-slate-600 dark:text-slate-400',
  },
}

const FIREWALL_ACTION_COLORS_FALLBACK: FirewallActionColors = {
  chart: 'var(--muted-foreground)',
  dot: 'bg-muted-foreground',
  badge: 'bg-muted text-foreground/80 dark:text-muted-foreground',
}

/** "Passed" traffic is not a WAF action but shares the chart with them (emerald-500). */
export const FIREWALL_PASSED_CHART_COLOR = '#10b981'

export function getFirewallActionChartColor(action: string): string {
  return (FIREWALL_ACTION_COLORS[action] ?? FIREWALL_ACTION_COLORS_FALLBACK)
    .chart
}

export function getFirewallActionDotClass(action: string): string {
  return (FIREWALL_ACTION_COLORS[action] ?? FIREWALL_ACTION_COLORS_FALLBACK).dot
}

export function getFirewallActionBadgeClass(action: string): string {
  return (FIREWALL_ACTION_COLORS[action] ?? FIREWALL_ACTION_COLORS_FALLBACK)
    .badge
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

export function isChallengeRule(
  rule: Models.WafRule,
): rule is Models.WafRuleChallenge {
  return rule.action === WafRuleAction.Challenge
}

function normalizeRateLimitKey(value: unknown): FirewallRateLimitKey {
  return FIREWALL_RATE_LIMIT_KEYS.some((k) => k.value === value)
    ? (value as FirewallRateLimitKey)
    : FIREWALL_RATE_LIMIT_KEY_DEFAULT
}

function normalizeRateLimitStrategy(value: unknown): FirewallRateLimitStrategy {
  return FIREWALL_RATE_LIMIT_STRATEGIES.some((s) => s.value === value)
    ? (value as FirewallRateLimitStrategy)
    : FIREWALL_RATE_LIMIT_STRATEGY_DEFAULT
}

export function getRuleRateLimit(rule: Models.WafRule): {
  limit: number
  interval: number
  key: FirewallRateLimitKey
  strategy: FirewallRateLimitStrategy
  maxBucketSize: number
} | null {
  if (
    isRateLimitRule(rule) &&
    typeof rule.limit === 'number' &&
    typeof rule.interval === 'number'
  ) {
    return {
      limit: rule.limit,
      interval: rule.interval,
      key: normalizeRateLimitKey(rule.key),
      strategy: normalizeRateLimitStrategy(rule.strategy),
      maxBucketSize: toFiniteNumber(rule.maxBucketSize) ?? 0,
    }
  }

  const config = readRuleConfig(rule)
  if (!config) return null

  const limit = toFiniteNumber(config.limit)
  const interval = toFiniteNumber(config.interval)
  if (limit == null || interval == null) return null

  return {
    limit,
    interval,
    key: normalizeRateLimitKey(config.key),
    strategy: normalizeRateLimitStrategy(config.strategy),
    maxBucketSize: toFiniteNumber(config.maxBucketSize) ?? 0,
  }
}

export function getRuleChallenge(rule: Models.WafRule): {
  challengeType: string
  difficulty: number
  ttl: number
} | null {
  if (!isChallengeRule(rule)) {
    const config = readRuleConfig(rule)
    if (!config) return null
    const difficulty = toFiniteNumber(config.difficulty)
    const ttl = toFiniteNumber(config.ttl)
    if (difficulty == null || ttl == null) return null
    return {
      challengeType:
        typeof config.challengeType === 'string' ? config.challengeType : '',
      difficulty,
      ttl,
    }
  }

  const config = readRuleConfig(rule)
  const difficulty =
    typeof rule.difficulty === 'number'
      ? rule.difficulty
      : (toFiniteNumber(config?.difficulty) ?? CHALLENGE_DIFFICULTY_DEFAULT)
  const ttl =
    typeof rule.ttl === 'number'
      ? rule.ttl
      : (toFiniteNumber(config?.ttl) ?? CHALLENGE_TTL_DEFAULT)

  return {
    challengeType: typeof rule.challengeType === 'string' ? rule.challengeType : '',
    difficulty,
    ttl,
  }
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
