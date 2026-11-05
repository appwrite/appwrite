export type BuyDomainContinueSearch = {
  domain?: string
  stage?: 'checkout'
}

function normalizeDomainParam(domain: string | undefined): string | undefined {
  const normalized = domain?.trim().toLowerCase()
  return normalized || undefined
}

export function buildBuyDomainContinuePath(
  search: BuyDomainContinueSearch = {},
): string {
  const params = new URLSearchParams()
  const domain = normalizeDomainParam(search.domain)
  if (domain) params.set('domain', domain)
  if (search.stage === 'checkout') params.set('stage', 'checkout')
  const query = params.toString()
  return query ? `/domains/continue?${query}` : '/domains/continue'
}

export function buildBuyDomainWizardSearch(search: BuyDomainContinueSearch = {}) {
  const normalized: BuyDomainContinueSearch = {}
  const domain = normalizeDomainParam(search.domain)
  if (domain) normalized.domain = domain
  if (search.stage === 'checkout') normalized.stage = 'checkout'
  return normalized
}

export function buildSignUpForDomainPath(search: BuyDomainContinueSearch = {}) {
  return {
    to: '/sign-up' as const,
    search: {
      redirect: buildBuyDomainContinuePath(search),
    },
  }
}

export function buildSignInForDomainPath(search: BuyDomainContinueSearch = {}) {
  return {
    to: '/sign-in' as const,
    search: {
      redirect: buildBuyDomainContinuePath(search),
    },
  }
}
