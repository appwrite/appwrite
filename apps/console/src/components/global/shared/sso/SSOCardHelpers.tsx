import { useCallback, useRef, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from '@tanstack/react-router'
import { AlertTriangle } from 'lucide-react'
import { toast } from 'sonner'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { SSO_ADDON_CARD_ID } from '@/components/global/shared/sso-addon/use-sso-addon'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { ADDON_KEY_SSO, ADDON_KEY_SSO_ORG } from '@/lib/billing/addons'
import { useT } from '@/lib/i18n/translate'
import {
  organizationAddonsQueryOptions,
  organizationPlanQueryOptions,
  projectAddonsQueryOptions,
  useOrganizationPlan,
  useProject,
  useSSODomains,
  useSSOProviders,
  useSSORequired,
} from '@/lib/react-query/hooks'
import {
  isSSOAddonError,
  PROJECT_SSO_ADDON_REQUIRED_MESSAGE,
  SSO_ADDON_REQUIRED_MESSAGE,
  ssoAddonStatus,
  type SSOAddonStatus,
} from '@/lib/sso-addon'
import type { SSOTarget } from '@/lib/sso-target'

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
 * The organization that pays for `target`'s add-on: the organization itself,
 * or the project's, once the project is read.
 */
function useBillingOrganizationId(target: SSOTarget): string | undefined {
  const { project } = useProject(
    target.scope === 'project' ? target.projectId : undefined,
  )
  return target.scope === 'organization'
    ? target.organizationId
    : project?.teamId
}

/**
 * Whether `target` can set up single sign-on: an organization needs its own
 * Single sign-on add-on (`ssoOrg`) active, a team of a project its project's
 * (`sso`), on the plan of the project's organization. `locked` only once the
 * plan and the add-ons are known: setup is never refused on a guess.
 * `enabled: false` leaves the add-ons unread, for a page that may not show
 * single sign-on.
 */
export function useSSOAddonGate(target: SSOTarget, enabled = true) {
  const { features } = useConsoleProfile()
  const orgId = useBillingOrganizationId(target)
  const { plan, isFetched } = useOrganizationPlan(orgId)
  const read = enabled && features.billing
  const addons = useQuery(
    target.scope === 'organization'
      ? organizationAddonsQueryOptions(read ? orgId : null)
      : projectAddonsQueryOptions(read ? target.projectId : null),
  )
  const known = isFetched && !!plan && addons.isSuccess
  const status: SSOAddonStatus | null = known
    ? ssoAddonStatus(
        plan,
        addons.data?.addons,
        target.scope === 'organization' ? ADDON_KEY_SSO_ORG : ADDON_KEY_SSO,
      )
    : null
  return { known, status, locked: known && status !== 'active' }
}

/**
 * What a Single sign-on page shows. With the add-on not active and nothing
 * set up, only the add-on's card; with something set up before, the setup
 * cards stay listed, locked, to delete, and the card says it is paused.
 */
export function useSSOPageState(target: SSOTarget) {
  const gate = useSSOAddonGate(target)
  const { domains, isSuccess: domainsLoaded } = useSSODomains(target)
  const { providers, isSuccess: providersLoaded } = useSSOProviders(target)
  const required = useSSORequired(target)
  const nothingSetUp =
    domainsLoaded &&
    providersLoaded &&
    domains.length === 0 &&
    providers.length === 0 &&
    !required
  return {
    gate,
    nothingSetUp,
    // Unknown (not read yet, or no billing) is not locked: the server decides.
    showSetup: !gate.locked || !nothingSetUp,
    paused: gate.locked && !nothingSetUp,
  }
}

/**
 * Bring the Single sign-on add-on's card on this page into view, and focus
 * what it offers (enable, refresh a payment, upgrade).
 */
function showAddonCard() {
  const card = document.querySelector<HTMLElement>(
    `[data-card-id="${SSO_ADDON_CARD_ID}"]`,
  )
  if (!card) return
  card.scrollIntoView({ behavior: 'smooth', block: 'start' })
  card
    .querySelector<HTMLElement>('[data-sso-addon-actions] button')
    ?.focus({ preventScroll: true })
}

/**
 * A setup request the API refused because the Single sign-on add-on is not
 * active (it ended while the page was open): say so with the way to it, and
 * reload the plan and the add-ons so the page shows its locked state. Returns
 * whether it was that refusal.
 */
export function useSSOAddonErrorHandler(target: SSOTarget) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const orgId = useBillingOrganizationId(target)
  const { status } = useSSOAddonGate(target)
  const projectId = target.scope === 'project' ? target.projectId : null
  return useCallback(
    (error: unknown): boolean => {
      if (!isSSOAddonError(error)) return false
      // On a plan without the add-on, the upgrade; else its card on this page.
      const action = !features.billing
        ? undefined
        : status === 'unavailable' && orgId
          ? {
              label: t('Upgrade'),
              onClick: () => navigate({ to: '/upgrade', search: { orgId } }),
            }
          : { label: t('View add-on'), onClick: showAddonCard }
      toast.error(
        t(
          projectId
            ? PROJECT_SSO_ADDON_REQUIRED_MESSAGE
            : SSO_ADDON_REQUIRED_MESSAGE,
        ),
        { action },
      )
      if (orgId) {
        void queryClient.invalidateQueries({
          queryKey: organizationPlanQueryOptions(orgId).queryKey,
        })
      }
      void queryClient.invalidateQueries({
        queryKey: projectId
          ? projectAddonsQueryOptions(projectId).queryKey
          : organizationAddonsQueryOptions(orgId).queryKey,
      })
      return true
    },
    [features, navigate, orgId, projectId, queryClient, status, t],
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
          {t('Needs the Single sign-on add-on')}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}
