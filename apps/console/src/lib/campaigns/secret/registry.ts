// Relative-import safe: pulled into marketing-page-paths.ts, which vite.config.ts loads before `@/` resolves.

/** Paid-social landing pages for "The secret ... is hiding from you" campaign. */
export const SECRET_CAMPAIGN_VARIANTS = ['supabase-and-firebase', 'supabase', 'firebase'] as const

export type SecretCampaignVariant = (typeof SECRET_CAMPAIGN_VARIANTS)[number]

export function isSecretCampaignVariant(value: string): value is SecretCampaignVariant {
  return (SECRET_CAMPAIGN_VARIANTS as readonly string[]).includes(value)
}

export function getSecretCampaignPath(variant: SecretCampaignVariant): `/secret/${SecretCampaignVariant}` {
  return `/secret/${variant}`
}

/** Ad landing pages: rendered and prerendered like marketing pages, but kept out of the sitemap and search. */
export const SECRET_CAMPAIGN_PATHS = SECRET_CAMPAIGN_VARIANTS.map(getSecretCampaignPath)
