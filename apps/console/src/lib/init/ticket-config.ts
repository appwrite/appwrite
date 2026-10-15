import type { InitEventTicketConfig } from '@/lib/init/ticket-types'
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

/** Init Sep 2026 - same ticket types as July until event-specific rules are added. */
export const INIT_SEP_2026_TICKET_CONFIG: InitEventTicketConfig = {
  rules: [
    { typeId: 'gold', when: INIT_TICKET_MATCHERS.appwriteVerified },
    { typeId: 'silver', when: INIT_TICKET_MATCHERS.longTermVip },
    { typeId: 'standard', when: INIT_TICKET_MATCHERS.always },
  ],
}

const INIT_EVENT_TICKET_CONFIGS: Record<string, InitEventTicketConfig> = {
  'init-july-2026': INIT_JULY_2026_TICKET_CONFIG,
  'init-sep-2026': INIT_SEP_2026_TICKET_CONFIG,
}

export function getInitEventTicketConfig(eventId: string): InitEventTicketConfig {
  return INIT_EVENT_TICKET_CONFIGS[eventId] ?? DEFAULT_INIT_EVENT_TICKET_CONFIG
}
