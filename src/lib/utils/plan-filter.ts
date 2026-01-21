/**
 * Maps tier values to plan names
 * - tier-0 → 'free'
 * - tier-1 → 'pro'
 * - anything else → 'custom'
 */
export function getPlanNameFromTier(
  tier: string | number | null | undefined,
): string {
  if (tier === null || tier === undefined) {
    return 'free'
  }

  // Handle string format like "tier-0", "tier-1", etc.
  if (typeof tier === 'string') {
    const tierMatch = tier.match(/tier-(\d+)/i)
    if (tierMatch) {
      const tierNumber = parseInt(tierMatch[1], 10)
      if (tierNumber === 0) return 'free'
      if (tierNumber === 1) return 'pro'
      return 'custom'
    }

    // Handle direct tier numbers as strings
    if (tier === '0' || tier.toLowerCase() === 'tier-0') return 'free'
    if (tier === '1' || tier.toLowerCase() === 'tier-1') return 'pro'

    // If it's already a plan name, return it
    if (
      ['free', 'pro', 'scale', 'enterprise', 'custom'].includes(
        tier.toLowerCase(),
      )
    ) {
      return tier.toLowerCase()
    }

    return 'custom'
  }

  // Handle numeric tier values
  if (typeof tier === 'number') {
    if (tier === 0) return 'free'
    if (tier === 1) return 'pro'
    return 'custom'
  }

  return 'free'
}
