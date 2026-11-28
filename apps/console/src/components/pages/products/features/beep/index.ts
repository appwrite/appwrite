import type { ComponentType } from 'react'
import { BeepAlertVisual } from './BeepAlertVisual'
import { BeepCriticalVisual } from './BeepCriticalVisual'
import { BeepDevicesCatalog } from './BeepDevicesCatalog'
import { BeepEscalationVisual } from './BeepEscalationVisual'
import { BeepHomeVisual } from './BeepHomeVisual'
import { BeepProjectVisual } from './BeepProjectVisual'
import { BeepPushVisual } from './BeepPushVisual'
import { BeepSettingsVisual } from './BeepSettingsVisual'
import { BeepSnoozeVisual } from './BeepSnoozeVisual'
import { BeepUpdatesVisual } from './BeepUpdatesVisual'

/** Feature visuals by feature id. `devices` uses the companion catalog instead. */
export const BEEP_FEATURE_VISUALS: Record<string, ComponentType> = {
  push: BeepPushVisual,
  alerts: BeepAlertVisual,
  escalations: BeepEscalationVisual,
  home: BeepHomeVisual,
  projects: BeepProjectVisual,
  choose: BeepSettingsVisual,
  snooze: BeepSnoozeVisual,
  critical: BeepCriticalVisual,
  updates: BeepUpdatesVisual,
}

/** Wide companions for stacked sections, by feature id. */
export const BEEP_FEATURE_COMPANIONS: Record<string, ComponentType> = {
  devices: BeepDevicesCatalog,
}
