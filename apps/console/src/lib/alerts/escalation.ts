import { formatLength } from '@/lib/alerts/metrics'
import type { Alert, EscalationStep } from '@/lib/appwrite/monitors'

/** Roles a step can notify; the server validates them against its own list. */
export const ESCALATION_ROLES = ['owner', 'developer']

/** Most rounds a policy can add after the first. */
export const MAX_REPEAT = 9

/** Most seconds a step can wait: 30 days. */
export const MAX_DELAY = 2592000

/** How a step's wait reads: `now`, or `after 10 m`. */
export function delayLabel(delay: number, t: (text: string) => string): string {
  return delay === 0
    ? t('now')
    : t('after {delay}').replace('{delay}', formatLength(delay))
}

/** Who a step notifies, roles first: `Developers, Ana`. */
export function stepAudience(
  step: EscalationStep,
  memberName: (userId: string) => string,
  roleLabel: (role: string) => string,
): string {
  return [...step.roles.map(roleLabel), ...step.userIds.map(memberName)].join(
    ', ',
  )
}

/** Where an escalating alert stands; null when the alert does not escalate. */
export function escalationState(
  alert: Alert,
): 'waiting' | 'acknowledged' | 'resolved' | 'finished' | null {
  if (!alert.escalationPolicyId) return null
  if (alert.acknowledgedAt) return 'acknowledged'
  if (alert.escalatesAt) return 'waiting'
  if (alert.status === 'resolved') return 'resolved'
  return 'finished'
}

/** A policy's steps in one line: `Developers now → Ana, Ben after 10 m`. */
export function policySummary(
  steps: EscalationStep[],
  memberName: (userId: string) => string,
  roleLabel: (role: string) => string,
  t: (text: string) => string,
): string {
  return steps
    .map(
      (step) =>
        `${stepAudience(step, memberName, roleLabel)} ${delayLabel(step.delay, t)}`,
    )
    .join(' → ')
}
