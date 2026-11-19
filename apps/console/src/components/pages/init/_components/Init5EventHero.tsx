import type { InitDisplayEvent, LaunchEventLiveBanner } from '@/lib/init/types'
import type { ReactNode } from 'react'
import { EventHero } from './EventHero'
import { Init5HeroBackground } from './Init5HeroBackground'

export function Init5EventHero({
  event,
  liveBanner,
  headerAddon,
}: {
  event: InitDisplayEvent
  liveBanner?: LaunchEventLiveBanner
  headerAddon?: ReactNode
}) {
  return (
    <EventHero
      event={event}
      liveBanner={liveBanner}
      headerAddon={headerAddon}
      heroLayout="init5"
      HeroBackground={Init5HeroBackground}
    />
  )
}
