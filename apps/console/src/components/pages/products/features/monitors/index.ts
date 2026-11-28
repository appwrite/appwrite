import type { ComponentType } from 'react'
import { MonitorsAgentsVisual } from './MonitorsAgentsVisual'
import { MonitorsAlertVisual } from './MonitorsAlertVisual'
import { MonitorsBudgetVisual } from './MonitorsBudgetVisual'
import { MonitorsEndpointsVisual } from './MonitorsEndpointsVisual'
import { MonitorsEscalationVisual } from './MonitorsEscalationVisual'
import { MonitorsEventsVisual } from './MonitorsEventsVisual'
import { MonitorsMetricsCatalog } from './MonitorsMetricsCatalog'
import { MonitorsNotifyVisual } from './MonitorsNotifyVisual'
import { MonitorsSuggestionsVisual } from './MonitorsSuggestionsVisual'
import { MonitorsThresholdVisual } from './MonitorsThresholdVisual'

/** Feature visuals by feature id. `metrics` uses the companion catalog instead. */
export const MONITORS_FEATURE_VISUALS: Record<string, ComponentType> = {
  suggestions: MonitorsSuggestionsVisual,
  threshold: MonitorsThresholdVisual,
  endpoints: MonitorsEndpointsVisual,
  alert: MonitorsAlertVisual,
  escalations: MonitorsEscalationVisual,
  notify: MonitorsNotifyVisual,
  events: MonitorsEventsVisual,
  agents: MonitorsAgentsVisual,
  budget: MonitorsBudgetVisual,
}

/** Wide companions for stacked sections, by feature id. */
export const MONITORS_FEATURE_COMPANIONS: Record<string, ComponentType> = {
  metrics: MonitorsMetricsCatalog,
}
