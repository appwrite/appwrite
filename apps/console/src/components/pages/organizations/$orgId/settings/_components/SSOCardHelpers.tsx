import { useCallback, useRef, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate } from '@tanstack/react-router'
import { AlertCircle, AlertTriangle } from 'lucide-react'
import { toast } from 'sonner'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { getActiveProfileFeatures } from '@/lib/console-profiles'
import { useT } from '@/lib/i18n/translate'
import {
  organizationPlanQueryOptions,
  useOrganizationPlan,
} from '@/lib/react-query/hooks'
import {
  isSSOPlanError,
  planSupportsSSO,
  SSO_PLAN_REQUIRED_MESSAGE,
} from '@/lib/sso-plan'
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
 * Whether the organization's plan includes single sign-on (Pro and above).
 * `locked` only once the plan is known: setup is never refused on a guess.
 */
export function useSSOPlanGate(orgId: string) {
  const { plan, isFetched } = useOrganizationPlan(orgId)
  const known = isFetched && !!plan
  const supported = planSupportsSSO(plan)
  return {
    known,
    supported,
    locked: known && !supported,
    planName: resolveOrganizationPlanDisplayLabel({
      planName: plan?.name ?? null,
      planId: plan?.$id,
    }),
  }
}

/**
 * A setup request the API refused because the plan no longer includes single
 * sign-on (it changed while the page was open): say so with the upgrade, and
 * reload the plan so the page shows its locked state. Returns whether it was
 * that refusal.
 */
export function useSSOPlanErrorHandler(orgId: string) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  return useCallback(
    (error: unknown): boolean => {
      if (!isSSOPlanError(error)) return false
      toast.error(t(SSO_PLAN_REQUIRED_MESSAGE), {
        action: getActiveProfileFeatures().billing
          ? {
              label: t('Upgrade'),
              onClick: () => navigate({ to: '/upgrade', search: { orgId } }),
            }
          : undefined,
      })
      void queryClient.invalidateQueries({
        queryKey: organizationPlanQueryOptions(orgId).queryKey,
      })
      return true
    },
    [navigate, orgId, queryClient, t],
  )
}

/** A setup control the plan does not allow: disabled, with the reason. */
export function PlanLockedTooltip({
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
          {t('Single sign-on is available on Pro')}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

/**
 * Single sign-on on a plan without it, in the form project environments use:
 * `upgrade` when nothing is set up yet, `paused` when the organization kept its
 * domains or identity providers from Pro.
 */
export function SSOPlanNotice({
  orgId,
  planName,
  variant,
}: {
  orgId: string
  planName: string
  variant: 'upgrade' | 'paused'
}) {
  const t = useT()
  const canUpgrade = !!orgId && getActiveProfileFeatures().billing
  return (
    <Alert className="border-amber-500/30 bg-amber-500/5">
      <AlertCircle className="h-4 w-4 text-amber-500" />
      <div className="flex flex-1 items-start justify-between gap-4">
        <div className="min-w-0 flex-1">
          <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
            {variant === 'upgrade'
              ? t('Single sign-on is available on Pro')
              : t('Single sign-on is paused')}
          </AlertTitle>
          <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
            {(variant === 'upgrade'
              ? t(
                  'Your plan ({plan}) does not include single sign-on. Upgrade to verify your domains and connect identity providers, so people on your domains sign in with SSO.',
                )
              : t(
                  'Your plan ({plan}) does not include single sign-on, so nobody signs in through your identity providers and Require SSO has no effect until the organization is back on Pro. You can still delete domains and identity providers, and turn Require SSO off.',
                )
            ).replace('{plan}', planName)}
          </AlertDescription>
        </div>
        {canUpgrade ? (
          <Button
            asChild
            size="sm"
            className="h-8 shrink-0 bg-amber-500 px-3 text-[12px] font-medium text-amber-950 hover:bg-amber-400 dark:bg-amber-500 dark:text-amber-950 dark:hover:bg-amber-400"
          >
            <Link
              to="/upgrade"
              search={{ orgId }}
              {...analyticsAttrs('upgrade-clicked')}
            >
              {t('Upgrade')}
            </Link>
          </Button>
        ) : null}
      </div>
    </Alert>
  )
}
