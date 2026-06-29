/** Appwrite BaaS competitors on X for competitive voice analysis. */
export const APPWRITE_COMPETITOR_ACCOUNTS = [
  { username: 'appwrite', label: 'Appwrite', isAppwrite: true },
  { username: 'supabase', label: 'Supabase', isAppwrite: false },
  { username: 'firebase', label: 'Firebase', isAppwrite: false },
  { username: 'convex', label: 'Convex', isAppwrite: false },
  { username: 'nhost', label: 'NHost', isAppwrite: false },
  { username: 'back4app', label: 'Back4App', isAppwrite: false },
  { username: 'pocketbase', label: 'PocketBase', isAppwrite: false },
  { username: 'AWSAmplify', label: 'AWS Amplify', isAppwrite: false },
] as const

export type CompetitorAccount = (typeof APPWRITE_COMPETITOR_ACCOUNTS)[number]

export type AccountTimelineExport = {
  username: string
  label: string
  isAppwrite: boolean
  userId: string
  name?: string
  fetchedAt: string
  since: string
  tweetCount: number
  tweets: Array<{
    id: string
    text: string
    created_at?: string
    public_metrics?: {
      retweet_count: number
      reply_count: number
      like_count: number
      quote_count: number
    }
  }>
}

export type CompetitiveScanExport = {
  scannedAt: string
  since: string
  sinceLabel: string
  sinceUnit: 'weeks' | 'months' | 'custom'
  sinceAmount?: number
  durationDays: number
  accounts: AccountTimelineExport[]
  errors: Array<{ username: string; error: string }>
}
