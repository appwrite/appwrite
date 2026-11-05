import { useEffect } from 'react'
import { useLocation, useNavigate } from '@tanstack/react-router'
import { isPreLaunchAllowedPath, isPreLaunchModeEnabled } from '@/lib/pre-launch'

/** Client-side lock: bounce any non-init (and non-auth) route back to `/init`. */
export function PreLaunchRedirect() {
  const location = useLocation()
  const navigate = useNavigate()
  const preLaunch = isPreLaunchModeEnabled()

  useEffect(() => {
    if (!preLaunch) return
    if (isPreLaunchAllowedPath(location.pathname)) return
    void navigate({ to: '/init', replace: true })
  }, [preLaunch, location.pathname, navigate])

  return null
}
