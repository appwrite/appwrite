import type { MarketingFaqItem } from '@/components/pages/marketing/MarketingFaqSection'
import type { ProductTone } from '@/lib/products/theme'
import type { ProductId } from '@/lib/products/types'

export type AlternativeId =
  | 'supabase'
  | 'firebase'
  | 'vercel'
  | 'netlify'
  | 'neon'
  | 'auth0'
  | 'convex'
  | 'cloudinary'
  | 'clerk'
  | 'amplify'
  | 'planetscale'
  | 'posthog'
  | 'plausible'

/**
 * `true` renders a check, `false` a dash, `'partial'` a half mark, `'soon'` a "Coming soon" pill,
 * and any other string renders as text.
 */
export type ComparisonValue = boolean | 'partial' | 'soon' | string

export type ComparisonCell = ComparisonValue | { value: ComparisonValue; note: string }

export type ComparisonRow = {
  label: string
  appwrite: ComparisonCell
  competitor: ComparisonCell
}

export type ComparisonGroup = {
  title: string
  rows: ComparisonRow[]
}

export type AlternativeLinkKind = 'blog' | 'product' | 'docs'

export type AlternativeLink = {
  kind: AlternativeLinkKind
  title: string
  description: string
  href: string
}

export type AlternativeSource = {
  label: string
  href: string
}

export type FairPlayPoint =
  | string
  | {
      text: string
      /** Muted follow-up (e.g. Appwrite roadmap context). */
      aside?: string
    }

export type AlternativeContent = {
  comparison: ComparisonGroup[]
  /** Honest notes on where the other platform is a strong fit. */
  fairPlay: {
    title: string
    description: string
    points: FairPlayPoint[]
  }
  related: AlternativeLink[]
  faq: MarketingFaqItem[]
  /** Where the competitor facts came from. Not rendered; kept so the comparison can be re-verified. */
  sources: AlternativeSource[]
}

export type AlternativeMeta = {
  id: AlternativeId
  /** Competitor display name. Proper noun, never translated. */
  name: string
  /** What the competitor is, in a few words (shown on cards and in the OG image eyebrow). */
  category: string
  /** Browser and Open Graph title without the Appwrite suffix. */
  metaTitle: string
  metaDescription: string
  /** One line for the "compare other platforms" grid. */
  summary: string
  tone: ProductTone
  secondaryTone: ProductTone
  /** Appwrite product the comparison centers on, when the competitor is product specific. */
  focusProduct?: ProductId
}
