import { WafRuleAction } from '@appwrite.io/console'
import type { FirewallCreatableAction } from '@/lib/firewall/actions'
import {
  FIREWALL_CHALLENGE_SOLVED_METRIC,
  FIREWALL_DENIED_METRIC,
  FIREWALL_RATE_LIMITED_METRIC,
  FIREWALL_REDIRECTED_METRIC,
} from '@/lib/usage/firewall-events'

/** WAF usage metric that best represents each rule action's activity. */
export type FirewallActionMetricConfig = {
  /** Primary count metric for the action. */
  metric: string
  /** i18n key for the count series label. */
  label: string
}

/**
 * Per-action WAF activity metric. `bypass` has no dedicated counter, so it is
 * intentionally absent (no activity graph is shown for bypass rules).
 */
export const FIREWALL_ACTION_METRIC: Partial<
  Record<FirewallCreatableAction, FirewallActionMetricConfig>
> = {
  [WafRuleAction.Deny]: { metric: FIREWALL_DENIED_METRIC, label: 'Denied' },
  [WafRuleAction.RateLimit]: {
    metric: FIREWALL_RATE_LIMITED_METRIC,
    label: 'Rate limited',
  },
  [WafRuleAction.Redirect]: {
    metric: FIREWALL_REDIRECTED_METRIC,
    label: 'Redirected',
  },
  [WafRuleAction.Challenge]: {
    metric: FIREWALL_CHALLENGE_SOLVED_METRIC,
    label: 'Challenge solves',
  },
}

export function getFirewallActionMetric(
  action: FirewallCreatableAction | undefined,
): FirewallActionMetricConfig | undefined {
  return action ? FIREWALL_ACTION_METRIC[action] : undefined
}
