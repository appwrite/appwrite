import { amplifyAlternativeContent } from '@/lib/alternatives/content/amplify'
import { auth0AlternativeContent } from '@/lib/alternatives/content/auth0'
import { clerkAlternativeContent } from '@/lib/alternatives/content/clerk'
import { cloudinaryAlternativeContent } from '@/lib/alternatives/content/cloudinary'
import { convexAlternativeContent } from '@/lib/alternatives/content/convex'
import { firebaseAlternativeContent } from '@/lib/alternatives/content/firebase'
import { neonAlternativeContent } from '@/lib/alternatives/content/neon'
import { netlifyAlternativeContent } from '@/lib/alternatives/content/netlify'
import { planetscaleAlternativeContent } from '@/lib/alternatives/content/planetscale'
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
}

export function getAlternativeContent(id: AlternativeId): AlternativeContent {
  return ALTERNATIVE_CONTENT[id]
}
