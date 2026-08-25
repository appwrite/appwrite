import {
  Blocks,
  FileText,
  Gift,
  HeartHandshake,
  type LucideIcon,
} from 'lucide-react'
import type { ComponentType, SVGProps } from 'react'
import type { ConsoleProfileId } from '@/lib/console-profiles'
import { MARKETING_SOCIAL_STATS } from '@/lib/marketing/social-stats'
import { GitHubBrandIcon } from '@/lib/community/GitHubBrandIcon'
import { XBrandIcon } from '@/lib/community/XBrandIcon'

/** Unique calendar days of console use before the wizard can first appear. */
export const COMMUNITY_SUPPORT_UNIQUE_DAYS_THRESHOLD = 7

/** Reminder interval after skip until an action is taken (~2 months). */
export const COMMUNITY_SUPPORT_REMINDER_MS = 60 * 24 * 60 * 60 * 1000

export type CommunitySupportActionId =
  | 'community'
  | 'contribute'
  | 'share'
  | 'content'
  | 'affiliates'
  | 'integrations'

export type CommunitySupportIcon =
  | LucideIcon
  | ComponentType<SVGProps<SVGSVGElement>>

export type CommunitySupportAction = {
  id: CommunitySupportActionId
  title: string
  description: string
  href: string
  external: boolean
  icon: CommunitySupportIcon
}

/** Which console profiles may see this example. */
export type CommunitySupportShareAudience =
  | 'all'
  | 'cloud'
  | 'self-hosted'

export type CommunitySupportShareExample = {
  text: string
  audience: CommunitySupportShareAudience
}

/**
 * Example posts for the editable X share box.
 * Keep these in a real developer voice (not campaign copy).
 */
export const COMMUNITY_SUPPORT_SHARE_TEXTS: CommunitySupportShareExample[] = [
  {
    audience: 'all',
    text: "I've been building with @appwrite and it just stays out of the way. Auth, DB, storage. Done. https://appwrite.io",
  },
  {
    audience: 'all',
    text: 'Stopped stitching together five backend services. @appwrite covers the boring parts so I can ship. https://appwrite.io',
  },
  {
    audience: 'all',
    text: 'If you need a backend that feels open source and production-ready, try @appwrite. Been happy with it. https://appwrite.io',
  },
  {
    audience: 'all',
    text: "Been shipping side projects on @appwrite instead of wiring everything from scratch. It's a good call. https://appwrite.io",
  },
  {
    audience: 'all',
    text: 'Auth used to eat a weekend. With @appwrite I set it up and moved on. https://appwrite.io',
  },
  {
    audience: 'all',
    text: 'Replaced a pile of custom backend glue with @appwrite. Fewer moving parts, same velocity. https://appwrite.io',
  },
  {
    audience: 'all',
    text: 'Databases, file storage, and functions in one place. @appwrite has been a solid foundation for my last few apps. https://appwrite.io',
  },
  {
    audience: 'all',
    text: 'I tell friends to try @appwrite when they ask what to use instead of rolling their own backend. https://appwrite.io',
  },
  {
    audience: 'all',
    text: 'Open source backend that does not feel like a science project: @appwrite. Worth a look. https://appwrite.io',
  },
  {
    audience: 'all',
    text: 'Spent less time on auth edge cases this month thanks to @appwrite. That alone was worth it. https://appwrite.io',
  },
  {
    audience: 'cloud',
    text: 'Moved a side project onto @appwrite Cloud and stopped babysitting servers. Feels great. https://appwrite.io',
  },
  {
    audience: 'cloud',
    text: 'Using @appwrite Cloud for auth and databases so I can stay focused on the product. https://appwrite.io',
  },
  {
    audience: 'cloud',
    text: 'Spun up @appwrite Cloud for a weekend build and kept shipping on it. Solid choice. https://appwrite.io',
  },
  {
    audience: 'cloud',
    text: 'Quiet recommendation if you want a managed backend: @appwrite Cloud. Open source roots, less ops. https://appwrite.io',
  },
  {
    audience: 'self-hosted',
    text: 'Self-hosting @appwrite for a client project and it has been refreshingly boring (in a good way). https://appwrite.io',
  },
  {
    audience: 'self-hosted',
    text: 'Running a self-hosted @appwrite instance for our team. Open source, under our control, still nice to use. https://appwrite.io',
  },
  {
    audience: 'self-hosted',
    text: 'Quiet recommendation: self-hosted @appwrite. Solid APIs, and I actually enjoy using the console. https://appwrite.io',
  },
  {
    audience: 'self-hosted',
    text: 'Self-hosted @appwrite has been a calm foundation for our internal tools. No surprise lock-in. https://appwrite.io',
  },
]

export function getCommunitySupportShareTextsForProfile(
  profileId: ConsoleProfileId,
): string[] {
  return COMMUNITY_SUPPORT_SHARE_TEXTS.filter(
    (item) => item.audience === 'all' || item.audience === profileId,
  ).map((item) => item.text)
}

/** Fisher-Yates shuffle for the active profile; optionally avoid starting with `avoidFirst`. */
export function shuffleCommunitySupportShareTexts(
  profileId: ConsoleProfileId,
  avoidFirst?: string,
): string[] {
  const shuffled = [...getCommunitySupportShareTextsForProfile(profileId)]
  for (let i = shuffled.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1))
    const current = shuffled[i]!
    shuffled[i] = shuffled[j]!
    shuffled[j] = current
  }
  if (avoidFirst && shuffled.length > 1 && shuffled[0] === avoidFirst) {
    const swapWith = 1 + Math.floor(Math.random() * (shuffled.length - 1))
    const first = shuffled[0]!
    shuffled[0] = shuffled[swapWith]!
    shuffled[swapWith] = first
  }
  return shuffled
}

