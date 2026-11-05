import { useEffect, useMemo, useState } from 'react'
import { useInitPresenceActivity } from '@/lib/init/init-presence-context'
import {
  resolveInitPresenceBaselineActivity,
  resolveInitPresenceBaselineZone,
  type InitPresenceBaselineZone,
} from '@/lib/init/init-presence-baseline'
import type { InitDisplayEvent } from '@/lib/init/types'

export function useInitScrollSpyBaselineZone(event: InitDisplayEvent) {
  const dayNumbers = useMemo(() => event.days.map((day) => day.day), [event.days])
  const hasPrizes = Boolean(event.prizes && !event.isRecapMode)
  const hasGlobe = Boolean(event.presenceEnabled && !event.isRecapMode)
  const [zone, setZone] = useState<InitPresenceBaselineZone>({ kind: 'default' })

  useEffect(() => {
    setZone({ kind: 'default' })
  }, [dayNumbers, hasGlobe, hasPrizes])

  useEffect(() => {
    const main = document.getElementById('main-content')
    if (!main) return

    let frame = 0

    const update = () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => {
        setZone(
          resolveInitPresenceBaselineZone(main, {
            dayNumbers,
            hasPrizes,
            hasGlobe,
          }),
        )
      })
    }

    update()
    main.addEventListener('scroll', update, { passive: true })
    window.addEventListener('resize', update)

    const resizeObserver = new ResizeObserver(update)
    resizeObserver.observe(main)

    return () => {
      cancelAnimationFrame(frame)
      main.removeEventListener('scroll', update)
      window.removeEventListener('resize', update)
      resizeObserver.disconnect()
    }
  }, [dayNumbers, hasGlobe, hasPrizes])

  return zone
}

/** Keeps sidebar baseline activity aligned with the user's scroll position on `/init`. */
export function useInitPresenceBaseline(
  event: InitDisplayEvent,
  accountUserId?: string | null,
) {
  const zone = useInitScrollSpyBaselineZone(event)
  const { setBaselineActivity } = useInitPresenceActivity()

  useEffect(() => {
    const seed = accountUserId ?? 'guest'
    setBaselineActivity(resolveInitPresenceBaselineActivity(event, zone, seed))
  }, [accountUserId, event, setBaselineActivity, zone])
}
