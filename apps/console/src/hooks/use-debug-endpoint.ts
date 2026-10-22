import { useState, useEffect } from 'react'
import {
  getDebugEndpointOverride,
  getDebugCustomEndpoint,
  getCustomDebugEndpoints,
  getEffectiveEndpointBaseUrl,
  getEnvEndpointBaseUrl,
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
  const [customEndpoints, setCustomEndpoints] = useState(
    getCustomDebugEndpoints,
  )
  const [effectiveUrl, setEffectiveUrl] = useState(
    () => getEffectiveEndpointBaseUrl() ?? getEnvEndpointBaseUrl(),
  )
  const [envUrl, setEnvUrl] = useState(getEnvEndpointBaseUrl)

  useEffect(() => {
    return subscribeToDebugEndpointChange(() => {
      setPreset(getDebugEndpointOverride())
      setCustomUrl(getDebugCustomEndpoint())
      setCustomEndpoints(getCustomDebugEndpoints())
      setEffectiveUrl(getEffectiveEndpointBaseUrl() ?? getEnvEndpointBaseUrl())
      setEnvUrl(getEnvEndpointBaseUrl())
    })
  }, [])

  return { preset, customUrl, customEndpoints, effectiveUrl, envUrl }
}
