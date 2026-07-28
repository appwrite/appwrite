import type { Models } from '@appwrite.io/console'
import {
  INIT_TICKET_BG_SRC_DARK,
  INIT_TICKET_BG_SRC_GOLD,
  INIT_TICKET_BG_SRC_LIGHT,
  INIT_TICKET_BG_SRC_SILVER,
} from '@/lib/init/ticket-layout'

export type InitTicketTypeId = 'standard' | 'silver' | 'gold'

export const INIT_TICKET_TYPE_IDS = [
  'standard',
  'silver',
  'gold',
] as const satisfies readonly InitTicketTypeId[]

export function isInitTicketTypeId(value: string): value is InitTicketTypeId {
  return INIT_TICKET_TYPE_IDS.includes(value as InitTicketTypeId)
}

export function formatInitMockTicketType(
  mockTypeId: InitTicketTypeId | null | undefined,
): string {
  if (mockTypeId === null || mockTypeId === undefined) {
    return 'Use account rules on /init'
  }
  switch (mockTypeId) {
    case 'gold':
      return 'Gold · Contributor'
    case 'silver':
      return 'Silver · Appwrite VIP'
    default:
      return 'Standard · theme pass'
  }
}

export interface InitTicketTypeMatcherContext {
  account: Models.User | null | undefined
  identities: Models.Identity[] | undefined
}

export interface InitTicketTypeDefinition {
  id: InitTicketTypeId
  /** Fixed artwork path. When omitted, the ticket follows the console theme. */
  backgroundSrc?: string
  themeBackgroundSrcLight?: string
  themeBackgroundSrcDark?: string
  /** Use light text and inverted icons regardless of console theme. */
  forceDarkChrome?: boolean
  shadowClassName: string
  shadowClassNameDarkTheme?: string
  /** Base vertical offset (px) between ticket and drop shadow. */
  shadowOffsetY: number
  /** Pass tier label on the ticket stub (e.g. Appwrite VIP). */
  passLabel?: string
  /** Role line under the holder name (e.g. Appwrite developer). */
  holderTitle?: string
  /** Fixed accent for underscore and ticket ID. Standard uses theme variants. */
  accentColor?: string
  themeAccentColorLight?: string
  themeAccentColorDark?: string
}

export interface InitEventTicketTypeRule {
  typeId: InitTicketTypeId
  when: (context: InitTicketTypeMatcherContext) => boolean
}

/** Per-event ticket type rules - first match wins. */
export interface InitEventTicketConfig {
  rules: InitEventTicketTypeRule[]
}

export interface ResolvedInitTicketAppearance {
  typeId: InitTicketTypeId
  backgroundSrc: string
  usesDarkChrome: boolean
  shadowClassName: string
  shadowOffsetY: number
  passLabel: string
  holderTitle: string
  accentColor: string
}

export const INIT_TICKET_TYPE_CATALOG: Record<
  InitTicketTypeId,
  InitTicketTypeDefinition
> = {
  standard: {
    id: 'standard',
    themeBackgroundSrcLight: INIT_TICKET_BG_SRC_LIGHT,
    themeBackgroundSrcDark: INIT_TICKET_BG_SRC_DARK,
    themeAccentColorLight: 'var(--brand-cta)',
    themeAccentColorDark: 'var(--brand-cta)',
    shadowClassName: 'bg-black/8',
    shadowClassNameDarkTheme: 'bg-black/8',
    shadowOffsetY: 0,
    passLabel: 'Init pass',
    holderTitle: 'Appwrite developer',
  },
  silver: {
    id: 'silver',
    backgroundSrc: INIT_TICKET_BG_SRC_SILVER,
    forceDarkChrome: true,
    accentColor: '#E4E4E7',
    shadowClassName: 'bg-black/8',
    shadowOffsetY: 0,
    passLabel: 'Appwrite VIP',
    holderTitle: 'Appwrite developer',
  },
  gold: {
    id: 'gold',
    backgroundSrc: INIT_TICKET_BG_SRC_GOLD,
    forceDarkChrome: true,
    accentColor: '#FBBF24',
    shadowOffsetY: 0,
    passLabel: 'Contributor',
    holderTitle: 'Appwrite developer',
  },
}

function isAppwriteVerifiedTicketUser(
  account: Models.User | null | undefined,
): boolean {
  const email = account?.email?.trim().toLowerCase()
  if (!email || !account?.emailVerification) return false
  return email.endsWith('@appwrite.io')
}

const INIT_TICKET_VIP_TENURE_MS = 3 * 365.25 * 24 * 60 * 60 * 1000

function isLongTermVipMember(
  account: Models.User | null | undefined,
): boolean {
  if (!account) return false
  const createdAt = account.$createdAt || account.registration
  if (!createdAt) return false
  const created = new Date(createdAt).getTime()
  if (Number.isNaN(created)) return false
  return Date.now() - created >= INIT_TICKET_VIP_TENURE_MS
}

export const INIT_TICKET_MATCHERS = {
  appwriteVerified: (context: InitTicketTypeMatcherContext) =>
    isAppwriteVerifiedTicketUser(context.account),
  longTermVip: (context: InitTicketTypeMatcherContext) =>
    isLongTermVipMember(context.account),
  always: () => true,
} as const

/** Default rules shared by Init events unless overridden. */
export const DEFAULT_INIT_EVENT_TICKET_CONFIG: InitEventTicketConfig = {
  rules: [
    { typeId: 'gold', when: INIT_TICKET_MATCHERS.appwriteVerified },
    { typeId: 'silver', when: INIT_TICKET_MATCHERS.longTermVip },
    { typeId: 'standard', when: INIT_TICKET_MATCHERS.always },
  ],
}

export function resolveInitTicketType(
  config: InitEventTicketConfig,
  context: InitTicketTypeMatcherContext,
): InitTicketTypeDefinition {
  const rule = config.rules.find((entry) => entry.when(context))
  const typeId = rule?.typeId ?? 'standard'
  return INIT_TICKET_TYPE_CATALOG[typeId]
}

export function resolveInitTicketAppearance(
  config: InitEventTicketConfig,
  context: InitTicketTypeMatcherContext,
  themeUsesDarkImage: boolean,
  mockTypeId?: InitTicketTypeId | null,
): ResolvedInitTicketAppearance {
  const type = mockTypeId
    ? INIT_TICKET_TYPE_CATALOG[mockTypeId]
    : resolveInitTicketType(config, context)
  const usesDarkChrome = type.forceDarkChrome ?? themeUsesDarkImage

  const backgroundSrc =
    type.backgroundSrc ??
    (themeUsesDarkImage
      ? type.themeBackgroundSrcDark!
      : type.themeBackgroundSrcLight!)

  const shadowClassName =
    type.id === 'standard' && themeUsesDarkImage && type.shadowClassNameDarkTheme
      ? type.shadowClassNameDarkTheme
      : type.shadowClassName

  const accentColor =
    type.accentColor ??
    (themeUsesDarkImage
      ? type.themeAccentColorDark!
      : type.themeAccentColorLight!)

  return {
    typeId: type.id,
    backgroundSrc,
    usesDarkChrome,
    shadowClassName,
    shadowOffsetY: type.shadowOffsetY,
    passLabel: type.passLabel ?? 'Init pass',
    holderTitle: type.holderTitle ?? 'Appwrite developer',
    accentColor,
  }
}
