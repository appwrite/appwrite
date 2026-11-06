import { useState, useEffect } from 'react'
import {
  getDebugMcpEndpointOverride,
  getDebugCustomMcpEndpoint,
  getEffectiveMcpEndpointUrl,
  subscribeToDebugMcpEndpointChange,
  type McpEndpointPresetId,
} from '@/lib/debug-mcp-endpoint'

/**
 * Hook to access the current debug MCP endpoint override.
 * Re-renders when the endpoint changes (e.g. via debug menu).
 */
export function useDebugMcpEndpoint() {
  const [preset, setPreset] = useState<McpEndpointPresetId | null>(
    getDebugMcpEndpointOverride,
  )
  const [customUrl, setCustomUrl] = useState<string | null>(
    getDebugCustomMcpEndpoint,
  )
  const [effectiveUrl, setEffectiveUrl] = useState(getEffectiveMcpEndpointUrl)

  useEffect(() => {
    return subscribeToDebugMcpEndpointChange(() => {
      setPreset(getDebugMcpEndpointOverride())
      setCustomUrl(getDebugCustomMcpEndpoint())
      setEffectiveUrl(getEffectiveMcpEndpointUrl())
    })
  }, [])

  return { preset, customUrl, effectiveUrl }
}
