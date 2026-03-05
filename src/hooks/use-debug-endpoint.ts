import { useState, useEffect } from 'react'
import {
  getDebugEndpointOverride,
  getDebugCustomEndpoint,
  subscribeToDebugEndpointChange,
  type EndpointPresetId,
} from '@/lib/debug-endpoint'

/**
 * Hook to access the current debug endpoint override.
 * Re-renders when the endpoint changes (e.g. via debug menu).
 */
export function useDebugEndpoint() {
  const [preset, setPreset] = useState<EndpointPresetId | null>(
    getDebugEndpointOverride,
  )
  const [customUrl, setCustomUrl] = useState<string | null>(
    getDebugCustomEndpoint,
  )

  useEffect(() => {
    return subscribeToDebugEndpointChange(() => {
      setPreset(getDebugEndpointOverride())
      setCustomUrl(getDebugCustomEndpoint())
    })
  }, [])

  return { preset, customUrl }
}
