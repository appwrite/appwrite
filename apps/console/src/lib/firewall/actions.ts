import { WafRuleAction, type Models } from '@appwrite.io/console'
import type { FirewallRule } from '@/lib/firewall/types'

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

/** How a challenge rule tests visitors. */
export const FIREWALL_CHALLENGE_TYPES = [
  { value: 'compute', label: 'Compute' },
  { value: 'password', label: 'Password' },
] as const

export type FirewallChallengeType =
  (typeof FIREWALL_CHALLENGE_TYPES)[number]['value']

/** Password challenge length bounds, in bytes like the API counts them. */
export const CHALLENGE_PASSWORD_MIN_LENGTH = 8
export const CHALLENGE_PASSWORD_MAX_LENGTH = 256

/**
 * Whether a password challenge's password can be sent. Empty passes only when
 * it is not required: updating a rule that already has a password keeps it.
 */
export function isChallengePasswordValid(
  password: string,
  required: boolean,
): boolean {
  if (password === '') return !required
  const length = new TextEncoder().encode(password).length
  return (
    length >= CHALLENGE_PASSWORD_MIN_LENGTH &&
    length <= CHALLENGE_PASSWORD_MAX_LENGTH
  )
}

/** Wrong guesses one visitor may make per window, password challenges only. */
export const CHALLENGE_ATTEMPTS_MIN = 1
export const CHALLENGE_ATTEMPTS_MAX = 100
export const CHALLENGE_ATTEMPTS_DEFAULT = 10

/** Window the wrong guesses are counted over, in seconds (1 minute to 24 hours). */
export const CHALLENGE_ATTEMPTS_INTERVAL_MIN = 60
export const CHALLENGE_ATTEMPTS_INTERVAL_MAX = 86400
export const CHALLENGE_ATTEMPTS_INTERVAL_DEFAULT = 300

export type ChallengeRuleInput = {
  challengeType?: string
  difficulty?: number
  ttl?: number
  password?: string
  attempts?: number
  attemptsInterval?: number
}

/**
 * Whether a challenge form can be sent: the ranges the API checks, plus a
 * password when one is required (creating a password rule, or switching a
 * compute rule to password). Password rules apply to sites and functions only.
 */
export function isChallengeRuleInputValid(
  input: ChallengeRuleInput & {
    resourceType: string
    passwordRequired: boolean
  },
): boolean {
  const ttl = input.ttl ?? CHALLENGE_TTL_DEFAULT
  if (ttl < CHALLENGE_TTL_MIN || ttl > CHALLENGE_TTL_MAX) return false
  if (input.challengeType !== 'password') {
    const difficulty = input.difficulty ?? CHALLENGE_DIFFICULTY_DEFAULT
    return (
      difficulty >= CHALLENGE_DIFFICULTY_MIN &&
      difficulty <= CHALLENGE_DIFFICULTY_MAX
    )
  }
  const attempts = input.attempts ?? CHALLENGE_ATTEMPTS_DEFAULT
  const attemptsInterval =
    input.attemptsInterval ?? CHALLENGE_ATTEMPTS_INTERVAL_DEFAULT
  return (
    input.resourceType !== 'api' &&
    isChallengePasswordValid(input.password ?? '', input.passwordRequired) &&
    Number.isInteger(attempts) &&
    attempts >= CHALLENGE_ATTEMPTS_MIN &&
    attempts <= CHALLENGE_ATTEMPTS_MAX &&
    Number.isInteger(attemptsInterval) &&
    attemptsInterval >= CHALLENGE_ATTEMPTS_INTERVAL_MIN &&
    attemptsInterval <= CHALLENGE_ATTEMPTS_INTERVAL_MAX
  )
}

/**
 * Challenge fields for createChallengeRule / updateChallengeRule, built in one
 * place. Password rules carry no difficulty, and an empty password is left out
 * so an update keeps the stored one. Compute rules never carry the password
 * fields: the API rejects them.
 */
export function challengeRuleFields(input: ChallengeRuleInput) {
  if (input.challengeType === 'password') {
    return {
      challengeType: input.challengeType,
      ttl: input.ttl,
      password: input.password || undefined,
      attempts: input.attempts ?? CHALLENGE_ATTEMPTS_DEFAULT,
      attemptsInterval:
        input.attemptsInterval ?? CHALLENGE_ATTEMPTS_INTERVAL_DEFAULT,
    }
  }
  // challengeType is optional; omit to let the API apply its default.
  return {
    challengeType: input.challengeType?.trim() || undefined,
    difficulty: input.difficulty,
    ttl: input.ttl,
  }
}

