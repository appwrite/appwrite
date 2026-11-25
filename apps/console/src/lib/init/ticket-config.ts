import type { InitEventTicketConfig } from '@/lib/init/ticket-types'
import {
  INIT_5_TICKET_BG_SRC_DARK,
  INIT_5_TICKET_BG_SRC_GOLD,
  INIT_5_TICKET_BG_SRC_LIGHT,
  INIT_5_TICKET_BG_SRC_SILVER,
} from '@/lib/init/ticket-layout'
import {
  DEFAULT_INIT_EVENT_TICKET_CONFIG,
  INIT_TICKET_MATCHERS,
} from '@/lib/init/ticket-types'

/** Init July 2026 - gold for @appwrite.io contributors, silver for 3+ year members, then standard. */
export const INIT_JULY_2026_TICKET_CONFIG: InitEventTicketConfig = {
  rules: [
    { typeId: 'gold', when: INIT_TICKET_MATCHERS.appwriteVerified },
    { typeId: 'silver', when: INIT_TICKET_MATCHERS.longTermVip },
    { typeId: 'standard', when: INIT_TICKET_MATCHERS.always },
  ],
}

/** Init Nov 2026 (Init 5) - July's ticket types on the hero's light-trail 5. */
export const INIT_NOV_2026_TICKET_CONFIG: InitEventTicketConfig = {
  rules: [
    { typeId: 'gold', when: INIT_TICKET_MATCHERS.appwriteVerified },
    { typeId: 'silver', when: INIT_TICKET_MATCHERS.longTermVip },
    { typeId: 'standard', when: INIT_TICKET_MATCHERS.always },
  ],
  artwork: {
    standard: {
      themeBackgroundSrcLight: INIT_5_TICKET_BG_SRC_LIGHT,
      themeBackgroundSrcDark: INIT_5_TICKET_BG_SRC_DARK,
    },
    silver: { backgroundSrc: INIT_5_TICKET_BG_SRC_SILVER },
    gold: { backgroundSrc: INIT_5_TICKET_BG_SRC_GOLD },
  },
}

/** Init Sep 2026 - same ticket types as July until event-specific rules are added. */
const INIT_EVENT_TICKET_CONFIGS: Record<string, InitEventTicketConfig> = {
  'init-july-2026': INIT_JULY_2026_TICKET_CONFIG,
  'init-nov-2026': INIT_NOV_2026_TICKET_CONFIG,
}

export function getInitEventTicketConfig(
  eventId: string,
): InitEventTicketConfig {
  return INIT_EVENT_TICKET_CONFIGS[eventId] ?? DEFAULT_INIT_EVENT_TICKET_CONFIG
}
