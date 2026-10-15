import { useEffect } from 'react'
import { useLocation, useNavigate } from '@tanstack/react-router'
import { shouldRedirectDedicatedDatabaseProvisioning } from '@/lib/databases/dedicated-database-provisioning-access'

export function useRedirectIfDedicatedDatabaseProvisioning(
  status: string | null | undefined,
  fallbackTo: string,
  fallbackParams: Record<string, string>,
) {
  const { pathname } = useLocation()
  const navigate = useNavigate()
  const paramsKey = JSON.stringify(fallbackParams)

  useEffect(() => {
    if (!shouldRedirectDedicatedDatabaseProvisioning(status, pathname)) return
    void navigate({
      to: fallbackTo as never,
      params: JSON.parse(paramsKey) as never,
      replace: true,
    })
  }, [fallbackTo, navigate, paramsKey, pathname, status])
}
