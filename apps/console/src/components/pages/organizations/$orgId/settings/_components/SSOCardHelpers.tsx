import { useCallback, useRef, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { AlertCircle, AlertTriangle } from 'lucide-react'
import { toast } from 'sonner'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  ADDON_KEY_BAA,
  planSupportsAddon,
  type SupportedAddonKey,
} from '@/lib/billing/addons'
import { useI18n } from '@/lib/i18n'
import { useT } from '@/lib/i18n/translate'
import {
  organizationAddonsQueryOptions,
  organizationPlanQueryOptions,
  useBillingPlans,
  useOrganizationAddonPrice,
  useOrganizationPlan,
} from '@/lib/react-query/hooks'
import {
  isSSOAddonError,
  SSO_ADDON_REQUIRED_MESSAGE,
  ssoAddonStatus,
  type SSOAddonStatus,
} from '@/lib/sso-addon'
import { resolveOrganizationPlanDisplayLabel } from '@/lib/utils/plan-filter'

/**
 * Error text on the single sign-on screens. In the default dark theme
 * `--destructive` is a dark red meant for backgrounds (about 1.6:1 on the card),
 * so text there takes `--destructive-foreground`, the readable red. The other
 * themes use `--destructive-foreground` as the colour on a red background
 * (white, or black in high contrast), so they keep `text-destructive`.
 */
export const SSO_ERROR_TEXT =
  'text-destructive [.dark_&]:text-destructive-foreground'

/** The same for a destructive `Alert`'s description, which sets its own colour. */
export const SSO_ALERT_ERROR_TEXT = '[.dark_&]:!text-destructive-foreground'

/** A list that failed to load: say so, with a way to try again. */
export function SSOListLoadError({
  title,
  onRetry,
  retrying,
}: {
  title: string
  onRetry: () => void
  retrying: boolean
}) {
  const t = useT()
  return (
    <div className="px-6 py-4" role="alert">
      <EmptyState
        icon={AlertTriangle}
        title={title}
        description={t('Check your connection and try again.')}
        isEmpty
        variant="default"
        className="py-2"
        action={
          <Button
            variant="outline"
            size="sm"
            className="h-8 text-[13px]"
            onClick={onRetry}
            disabled={retrying}
          >
            {t('Try again')}
          </Button>
        }
      />
    </div>
  )
}

/** Whether `element` can take focus now. */
function canFocus(
  element: HTMLElement | null | undefined,
): element is HTMLElement {
  return (
    !!element &&
    element.isConnected &&
    !(element as HTMLButtonElement).disabled &&
    element.getAttribute('aria-hidden') !== 'true'
  )
}

/**
 * Where focus goes when a dialog closes. The SSO dialogs open from state (a row
 * menu, a button), not from a `DialogTrigger`, so Radix has nothing to return
 * focus to and drops it on `<body>`. `returnTo` names the candidates in order,
 * read when the dialog closes, so a control that is gone or disabled by then is
 * skipped.
 */
export function useDialogFocusReturn() {
  const candidates = useRef<Array<() => HTMLElement | null | undefined>>([])

  const returnTo = useCallback(
    (...next: Array<() => HTMLElement | null | undefined>) => {
      candidates.current = next
    },
    [],
  )

  const onCloseAutoFocus = useCallback((event: Event) => {
    for (const candidate of candidates.current) {
      const element = candidate()
      if (canFocus(element)) {
        event.preventDefault()
        element.focus()
        return
      }
    }
  }, [])

  return { returnTo, onCloseAutoFocus }
}

/**
 * Focus `element` when a dialog without a text field opens. The shared dialog
 * focuses only text fields, so a confirmation would otherwise leave focus on
 * the page behind it.
 */
export function focusOnOpen(element: () => HTMLElement | null | undefined) {
  return (event: Event) => {
    const content = event.currentTarget as HTMLElement | null
    const target = element()
    if (!target) return
    event.preventDefault()
    target.focus()
    // A row menu that opened the dialog returns focus to its trigger as it
    // closes, a task later: take it back once, after that.
    setTimeout(() => {
      if (content && !content.contains(document.activeElement)) {
        element()?.focus()
      }
    }, 0)
  }
}

