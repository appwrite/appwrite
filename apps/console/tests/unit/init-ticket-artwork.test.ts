import { describe, expect, test } from 'bun:test'
import {
  INIT_JULY_2026_TICKET_CONFIG,
  INIT_NOV_2026_TICKET_CONFIG,
} from '@/lib/init/ticket-config'
import {
  INIT_5_TICKET_BG_SRC_DARK,
  INIT_5_TICKET_BG_SRC_GOLD,
  INIT_5_TICKET_BG_SRC_LIGHT,
  INIT_5_TICKET_BG_SRC_SILVER,
  INIT_TICKET_BG_SRC_GOLD,
} from '@/lib/init/ticket-layout'
import { resolveInitTicketAppearance } from '@/lib/init/ticket-types'

const guest = { account: null, identities: undefined }

describe('init ticket artwork', () => {
  test('Init 5 draws every ticket type on its own artwork', () => {
    const resolve = (typeId: 'standard' | 'silver' | 'gold', dark: boolean) =>
      resolveInitTicketAppearance(
        INIT_NOV_2026_TICKET_CONFIG,
        guest,
        dark,
        typeId,
      )

    expect(resolve('standard', false).backgroundSrc).toBe(
      INIT_5_TICKET_BG_SRC_LIGHT,
    )
    expect(resolve('standard', true).backgroundSrc).toBe(
      INIT_5_TICKET_BG_SRC_DARK,
    )
    expect(resolve('silver', false).backgroundSrc).toBe(
      INIT_5_TICKET_BG_SRC_SILVER,
    )
    expect(resolve('gold', true).backgroundSrc).toBe(INIT_5_TICKET_BG_SRC_GOLD)
  })

  test('event artwork keeps the ticket type itself', () => {
    const gold = resolveInitTicketAppearance(
      INIT_NOV_2026_TICKET_CONFIG,
      guest,
      false,
      'gold',
    )

    expect(gold.typeId).toBe('gold')
    expect(gold.passLabel).toBe('Contributor')
    expect(gold.accentColor).toBe('#FBBF24')
    expect(gold.usesDarkChrome).toBe(true)
  })

  test('an event without artwork keeps the catalog artwork', () => {
    const gold = resolveInitTicketAppearance(
      INIT_JULY_2026_TICKET_CONFIG,
      guest,
      true,
      'gold',
    )

    expect(gold.backgroundSrc).toBe(INIT_TICKET_BG_SRC_GOLD)
  })
})
