import { useCallback } from 'react'
import { useIsZeroAccount } from '@/hooks/use-zero-account'
import { domainUrl } from '@/lib/domains/url'
import { useAccountZero } from '@/lib/react-query/hooks/zero'

/**
 * `domainUrl` for links the console opens in the browser. For the owner of an
 * unclaimed Zero project it returns the owner link, since the bare domain is
 * closed to everyone else until the project is claimed.
 */
export function useDomainUrl(): (domain: string) => string {
  const isZero = useIsZeroAccount()
  const { data: zero } = useAccountZero({ enabled: isZero })
  const accessToken = isZero ? zero?.accessToken : undefined
  return useCallback(
    (domain: string) => domainUrl(domain, accessToken),
    [accessToken],
  )
}
