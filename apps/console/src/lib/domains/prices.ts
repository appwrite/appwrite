// listPrices accepts at most 50 domains and one registration term per request.
export const DOMAIN_PRICES_BATCH_SIZE = 50

export function batchDomainPriceRequests(domains: string[]) {
  const byPeriod = new Map<number | undefined, string[]>()
  for (const domain of new Set(
    domains.map((domain) => domain.trim().toLowerCase()),
  )) {
    if (!domain) continue
    // .ai registrations require two years; all other TLDs use the API default.
    const periodYears = domain.endsWith('.ai') ? 2 : undefined
    const group = byPeriod.get(periodYears) ?? []
    group.push(domain)
    byPeriod.set(periodYears, group)
  }

  const batches: { domains: string[]; periodYears?: number }[] = []
  for (const [periodYears, group] of byPeriod) {
    for (let i = 0; i < group.length; i += DOMAIN_PRICES_BATCH_SIZE) {
      batches.push({
        domains: group.slice(i, i + DOMAIN_PRICES_BATCH_SIZE),
        periodYears,
      })
    }
  }
  return batches
}