/** Random share text from a freshly shuffled deck for the given profile. */
export function getCommunitySupportShareText(
  profileId: ConsoleProfileId = 'cloud',
): string {
  return shuffleCommunitySupportShareTexts(profileId)[0]!
}

export function getCommunitySupportShareHref(text: string): string {
  return `https://twitter.com/intent/tweet?text=${encodeURIComponent(text)}`
}

export { GitHubBrandIcon, XBrandIcon }

export const COMMUNITY_SUPPORT_ACTIONS: CommunitySupportAction[] = [
  {
    id: 'community',
    title: 'Join the community',
    description: 'Help other Appwriters on Discord and grow with the community.',
    href: MARKETING_SOCIAL_STATS.discord.link,
    external: true,
    icon: HeartHandshake,
  },
  {
    id: 'contribute',
    title: 'Star us on GitHub',
    description: 'A star helps more developers discover Appwrite.',
    href: 'https://github.com/appwrite/appwrite/stargazers',
    external: true,
    icon: GitHubBrandIcon,
  },
  {
    id: 'share',
    title: 'Spread the word on X',
    description: 'Tell others what you are building with Appwrite.',
    href: getCommunitySupportShareHref(getCommunitySupportShareText()),
    external: true,
    icon: XBrandIcon,
  },
  {
    id: 'content',
    title: 'Write content',
    description:
      'Publish blogs, videos, or tutorials that help developers discover Appwrite.',
    href: '/community',
    external: true,
    icon: FileText,
  },
  {
    id: 'affiliates',
    title: 'Join the Affiliates program',
    description:
      'Share invite links and earn credits when developers upgrade to Pro.',
    href: '/affiliates',
    external: true,
    icon: Gift,
  },
  {
    id: 'integrations',
    title: 'Build integrations',
    description: 'Connect Appwrite to the tools your stack already uses.',
    href: '/integrations',
    external: true,
    icon: Blocks,
  },
]

export function getLocalDayKey(date: Date = new Date()): string {
  const year = date.getFullYear()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export type CommunitySupportPromptState = {
  uniqueDayCount: number
  lastActiveDay: string | null
  shownCount: number
  lastShownAt: string | null
  actionTakenAt: string | null
  actionId: CommunitySupportActionId | null
}

export function shouldShowCommunitySupportPrompt(
  state: CommunitySupportPromptState,
  now: Date = new Date(),
): boolean {
  if (state.actionTakenAt) return false
  if (state.uniqueDayCount < COMMUNITY_SUPPORT_UNIQUE_DAYS_THRESHOLD) {
    return false
  }
  if (!state.lastShownAt) return true
  const lastShownMs = Date.parse(state.lastShownAt)
  if (Number.isNaN(lastShownMs)) return true
  return now.getTime() - lastShownMs >= COMMUNITY_SUPPORT_REMINDER_MS
}

export function withRecordedActiveDay(
  state: CommunitySupportPromptState,
  dayKey: string = getLocalDayKey(),
): CommunitySupportPromptState {
  if (state.lastActiveDay === dayKey) return state
  return {
    ...state,
    lastActiveDay: dayKey,
    uniqueDayCount: state.uniqueDayCount + 1,
  }
}

/** Persist an impression: bump show count and stamp lastShownAt. */
export function withRecordedShow(
  state: CommunitySupportPromptState,
  now: Date = new Date(),
): CommunitySupportPromptState {
  return {
    ...state,
    shownCount: state.shownCount + 1,
    lastShownAt: now.toISOString(),
  }
}

export function withSkippedPrompt(
  state: CommunitySupportPromptState,
  now: Date = new Date(),
): CommunitySupportPromptState {
  return {
    ...state,
    // Always refresh so skip is a real prefs write (not a no-op after recordShown)
    // and wins races against in-flight active-day updates.
    lastShownAt: now.toISOString(),
  }
}

/**
 * Whether `local` should be kept over `remote` after an account prefs write.
 * Used to avoid older in-flight community-support writes clobbering newer state.
 */
export function isCommunitySupportStateAhead(
  local: CommunitySupportPromptState,
  remote: CommunitySupportPromptState,
): boolean {
  if (local.actionTakenAt && !remote.actionTakenAt) return true
  if (remote.actionTakenAt && !local.actionTakenAt) return false

  if (local.shownCount !== remote.shownCount) {
    return local.shownCount > remote.shownCount
  }

  const localShown = local.lastShownAt ? Date.parse(local.lastShownAt) : 0
  const remoteShown = remote.lastShownAt ? Date.parse(remote.lastShownAt) : 0
  const localShownMs = Number.isFinite(localShown) ? localShown : 0
  const remoteShownMs = Number.isFinite(remoteShown) ? remoteShown : 0
  if (localShownMs !== remoteShownMs) {
    return localShownMs > remoteShownMs
  }

  return local.uniqueDayCount > remote.uniqueDayCount
}

export function withTakenAction(
  state: CommunitySupportPromptState,
  actionId: CommunitySupportActionId,
  now: Date = new Date(),
): CommunitySupportPromptState {
  return {
    ...state,
    actionId,
    actionTakenAt: now.toISOString(),
    lastShownAt: now.toISOString(),
  }
}
