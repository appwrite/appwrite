import { useCallback } from 'react'
import { useIsZeroAccount } from '@/hooks/use-zero-account'
import { visitUrl } from '@/lib/domains/url'
import { useAccountZero } from '@/lib/react-query/hooks/zero'

/**
 * The target of a link that opens a domain when clicked: the owner link for
 * the owner of an unclaimed Zero project, otherwise the plain domain. Text,
 * copies and QR codes use `domainUrl`, so the token never leaves the click.
 * Render these links with `rel="noopener noreferrer"`.
 */
export function useVisitUrl(): (domain: string) => string {
  const isZero = useIsZeroAccount()
  const { data } = useAccountZero({ enabled: isZero })
  const zero = isZero ? data : undefined
  return useCallback((domain: string) => visitUrl(domain, zero), [zero])
}
