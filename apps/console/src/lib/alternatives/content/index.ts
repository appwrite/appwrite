import { amplifyAlternativeContent } from '@/lib/alternatives/content/amplify'
import { auth0AlternativeContent } from '@/lib/alternatives/content/auth0'
import { bugbotAlternativeContent } from '@/lib/alternatives/content/bugbot'
import { clerkAlternativeContent } from '@/lib/alternatives/content/clerk'
import { cloudinaryAlternativeContent } from '@/lib/alternatives/content/cloudinary'
import { coderabbitAlternativeContent } from '@/lib/alternatives/content/coderabbit'
import { convexAlternativeContent } from '@/lib/alternatives/content/convex'
import { copilotAlternativeContent } from '@/lib/alternatives/content/copilot'
import { firebaseAlternativeContent } from '@/lib/alternatives/content/firebase'
import { greptileAlternativeContent } from '@/lib/alternatives/content/greptile'
import { muxAlternativeContent } from '@/lib/alternatives/content/mux'
import { neonAlternativeContent } from '@/lib/alternatives/content/neon'
import { netlifyAlternativeContent } from '@/lib/alternatives/content/netlify'
import { planetscaleAlternativeContent } from '@/lib/alternatives/content/planetscale'
import { plausibleAlternativeContent } from '@/lib/alternatives/content/plausible'
import { posthogAlternativeContent } from '@/lib/alternatives/content/posthog'
import { supabaseAlternativeContent } from '@/lib/alternatives/content/supabase'
import { vercelAlternativeContent } from '@/lib/alternatives/content/vercel'
import type { AlternativeContent, AlternativeId } from '@/lib/alternatives/types'

export const ALTERNATIVE_CONTENT: Record<AlternativeId, AlternativeContent> = {
  supabase: supabaseAlternativeContent,
  firebase: firebaseAlternativeContent,
  vercel: vercelAlternativeContent,
  netlify: netlifyAlternativeContent,
  neon: neonAlternativeContent,
  auth0: auth0AlternativeContent,
  convex: convexAlternativeContent,
  cloudinary: cloudinaryAlternativeContent,
  clerk: clerkAlternativeContent,
  amplify: amplifyAlternativeContent,
  planetscale: planetscaleAlternativeContent,
  posthog: posthogAlternativeContent,
  plausible: plausibleAlternativeContent,
  mux: muxAlternativeContent,
  coderabbit: coderabbitAlternativeContent,
  copilot: copilotAlternativeContent,
  bugbot: bugbotAlternativeContent,
  greptile: greptileAlternativeContent,
}

export function getAlternativeContent(id: AlternativeId): AlternativeContent {
  return ALTERNATIVE_CONTENT[id]
}
