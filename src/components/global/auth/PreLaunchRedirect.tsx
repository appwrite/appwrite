import { useEffect, useState } from 'react'
import { useLocation, useNavigate } from '@tanstack/react-router'
import { useDebugOverrides } from '@/lib/debug-overrides'
import { isPreLaunchAllowedPath } from '@/lib/pre-launch'

/** Client-side lock: bounce any non-init (and non-auth) route back to `/init`. */
export function PreLaunchRedirect() {
  const location = useLocation()
  const navigate = useNavigate()
  const { preLaunch } = useDebugOverrides()
  const [overridesReady, setOverridesReady] = useState(false)

  useEffect(() => {
    setOverridesReady(true)
  }, [])

  useEffect(() => {
    if (!overridesReady) return
    if (!preLaunch) return
    if (isPreLaunchAllowedPath(location.pathname)) return
    void navigate({ to: '/init', replace: true })
  }, [overridesReady, preLaunch, location.pathname, navigate])

  return null
}

