import { useState, type KeyboardEvent, type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { Browser, type Models } from '@appwrite.io/console'
import {
  ArrowUpRight,
  ExternalLink,
  Globe,
  Link2,
  Mail,
  Megaphone,
  Search,
  Sparkles,
  UserRound,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { sdk } from '@/lib/appwrite/sdk'
import { PhotoAvatar } from '@/components/global/shared/Avatar'
import {
  CountryFlagIcon,
  breakdownLeadingIconFrameClass,
} from '../../usage/_components/UsageBreakdownRows'
import { formatNumber } from './format'

const ROW_LINK_CLASS =
  'flex h-5 w-5 shrink-0 items-center justify-center rounded text-muted-foreground opacity-0 transition-opacity hover:bg-muted hover:text-foreground focus-visible:opacity-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring group-hover:opacity-100'

/** A path inside the Console, as opposed to a visitor-facing URL. */
export function isConsolePath(href: string): boolean {
  return href.startsWith('/') && !href.startsWith('//')
}

/**
 * One ranked row in a breakdown panel: a proportional background bar, an
 * optional leading badge (flag, colour dot, rank), the dimension value, its
 * share of the total and the raw count.
 *
 * Shared by every breakdown panel so the panels cannot drift apart visually.
 */
export function BreakdownRow({
  label,
  value,
  share,
  barPercent,
  leading,
  badge,
  color,
  mono = false,
  onClick,
  active = false,
  actionTitle,
  href,
  conversionRate,
  hrefTitle,
}: {
  /** Conversion rate of the selected goal for this row, 0-100; omitted when none is selected. */
  conversionRate?: number | null
  /**
   * Link for the value, shown as a small icon after the label on hover /
   * focus. An external URL (a page or hostname) opens in a new window; a
   * Console path (`/projects/...`, a user in Auth) opens in place.
   */
  href?: string
  /** Hover hint for the link icon. */
  hrefTitle?: string
  /** Small trailing badge after the label (e.g. "Plotted"). */
  badge?: ReactNode
  /** Makes the row a button (e.g. apply a page filter for this value). */
  onClick?: () => void
  /** Highlight the row, e.g. when its value is an active filter. */
  active?: boolean
  /** Hover hint for clickable rows. */
  actionTitle?: string
  label: string
  value: number
  /** Share of the column total, 0-100. */
  share: number
  /** Width of the background bar relative to the top row, 0-100. */
  barPercent: number
  leading?: ReactNode
  /** Bar colour; falls back to a neutral accent bar. */
  color?: string
  /** Render the label in a monospace face (paths, hostnames). */
  mono?: boolean
}) {
  return (
    // Fixed height (BREAKDOWN_ROW_HEIGHT_PX) so panels can reserve an exact
    // body height and never shift when tabs or data change.
    //
    // A div with role="button" rather than a <button>: the row can contain
    // the external link, and a link inside a button is invalid HTML.
    <div
      {...(onClick
        ? {
            role: 'button',
            tabIndex: 0,
            onClick,
            onKeyDown: (event: KeyboardEvent<HTMLDivElement>) => {
              if (event.target !== event.currentTarget) return
              if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                onClick()
              }
            },
            title: actionTitle,
            'aria-pressed': active,
          }
        : {})}
      className={cn(
        'group relative flex h-7 w-full items-center gap-2.5 rounded-md px-2 text-start transition-colors hover:bg-accent/50',
        onClick &&
          'cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        active && 'bg-accent/60 ring-1 ring-border',
      )}
    >
      <div
        className={cn(
          'absolute inset-y-0 start-0 rounded-md transition-all',
          color
            ? 'group-hover:opacity-80'
            : 'bg-accent/30 group-hover:bg-accent/50',
        )}
        style={{
          width: `${Math.max(0, Math.min(100, barPercent))}%`,
          ...(color ? { backgroundColor: color, opacity: 0.15 } : {}),
        }}
      />
      <div className="relative flex min-w-0 flex-1 items-center gap-2">
        {leading}
        <span
          className={cn(
            'min-w-0 truncate text-[12px] font-medium text-foreground',
            mono && 'font-mono',
          )}
          title={label}
        >
          {label}
        </span>
        {href && isConsolePath(href) ? (
          // A Console page (a user in Auth) opens in place, like any link.
          <Link
            to={href}
            onClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => event.stopPropagation()}
            title={hrefTitle}
            aria-label={hrefTitle}
            className={ROW_LINK_CLASS}
          >
            <ArrowUpRight className="h-3 w-3" />
          </Link>
        ) : href ? (
          <a
            href={href}
            target="_blank"
            rel="noopener noreferrer"
            // Opening the page must not also toggle the row's filter.
            onClick={(event) => event.stopPropagation()}
            onKeyDown={(event) => event.stopPropagation()}
            title={hrefTitle ?? href}
            aria-label={`Open ${href} in a new window`}
            className={ROW_LINK_CLASS}
          >
            <ExternalLink className="h-3 w-3" />
          </a>
        ) : null}
        {badge}
        <span className="flex-1" />
        <div className="flex shrink-0 items-center gap-3">
          {conversionRate !== undefined ? (
            <span
              className="min-w-[44px] text-end text-[11px] font-semibold tabular-nums text-emerald-600 dark:text-emerald-400"
              title="Conversion rate"
            >
              {conversionRate === null ? '–' : `${conversionRate.toFixed(1)}%`}
            </span>
          ) : null}
          <span className="text-[11px] font-medium tabular-nums text-muted-foreground">
            {Math.round(share)}%
          </span>
          <span className="min-w-[50px] text-end text-[12px] font-semibold tabular-nums text-foreground">
            {formatNumber(value)}
          </span>
        </div>
      </div>
    </div>
  )
}

