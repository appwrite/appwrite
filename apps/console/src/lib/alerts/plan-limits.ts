import type { Models } from '@appwrite.io/console'
import type { EscalationStep } from '@/lib/appwrite/monitors'

/**
 * Alerting fields of a billing plan. Console SDK releases that predate them
 * leave them undefined, which reads as uncapped, as the API reads a missing key.
 */
type AlertingPlan = Models.BillingPlan & {
  probes?: number
  probeInterval?: number
  escalations?: number
  escalationSteps?: number
  escalationRepeat?: number | null
}

export type AlertingLimits = {
  /** Synthetic checks the organization may hold; null when uncapped. */
  probes: number | null
  /** Shortest seconds between two checks of one synthetic check; 0 when the plan sets none. */
  probeInterval: number
  /** Escalations the organization may hold; null when uncapped. */
  escalations: number | null
  /** Steps one escalation may run; null when uncapped. */
  escalationSteps: number | null
  /** Most times an escalation's steps run again; null when uncapped. */
  escalationRepeat: number | null
}

/** 0 or a missing count means the plan does not cap it. */
function cap(value: number | undefined): number | null {
  return value && value > 0 ? value : null
}

export function alertingLimits(
  plan: Models.BillingPlan | null | undefined,
): AlertingLimits {
  const alerting = (plan ?? {}) as Partial<AlertingPlan>
  return {
    probes: cap(alerting.probes),
    probeInterval: alerting.probeInterval ?? 0,
    escalations: cap(alerting.escalations),
    escalationSteps: cap(alerting.escalationSteps),
    escalationRepeat: alerting.escalationRepeat ?? null,
  }
}

/** The interval a new synthetic check starts on: a minute, or the plan's minimum when longer, as the API defaults it. */
export function defaultProbeInterval(minimum: number): number {
  return Math.max(60, minimum)
}

/**
 * On-call responders the escalations notify: confirmed members a step names
 * by role or by user, each counted once.
 */
export function countResponders(
  escalations: { steps: EscalationStep[] }[],
  members: { userId: string; roles: string[] }[],
): number {
  const steps = escalations.flatMap((escalation) => escalation.steps)
  const roles = new Set(steps.flatMap((step) => step.roles))
  const userIds = new Set(steps.flatMap((step) => step.userIds))
  return members.filter(
    (member) =>
      userIds.has(member.userId) ||
      member.roles.some((role) => roles.has(role)),
  ).length
}
