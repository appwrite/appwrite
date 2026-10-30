import { useCallback } from 'react'
import { useIsZeroAccount } from '@/hooks/use-zero-account'
import { domainUrl, ownerLinkUrl } from '@/lib/domains/url'
import { useAccountZero } from '@/lib/react-query/hooks/zero'

/**
 * The target of a link that opens a domain when clicked. For the owner of an
 * unclaimed Zero project it is the owner link, since the bare domain is closed
 * to everyone else until the project is claimed. Text, copies and QR codes use
 * `domainUrl`, so the token never leaves the click. Render these links with
 * `rel="noopener noreferrer"`.
 */
export function useVisitUrl(): (domain: string) => string {
  const isZero = useIsZeroAccount()
  const { data: zero } = useAccountZero({ enabled: isZero })
  const accessToken = isZero ? zero?.accessToken : undefined
  return useCallback(
    (domain: string) =>
      accessToken ? ownerLinkUrl(domain, accessToken) : domainUrl(domain),
    [accessToken],
  )
}
