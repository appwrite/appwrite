import { useEffect, useRef, useState } from 'react'
import type { ProjectFirewallTrafficOverview } from '@/lib/usage/firewall-events'
import { getFirewallTrafficOverviewSnapshot } from '@/lib/usage/firewall-events'

type UseFirewallTrafficLiveTransitionsOptions = {
  overview: ProjectFirewallTrafficOverview | undefined
  enabled: boolean
}

export function useFirewallTrafficLiveTransitions({
  overview,
  enabled,
}: UseFirewallTrafficLiveTransitionsOptions) {
  const previousOverviewRef = useRef<ProjectFirewallTrafficOverview | undefined>(
    undefined,
  )
  const [chartAnimationRevision, setChartAnimationRevision] = useState(0)

  useEffect(() => {
    if (!enabled || !overview) return

    const previous = previousOverviewRef.current
    previousOverviewRef.current = overview

    if (!previous) return
    if (
      getFirewallTrafficOverviewSnapshot(previous) ===
      getFirewallTrafficOverviewSnapshot(overview)
    ) {
      return
    }

    setChartAnimationRevision((revision) => revision + 1)
  }, [overview, enabled])

  return { chartAnimationRevision }
}
