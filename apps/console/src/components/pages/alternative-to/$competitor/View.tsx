import type { ComponentType } from 'react'
import { AmplifyComparison } from './_components/AmplifyComparison'
import { Auth0Comparison } from './_components/Auth0Comparison'
import { ClerkComparison } from './_components/ClerkComparison'
import { CloudinaryComparison } from './_components/CloudinaryComparison'
import { ComparisonShell } from './_components/ComparisonParts'
import { ConvexComparison } from './_components/ConvexComparison'
import { FirebaseComparison } from './_components/FirebaseComparison'
import { NeonComparison } from './_components/NeonComparison'
import { NetlifyComparison } from './_components/NetlifyComparison'
import { PlanetScaleComparison } from './_components/PlanetScaleComparison'
import { PlausibleComparison } from './_components/PlausibleComparison'
import { PostHogComparison } from './_components/PostHogComparison'
import { SupabaseComparison } from './_components/SupabaseComparison'
import { VercelComparison } from './_components/VercelComparison'
import type { AlternativeId } from '@/lib/alternatives/types'

/** Each comparison is its own composition; only the closing sections are shared. */
const COMPARISON_PAGES: Record<AlternativeId, ComponentType> = {
  supabase: SupabaseComparison,
  firebase: FirebaseComparison,
  vercel: VercelComparison,
  netlify: NetlifyComparison,
  neon: NeonComparison,
  auth0: Auth0Comparison,
  convex: ConvexComparison,
  cloudinary: CloudinaryComparison,
  clerk: ClerkComparison,
  amplify: AmplifyComparison,
  planetscale: PlanetScaleComparison,
  posthog: PostHogComparison,
  plausible: PlausibleComparison,
}

export function View({ competitor }: { competitor: AlternativeId }) {
  const Page = COMPARISON_PAGES[competitor]
  return (
    <ComparisonShell id={competitor}>
      <Page />
    </ComparisonShell>
  )
}