/**
 * Channel names are free text from the API ("Organic Search", "Direct",
 * "AI"...). Matched loosely by keyword; anything unrecognised gets a globe.
 */
const CHANNEL_ICONS: [RegExp, LucideIcon][] = [
  [/\bai\b|assistant|llm/i, Sparkles],
  [/direct|none/i, Link2],
  [/search|organic/i, Search],
  [/social/i, Users],
  [/mail|newsletter/i, Mail],
  [/paid|ads?\b|cpc|display/i, Megaphone],
  [/referr/i, ExternalLink],
]

/** Neutral icon for a traffic channel, in the same 16px slot as favicons. */
export function ChannelIcon({ value }: { value: string | null | undefined }) {
  const Icon =
    CHANNEL_ICONS.find(([pattern]) => pattern.test(value ?? ''))?.[1] ?? Globe
  return (
    <span
      className="flex h-4 w-4 shrink-0 items-center justify-center"
      aria-hidden
    >
      <Icon className="h-3.5 w-3.5 text-muted-foreground" />
    </span>
  )
}

/** Monospace rank badge used by the page panels. */
export function RowRank({ index }: { index: number }) {
  return (
    <span className="shrink-0 font-mono text-[11px] text-muted-foreground">
      {index + 1}
    </span>
  )
}

/**
 * Flag for an ISO-3166-1 alpha-2 country code, from the Avatars service.
 *
 * Reuses the Usage breakdown's `CountryFlagIcon`, which requests flags through
 * the console client (`sdk.forConsole.avatars.getFlag`). That keeps it
 * independent of the viewed project and of the viewer's `avatars.read` scope
 * on it, the reason an earlier project-scoped fetch rendered empty boxes. The
 * shared component already falls back to a globe for unknown codes (`--`,
 * empty) or when the image fails to load.
 */
export function CountryFlag({ code }: { code: string | null | undefined }) {
  return <CountryFlagIcon countryCode={code ?? ''} />
}

/**
 * Referrer sources come back either as domains (`github.com`) or as known
 * source names (`Google`, `bing`). Names map to a domain for the favicon;
 * anything else without a dot gets the neutral globe.
 */
const SOURCE_DOMAINS: Record<string, string> = {
  google: 'google.com',
  bing: 'bing.com',
  duckduckgo: 'duckduckgo.com',
  yahoo: 'yahoo.com',
  baidu: 'baidu.com',
  yandex: 'yandex.com',
  ecosia: 'ecosia.org',
  brave: 'search.brave.com',
  github: 'github.com',
  gitlab: 'gitlab.com',
  twitter: 'x.com',
  x: 'x.com',
  'x (twitter)': 'x.com',
  facebook: 'facebook.com',
  instagram: 'instagram.com',
  linkedin: 'linkedin.com',
  reddit: 'reddit.com',
  youtube: 'youtube.com',
  tiktok: 'tiktok.com',
  discord: 'discord.com',
  slack: 'slack.com',
  medium: 'medium.com',
  'hacker news': 'news.ycombinator.com',
  hackernews: 'news.ycombinator.com',
  'product hunt': 'producthunt.com',
  producthunt: 'producthunt.com',
  gmail: 'mail.google.com',
  chatgpt: 'chatgpt.com',
  openai: 'openai.com',
  perplexity: 'perplexity.ai',
  claude: 'claude.ai',
  gemini: 'gemini.google.com',
  copilot: 'copilot.microsoft.com',
}

const DIRECT_SOURCES = new Set(['direct', 'direct / none', '(direct)', 'none'])