/**
 * A password rule's guess limit for the rule list, like "10 guesses / 5 min".
 */
export function formatChallengeAttempts(
  attempts: number,
  attemptsInterval: number,
  t: (text: string) => string,
): string {
  const window =
    attemptsInterval % 3600 === 0
      ? t('{count} h').replace('{count}', String(attemptsInterval / 3600))
      : attemptsInterval % 60 === 0
        ? t('{count} min').replace('{count}', String(attemptsInterval / 60))
        : t('{count} sec').replace('{count}', String(attemptsInterval))
  return t(attempts === 1 ? '1 guess / {window}' : '{count} guesses / {window}')
    .replace('{count}', String(attempts))
    .replace('{window}', window)
}

/** Keys a rate-limit rule can be bucketed by. */
export const FIREWALL_RATE_LIMIT_KEYS = [
  { value: 'ip', label: 'IP address' },
  { value: 'userId', label: 'User ID' },
] as const

export type FirewallRateLimitKey =
  (typeof FIREWALL_RATE_LIMIT_KEYS)[number]['value']

export const FIREWALL_RATE_LIMIT_KEY_DEFAULT: FirewallRateLimitKey = 'ip'

/** Rate-limit window length bounds, in seconds (Console allows 1s to 24h). */
export const FIREWALL_RATE_LIMIT_INTERVAL_MIN = 1
export const FIREWALL_RATE_LIMIT_INTERVAL_MAX = 86400

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
  rule: FirewallRule,
): rule is Models.WafRuleRateLimit {
  return rule.action === WafRuleAction.RateLimit
}

export function isRedirectRule(
  rule: FirewallRule,
): rule is Models.WafRuleRedirect {
  return rule.action === WafRuleAction.Redirect
}

export function isChallengeRule(
  rule: FirewallRule,
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

export function getRuleRateLimit(rule: FirewallRule): {
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

export function getRuleChallenge(rule: FirewallRule): {
  challengeType: string
  difficulty: number
  ttl: number
  attempts: number
  attemptsInterval: number
} | null {
  const config = readRuleConfig(rule)
  // Rules keep their settings under config; the challenge type is `type` there.
  const fromRule = (key: string): unknown =>
    isChallengeRule(rule)
      ? (rule as unknown as Record<string, unknown>)[key]
      : undefined
  const challengeType = [
    fromRule('challengeType'),
    config?.challengeType,
    config?.type,
  ].find((value): value is string => typeof value === 'string' && value !== '')
  const attempts =
    toFiniteNumber(fromRule('attempts')) ??
    toFiniteNumber(config?.attempts) ??
    CHALLENGE_ATTEMPTS_DEFAULT
  const attemptsInterval =
    toFiniteNumber(fromRule('attemptsInterval')) ??
    toFiniteNumber(config?.attemptsInterval) ??
    CHALLENGE_ATTEMPTS_INTERVAL_DEFAULT

  if (!isChallengeRule(rule)) {
    if (!config) return null
    const difficulty = toFiniteNumber(config.difficulty)
    const ttl = toFiniteNumber(config.ttl)
    if (ttl == null || (difficulty == null && challengeType !== 'password')) {
      return null
    }
    return {
      challengeType: challengeType ?? '',
      difficulty: difficulty ?? CHALLENGE_DIFFICULTY_DEFAULT,
      ttl,
      attempts,
      attemptsInterval,
    }
  }

  return {
    challengeType: challengeType ?? '',
    difficulty:
      toFiniteNumber(fromRule('difficulty')) ??
      toFiniteNumber(config?.difficulty) ??
      CHALLENGE_DIFFICULTY_DEFAULT,
    ttl:
      toFiniteNumber(fromRule('ttl')) ??
      toFiniteNumber(config?.ttl) ??
      CHALLENGE_TTL_DEFAULT,
    attempts,
    attemptsInterval,
  }
}

export function getRuleRedirect(
  rule: FirewallRule,
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

function readRuleConfig(rule: FirewallRule): Record<string, unknown> | null {
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
