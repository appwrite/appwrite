import type { ComponentType } from 'react'
import { FirewallConditionsVisual } from './FirewallConditionsVisual'
import { FirewallImpactVisual } from './FirewallImpactVisual'
import { FirewallPriorityVisual } from './FirewallPriorityVisual'
import { FirewallScopesVisual } from './FirewallScopesVisual'

export const FIREWALL_FEATURE_VISUALS: Record<string, ComponentType> = {
  conditions: FirewallConditionsVisual,
  scopes: FirewallScopesVisual,
  priority: FirewallPriorityVisual,
  impact: FirewallImpactVisual,
}
