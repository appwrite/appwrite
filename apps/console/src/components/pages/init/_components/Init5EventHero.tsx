import type { InitDisplayEvent, LaunchEventLiveBanner } from '@/lib/init/types'
import type { ReactNode } from 'react'
import { EventHero } from './EventHero'
import { Init5HeroBackground } from './Init5HeroBackground'
import { Init5HeroProvider } from './init5/init5-hero-context'

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
    <Init5HeroProvider>
      <EventHero
        event={event}
        liveBanner={liveBanner}
        headerAddon={headerAddon}
        heroLayout="init5"
        HeroBackground={Init5HeroBackground}
      />
    </Init5HeroProvider>
  )
}
