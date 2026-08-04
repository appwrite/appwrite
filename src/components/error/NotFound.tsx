import {
  Link,
  useCanGoBack,
  useLocation,
  useMatches,
  useRouter,
  type AnyRouteMatch,
} from '@tanstack/react-router'
import { useEffect } from 'react'
import { ArrowLeft, FileQuestion, Home } from 'lucide-react'
import { ConsoleLayout } from '@/components/global/layout/ConsoleLayout'
import { StandaloneCommandCenterScope } from '@/components/global/providers/KeyboardShortcuts'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { isMarketingPagePath } from '@/lib/marketing/is-marketing-page'
import { getLegacyRedirectTarget } from '@/lib/seo/legacy-redirects'
import { MarketingPageShell } from '@/lib/marketing/MarketingPageShell'
import { getMarketingPageUrl } from '@/lib/marketing/urls'
import { openInNewWindow } from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'
import { pageTitle } from '@/lib/utils/page-title'
import { useT } from '@/lib/i18n/translate'

const CONSOLE_AREA_PREFIXES = new Set([
  'projects',
  'organizations',
  'account',
  'blocks',
  'init',
  'generator',
  'assistant',
  'agent',
])

function isConsoleAreaPath(pathname: string): boolean {
  const firstSegment = pathname.split('/').filter(Boolean)[0]
  return firstSegment ? CONSOLE_AREA_PREFIXES.has(firstSegment) : false
}

function isDocsPath(pathname: string): boolean {
  const normalized = pathname.replace(/\/+$/, '') || '/'
  return normalized === '/docs' || normalized.startsWith('/docs/')
}

/**
 * Org layout skips OrgOverview (and its ConsoleLayout) for these children.
 * Mirror `organizations.$orgId.tsx` so nested 404s still get a single shell.
 */
function isOrgOutletOnlyPath(
  pathname: string,
  matches: AnyRouteMatch[],
): boolean {
  if (
    pathname.includes('/domains/buy') ||
    pathname.includes('/domains/transfer-in') ||
    pathname === '/upgrade'
  ) {
    return true
  }

  return matches.some(
    (match) =>
      match.routeId.includes('/domains/$domainId') ||
      match.routeId.includes('/marketplace/$appId') ||
      match.routeId.includes('/apps/$appId') ||
      match.routeId.includes('/support'),
  )
}

/**
 * True when a matched parent already renders ConsoleLayout (or equivalent chrome)
 * around the not-found outlet. Returning a second shell duplicates header/footer.
 */
function shouldRenderContentOnly(
  pathname: string,
  matches: AnyRouteMatch[],
): boolean {
  if (isDocsPath(pathname)) return true

  if (matches.some((m) => m.routeId === '/_public/projects/$projectId')) {
    return true
  }

  if (matches.some((m) => m.routeId === '/_public/account')) {
    return true
  }

  // App detail layout wraps <Outlet /> in ConsoleLayout.
  if (
    matches.some(
      (m) => m.routeId === '/_public/organizations/$orgId/apps/$appId',
    )
  ) {
    return true
  }

  // Org overview wraps the outlet unless an outlet-only child is active.
  if (matches.some((m) => m.routeId === '/_public/organizations/$orgId')) {
    return !isOrgOutletOnlyPath(pathname, matches)
  }

  return false
}

function shouldUseMarketingShell(
  pathname: string,
  marketingEnabled: boolean,
): boolean {
  if (!marketingEnabled) return false
  if (isMarketingPagePath(pathname)) return true
  return !isConsoleAreaPath(pathname)
}

function getHomeHref(pathname: string, marketingEnabled: boolean): string {
  if (
    marketingEnabled &&
    (isMarketingPagePath(pathname) || !isConsoleAreaPath(pathname))
  ) {
    return '/home'
  }

  return '/'
}