/**
 * Lets a submit through once until it settles. `isPending` from React Query is
 * only seen after a re-render, so a double-click or a second Enter in the same
 * moment would otherwise send the request twice.
 */
export function useSingleSubmit() {
  const busy = useRef(false)
  return useCallback((start: (settle: () => void) => void): boolean => {
    if (busy.current) return false
    busy.current = true
    start(() => {
      busy.current = false
    })
    return true
  }, [])
}

/**
 * Whether the organization can set up single sign-on: it is part of the HIPAA
 * BAA add-on, so it needs that add-on active. `locked` only once the plan and
 * the add-ons are known: setup is never refused on a guess. `enabled: false`
 * leaves the add-ons unread, for a page that may not show single sign-on.
 */
export function useSSOAddonGate(orgId: string, enabled = true) {
  const { features } = useConsoleProfile()
  const { plan, isFetched } = useOrganizationPlan(orgId)
  const addons = useQuery(
    organizationAddonsQueryOptions(enabled && features.billing ? orgId : null),
  )
  const known = isFetched && !!plan && addons.isSuccess
  const status: SSOAddonStatus | null = known
    ? ssoAddonStatus(plan, addons.data?.addons, ADDON_KEY_BAA)
    : null
  return {
    known,
    status,
    locked: known && status !== 'active',
    planName: resolveOrganizationPlanDisplayLabel({
      planName: plan?.name ?? null,
      planId: plan?.$id,
    }),
  }
}

/** Where the organization's HIPAA BAA add-on is enabled, and its payment confirmed. */
function complianceRoute(orgId: string) {
  return {
    to: '/organizations/$orgId/settings/compliance',
    params: { orgId },
  } as const
}

/**
 * A setup request the API refused because the HIPAA BAA add-on is not active
 * (it ended while the page was open): say so with the way to it, and reload the
 * plan and the add-ons so the page shows its locked state. Returns whether it
 * was that refusal.
 */
export function useSSOAddonErrorHandler(orgId: string) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const { status } = useSSOAddonGate(orgId)
  return useCallback(
    (error: unknown): boolean => {
      if (!isSSOAddonError(error)) return false
      // On a plan without the add-on, the upgrade; else the add-on itself.
      const action = !features.billing
        ? undefined
        : status === 'unavailable'
          ? {
              label: t('Upgrade'),
              onClick: () => navigate({ to: '/upgrade', search: { orgId } }),
            }
          : features.compliance
            ? {
                label: t('View add-on'),
                onClick: () => navigate(complianceRoute(orgId)),
              }
            : undefined
      toast.error(t(SSO_ADDON_REQUIRED_MESSAGE), { action })
      void queryClient.invalidateQueries({
        queryKey: organizationPlanQueryOptions(orgId).queryKey,
      })
      void queryClient.invalidateQueries({
        queryKey: organizationAddonsQueryOptions(orgId).queryKey,
      })
      return true
    },
    [features, navigate, orgId, queryClient, status, t],
  )
}