export function sourceFaviconHostname(
  value: string | null | undefined,
): string | null {
  const normalized = value?.trim().toLowerCase()
  if (!normalized) return null
  if (normalized.includes('.')) {
    // Strip any scheme / path the API may include.
    return normalized.replace(/^https?:\/\//, '').split('/')[0] || null
  }
  return SOURCE_DOMAINS[normalized] ?? null
}

/**
 * Favicon for a traffic source, via the Avatars service (like Usage's
 * hostnames). Drawn edge to edge in the 16px slot, with no frame or inner
 * padding: favicons carry their own shape, and framed they shrink to ~10px.
 */
export function SourceFavicon({ value }: { value: string | null | undefined }) {
  const [failed, setFailed] = useState(false)
  const isDirect = DIRECT_SOURCES.has(value?.trim().toLowerCase() ?? '')
  const hostname = isDirect ? null : sourceFaviconHostname(value)

  if (isDirect || !hostname || failed) {
    const Icon = isDirect ? Link2 : Globe
    return (
      <span
        className="flex h-4 w-4 shrink-0 items-center justify-center"
        aria-hidden
      >
        <Icon className="h-3.5 w-3.5 text-muted-foreground" />
      </span>
    )
  }

  return (
    <img
      src={sdk.forConsole.avatars.getFavicon({ url: `https://${hostname}` })}
      alt=""
      aria-hidden
      className="h-4 w-4 shrink-0 rounded-[3px] object-contain"
      onError={() => setFailed(true)}
    />
  )
}

/**
 * The analytics API reports browsers by name ("Chrome Mobile iOS"); the
 * Avatars service takes its own two-letter codes (the SDK's `Browser` enum).
 * Names are matched case-insensitively; browsers the service has no icon for
 * (Samsung Internet, Brave, ...) get a neutral globe.
 */
const BROWSER_CODES: Record<string, Browser> = {
  chrome: Browser.GoogleChrome,
  'google chrome': Browser.GoogleChrome,
  'chrome mobile': Browser.GoogleChromeMobile,
  'chrome mobile ios': Browser.GoogleChromeIOS,
  'chrome ios': Browser.GoogleChromeIOS,
  chromium: Browser.Chromium,
  firefox: Browser.MozillaFirefox,
  'mozilla firefox': Browser.MozillaFirefox,
  'firefox mobile': Browser.MozillaFirefox,
  'firefox ios': Browser.MozillaFirefox,
  'firefox focus': Browser.MozillaFirefox,
  safari: Browser.Safari,
  'mobile safari': Browser.MobileSafari,
  'safari mobile': Browser.MobileSafari,
  'microsoft edge': Browser.MicrosoftEdge,
  edge: Browser.MicrosoftEdge,
  'edge mobile': Browser.MicrosoftEdge,
  'microsoft edge ios': Browser.MicrosoftEdgeIOS,
  'edge ios': Browser.MicrosoftEdgeIOS,
  opera: Browser.Opera,
  'opera mini': Browser.OperaMini,
  'opera next': Browser.OperaNext,
  'android webview': Browser.AndroidWebViewBeta,
  'android webview beta': Browser.AndroidWebViewBeta,
  'avant browser': Browser.AvantBrowser,
}

export function browserAvatarCode(
  name: string | null | undefined,
): Browser | null {
  if (!name) return null
  return BROWSER_CODES[name.trim().toLowerCase()] ?? null
}

/** Browser icon from the Avatars service, same frame as the country flags. */
export function BrowserIcon({ name }: { name: string | null | undefined }) {
  const [failed, setFailed] = useState(false)
  const code = browserAvatarCode(name)

  if (!code || failed) {
    return (
      <span
        className={cn(
          breakdownLeadingIconFrameClass,
          'border-transparent bg-transparent',
        )}
        aria-hidden
      >
        <Globe className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
      </span>
    )
  }

  // Console client, like the flags: independent of the viewed project's scopes.
  const src = sdk.forConsole.avatars.getBrowser({
    code,
    width: 40,
    height: 40,
    quality: 100,
  })

  return (
    <span
      className={cn(
        breakdownLeadingIconFrameClass,
        'border-transparent bg-transparent',
      )}
      aria-hidden
    >
      <img
        src={src}
        alt=""
        className="h-full w-full object-contain"
        onError={() => setFailed(true)}
      />
    </span>
  )
}

/**
 * A project user's photo (initials while it loads), in the same 16px slot as
 * flags and favicons. An ID with no project user gets a neutral person icon.
 */
export function AnalyticsUserAvatar({
  projectId,
  userId,
  user,
}: {
  projectId: string
  userId: string
  user: Models.User | null | undefined
}) {
  if (!user) {
    return (
      <span
        className="flex h-4 w-4 shrink-0 items-center justify-center"
        aria-hidden
      >
        <UserRound className="h-3.5 w-3.5 text-muted-foreground" />
      </span>
    )
  }
  return (
    <PhotoAvatar
      projectId={projectId}
      userId={userId}
      name={user.name || user.email || user.phone || userId}
      size="xs"
      className="h-4 w-4 shrink-0 text-[7px]"
    />
  )
}