function NotFoundContent({
  homeHref,
  canGoBack,
  onGoBack,
  docsHref,
  docsExternal,
}: {
  homeHref: string
  canGoBack: boolean
  onGoBack: () => void
  docsHref: string
  docsExternal: boolean
}) {
  const t = useT()
  return (
    <div className="grid min-h-full w-full place-items-center px-4 py-24 sm:px-6 sm:py-32 lg:py-40">
      <div className="flex w-full max-w-md flex-col items-center gap-8">
        <div className="rounded-full bg-muted p-3 ring-1 ring-border">
          <FileQuestion
            className="h-8 w-8 text-muted-foreground"
            aria-hidden
          />
        </div>

        <div className="space-y-3 text-center">
          <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            404
          </p>
          <h1 className="text-2xl font-semibold text-foreground">
            {t('Page not found')}
          </h1>
          <p className="text-[14px] leading-relaxed text-muted-foreground">
            {t(
              'The page you requested does not exist, may have been moved, or is temporarily unavailable.',
            )}
          </p>
        </div>

        <div className="flex w-full flex-col gap-3 sm:flex-row">
          {canGoBack ? (
            <Button
              variant="outline"
              onClick={onGoBack}
              className="min-h-9 w-full shrink-0 sm:flex-1"
            >
              <ArrowLeft className="me-1.5 h-4 w-4" />
              {t('Go back')}
            </Button>
          ) : null}
          <Button
            variant="brandCta"
            size="sm"
            className={cn(
              'h-9 min-h-9 w-full shrink-0 gap-2 text-[13px] font-medium sm:flex-1',
              !canGoBack && 'sm:w-full',
            )}
            asChild
          >
            <Link to={homeHref}>
              <Home className="h-4 w-4" />
              {t('Go home')}
            </Link>
          </Button>
        </div>

        <p className="text-center text-[13px] text-muted-foreground">
          {t('Looking for product docs?')}{' '}
          {docsExternal ? (
            <a
              href={docsHref}
              className="link-neutral"
              onClick={(event) => {
                event.preventDefault()
                openInNewWindow(docsHref)
              }}
            >
              {t('Browse documentation')}
            </a>
          ) : (
            <Link
              to={docsHref}
              className="link-neutral"
            >
              {t('Browse documentation')}
            </Link>
          )}
        </p>
      </div>
    </div>
  )
}

/** Content-only 404 for routes that already provide header/footer (e.g. /docs layout). */
export function NotFoundView() {
  const t = useT()
  const router = useRouter()
  const canGoBack = useCanGoBack()
  const location = useLocation()
  const { features } = useConsoleProfile()

  const homeHref = getHomeHref(location.pathname, features.marketing)
  const docsHref = getMarketingPageUrl('/docs', features.marketing)
  const docsExternal = !features.marketing

  // Legacy website URLs (client-side navigation; server middleware 301s full
  // page loads before they ever reach this view).
  const legacyTarget = getLegacyRedirectTarget(location.pathname)

  useEffect(() => {
    if (!legacyTarget) return
    const hashIndex = legacyTarget.indexOf('#')
    const pathname =
      hashIndex === -1 ? legacyTarget : legacyTarget.slice(0, hashIndex)
    const hash = hashIndex === -1 ? undefined : legacyTarget.slice(hashIndex + 1)
    void router.navigate({ to: pathname as never, hash, replace: true })
  }, [legacyTarget, router])

  useEffect(() => {
    if (typeof document === 'undefined') return
    if (legacyTarget) return
    document.title = pageTitle(t('Page not found'))
  }, [t, legacyTarget])

  if (legacyTarget) {
    return null
  }

  return (
    <div className="flex min-h-full w-full flex-1 flex-col">
      <NotFoundContent
        homeHref={homeHref}
        canGoBack={canGoBack}
        onGoBack={() => router.history.back()}
        docsHref={docsHref}
        docsExternal={docsExternal}
      />
    </div>
  )
}

/** Full-page 404 with layout shell for unmatched routes and leaf routes without a parent shell. */
export function NotFound() {
  const location = useLocation()
  const matches = useMatches()
  const { features } = useConsoleProfile()

  // Parent layout already provides header/footer (docs, project, account, org overview, app detail).
  if (shouldRenderContentOnly(location.pathname, matches)) {
    return <NotFoundView />
  }

  const useMarketingLayout = shouldUseMarketingShell(
    location.pathname,
    features.marketing,
  )

  const view = <NotFoundView />

  if (useMarketingLayout) {
    return <MarketingPageShell>{view}</MarketingPageShell>
  }

  return (
    <StandaloneCommandCenterScope context="account">
      <ConsoleLayout showFooter>{view}</ConsoleLayout>
    </StandaloneCommandCenterScope>
  )
}
