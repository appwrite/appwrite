import {
  INIT_PRESENCE_ACTIVITY_ON_INIT,
  buildInitExploringActivity,
  buildInitExploringGlobeActivity,
  buildInitPreEventBaselineActivity,
  buildInitViewingTicketActivity,
} from '@/lib/init/init-presence-activity'
import {
  buildInitDayReadingActivity,
  resolveInitScrollSpyBaselineDay,
} from '@/lib/init/init-presence-day-activity'
import { getInitDayCardId } from '@/lib/init/scroll-to-day-card'
import {
  INIT_GET_INVOLVED_SECTION_ID,
  INIT_GLOBE_SECTION_ID,
  INIT_PRIZES_SECTION_ID,
  INIT_TICKET_SECTION_ID,
} from '@/lib/init/init-section-ids'
import type { InitDisplayEvent } from '@/lib/init/types'
import { INIT_DAY_SCROLL_SPY_OFFSET_PX } from '@/lib/init/use-init-scroll-spy-day'

export type InitPresenceBaselineZone =
  | { kind: 'default' }
  | { kind: 'ticket' }
  | { kind: 'day'; day: number }
  | { kind: 'prizes' }
  | { kind: 'globe' }
  | { kind: 'get-involved' }

type BaselineAnchor = {
  zone: InitPresenceBaselineZone
  id: string
}

function buildBaselineAnchors(options: {
  dayNumbers: number[]
  hasPrizes: boolean
  hasGlobe: boolean
}): BaselineAnchor[] {
  const anchors: BaselineAnchor[] = [
    { zone: { kind: 'ticket' }, id: INIT_TICKET_SECTION_ID },
  ]

  for (const day of [...options.dayNumbers].sort((a, b) => a - b)) {
    anchors.push({ zone: { kind: 'day', day }, id: getInitDayCardId(day) })
  }

  if (options.hasPrizes) {
    anchors.push({ zone: { kind: 'prizes' }, id: INIT_PRIZES_SECTION_ID })
  }

  if (options.hasGlobe) {
    anchors.push({ zone: { kind: 'globe' }, id: INIT_GLOBE_SECTION_ID })
  }

  anchors.push({ zone: { kind: 'get-involved' }, id: INIT_GET_INVOLVED_SECTION_ID })

  return anchors
}

/** Last page section whose top has crossed the scroll-spy anchor line. */
export function resolveInitPresenceBaselineZone(
  scrollRoot: HTMLElement,
  options: {
    dayNumbers: number[]
    hasPrizes: boolean
    hasGlobe: boolean
  },
): InitPresenceBaselineZone {
  const anchorY =
    scrollRoot.getBoundingClientRect().top + INIT_DAY_SCROLL_SPY_OFFSET_PX

  let zone: InitPresenceBaselineZone = { kind: 'default' }

  for (const entry of buildBaselineAnchors(options)) {
    const element = document.getElementById(entry.id)
    if (!element) continue
    if (element.getBoundingClientRect().top <= anchorY) {
      zone = entry.zone
    }
  }

  return zone
}

export function resolveInitPresenceBaselineActivity(
  event: InitDisplayEvent,
  zone: InitPresenceBaselineZone,
  seed: string,
): string {
  switch (zone.kind) {
    case 'globe':
      return buildInitExploringGlobeActivity()
    case 'prizes':
      return buildInitExploringActivity(
        event.prizes?.sectionTitle ?? 'Prizes and giveaways',
      )
    case 'ticket':
      return buildInitViewingTicketActivity()
    case 'get-involved':
      return buildInitExploringActivity(
        event.isRecapMode
          ? (event.recap?.getInvolvedSectionTitle ?? 'Keep exploring')
          : 'Ways to get involved',
      )
    case 'day': {
      const dayView = resolveInitScrollSpyBaselineDay(
        event.days,
        zone.day,
        event.currentDay,
      )

      if (!dayView) {
        return event.currentDay <= 0
          ? buildInitPreEventBaselineActivity(seed)
          : INIT_PRESENCE_ACTIVITY_ON_INIT
      }

      return buildInitDayReadingActivity(
        dayView,
        event.currentDay,
        `${seed}:day-${dayView.day}`,
      )
    }
    case 'default':
    default:
      return event.currentDay <= 0
        ? buildInitPreEventBaselineActivity(seed)
        : INIT_PRESENCE_ACTIVITY_ON_INIT
  }
}
