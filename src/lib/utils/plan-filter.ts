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

    // If it's already a canonical plan name, return it
    const normalized = tier.toLowerCase()
    if (['free', 'pro', 'custom'].includes(normalized)) return normalized
    // Legacy: scale/enterprise normalize to custom
    if (['scale', 'enterprise'].includes(normalized)) return 'custom'

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