/** A setup control the add-on does not allow yet: disabled, with the reason. */
export function SSOLockedTooltip({
  locked,
  children,
}: {
  locked: boolean
  children: ReactNode
}) {
  const t = useT()
  if (!locked) return children
  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex" tabIndex={0}>
            {children}
          </span>
        </TooltipTrigger>
        <TooltipContent className="max-w-xs text-xs">
          {t('Single sign-on is part of the HIPAA BAA add-on')}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

/** The plans that sell `addon`, cheapest first, as one list in `language`. */
function usePlansSellingAddon(addon: SupportedAddonKey): string | null {
  const { language } = useI18n()
  const { plans } = useBillingPlans()
  const names = Object.values(plans)
    .filter((plan) => planSupportsAddon(plan, addon))
    .sort((a, b) => a.order - b.order)
    .map((plan) => plan.name)
    .filter((name, index, all) => !!name && all.indexOf(name) === index)
  if (names.length === 0) return null
  return new Intl.ListFormat(language, { type: 'conjunction' }).format(names)
}

const NOTICE_ACTION_CLASS =
  'h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400'

/**
 * Single sign-on without an active HIPAA BAA add-on, in the form project
 * environments use. Without `paused` nothing is set up yet, and the notice
 * stands for the page: what the add-on costs and how to get it. With `paused`
 * the organization kept domains, identity providers or Require SSO, listed
 * below it to delete and turn off.
 *
 * - `available`: the plan sells the add-on; `onEnable` opens its dialog.
 * - `pending`: its payment awaits confirmation, on the Compliance page.
 * - `unavailable`: the plan does not sell it; the upgrade.
 */
export function SSOAddonNotice({
  orgId,
  status,
  planName,
  paused,
  onEnable,
}: {
  orgId: string
  status: Exclude<SSOAddonStatus, 'active'>
  planName: string
  paused: boolean
  onEnable: () => void
}) {
  const t = useT()
  const { features } = useConsoleProfile()
  const { addonPrice } = useOrganizationAddonPrice(
    status === 'available' && !paused ? orgId : null,
    ADDON_KEY_BAA,
  )
  const sellers = usePlansSellingAddon(ADDON_KEY_BAA)

  const title = paused
    ? t('Single sign-on is paused')
    : t('Single sign-on is part of the HIPAA BAA add-on')

  const description = paused
    ? [
        status === 'available'
          ? t('The HIPAA BAA add-on is not active.')
          : status === 'pending'
            ? t(
                'The payment for the HIPAA BAA add-on is awaiting confirmation.',
              )
            : t(
                'Your plan ({plan}) does not include the HIPAA BAA add-on.',
              ).replace('{plan}', planName),
        t(
          'Until the add-on is active, nobody signs in through your identity providers and Require SSO has no effect. You can still delete domains and identity providers, and turn Require SSO off.',
        ),
      ].join(' ')
    : status === 'available'
      ? addonPrice
        ? t(
            'The add-on costs {price}/month, prorated for your current billing cycle. Enable it to verify your domains and connect identity providers, so people on your domains sign in with SSO.',
          ).replace(
            '{price}',
            formatCurrency(addonPrice.monthlyPrice, addonPrice.currency),
          )
        : t(
            'Enable the add-on to verify your domains and connect identity providers, so people on your domains sign in with SSO.',
          )
      : status === 'pending'
        ? t(
            'The payment for the add-on is awaiting confirmation. Single sign-on can be set up once it is confirmed.',
          )
        : sellers
          ? t(
              'The add-on is available on {plans}. Upgrade to verify your domains and connect identity providers, so people on your domains sign in with SSO.',
            ).replace('{plans}', sellers)
          : t(
              'Your plan ({plan}) does not include the add-on. Upgrade to verify your domains and connect identity providers, so people on your domains sign in with SSO.',
            ).replace('{plan}', planName)

  const action = !features.billing ? null : status === 'available' ? (
    <Button size="sm" className={NOTICE_ACTION_CLASS} onClick={onEnable}>
      {t('Enable add-on')}
    </Button>
  ) : status === 'pending' ? (
    features.compliance ? (
      <Button asChild size="sm" className={NOTICE_ACTION_CLASS}>
        <Link {...complianceRoute(orgId)}>{t('View add-on')}</Link>
      </Button>
    ) : null
  ) : (
    <Button asChild size="sm" className={NOTICE_ACTION_CLASS}>
      <Link
        to="/upgrade"
        search={{ orgId }}
        {...analyticsAttrs('upgrade-clicked')}
      >
        {t('Upgrade')}
      </Link>
    </Button>
  )

  return (
    <Alert className="border-amber-500/30 bg-amber-500/5">
      <AlertCircle className="h-4 w-4 text-amber-500" />
      <div className="flex flex-1 items-start justify-between gap-4">
        <div className="min-w-0 flex-1">
          <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
            {title}
          </AlertTitle>
          <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
            {description}
          </AlertDescription>
        </div>
        {orgId ? action : null}
      </div>
    </Alert>
  )
}
