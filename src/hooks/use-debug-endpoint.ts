import { useState, useEffect } from 'react'
import {
  getDebugEndpointOverride,
  getDebugCustomEndpoint,
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
  const [effectiveUrl, setEffectiveUrl] = useState(
    () => getEffectiveEndpointBaseUrl() ?? getEnvEndpointBaseUrl(),
  )
  const [envUrl, setEnvUrl] = useState(getEnvEndpointBaseUrl)

  useEffect(() => {
    return subscribeToDebugEndpointChange(() => {
      setPreset(getDebugEndpointOverride())
      setCustomUrl(getDebugCustomEndpoint())
      setEffectiveUrl(getEffectiveEndpointBaseUrl() ?? getEnvEndpointBaseUrl())
      setEnvUrl(getEnvEndpointBaseUrl())
    })
  }, [])

  return { preset, customUrl, effectiveUrl, envUrl }
}
