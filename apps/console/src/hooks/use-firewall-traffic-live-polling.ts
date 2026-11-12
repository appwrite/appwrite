import { useEffect, useRef } from 'react'
import { FIREWALL_TRAFFIC_POLL_INTERVAL_MS } from '@/lib/usage/firewall-traffic-polling'

type UseFirewallTrafficLivePollingOptions = {
  enabled: boolean
  isDocumentVisible: boolean
  refreshRollingDateRange: () => void
  refetch: () => Promise<unknown>
}

export function useFirewallTrafficLivePolling({
  enabled,
  isDocumentVisible,
  refreshRollingDateRange,
  refetch,
}: UseFirewallTrafficLivePollingOptions) {
  const refetchRef = useRef(refetch)
  refetchRef.current = refetch
  const wasDocumentVisibleRef = useRef(isDocumentVisible)
  const isLiveActive = enabled && isDocumentVisible

  useEffect(() => {
    if (!isLiveActive) return

    const poll = () => {
      refreshRollingDateRange()
      void refetchRef.current()
    }

    const intervalId = window.setInterval(
      poll,
      FIREWALL_TRAFFIC_POLL_INTERVAL_MS,
    )
    return () => window.clearInterval(intervalId)
  }, [isLiveActive, refreshRollingDateRange])

  useEffect(() => {
    const becameVisible = isDocumentVisible && !wasDocumentVisibleRef.current
    wasDocumentVisibleRef.current = isDocumentVisible

    if (!becameVisible || !enabled) return

    refreshRollingDateRange()
    void refetchRef.current()
  }, [isDocumentVisible, enabled, refreshRollingDateRange])

  return { isLiveActive }
}
