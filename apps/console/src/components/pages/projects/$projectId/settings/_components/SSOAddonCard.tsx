'use client'

import { useMemo, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import {
  ADDON_KEY_SSO,
  findActiveOrPendingAddon,
  hasUpgradeablePlanWithAddon,
  isAddonScheduledForRemoval,
} from '@/lib/billing/addons'
import { enableSSOAddon } from '@/lib/billing/enable-sso-addon'
import { refetchOrganizationBillingQueries } from '@/lib/billing/refetch-organization-billing-queries'
import { canShowProjectSettings } from '@/lib/console-access-checks'
import {
  projectAddonsQueryOptions,
  useBillingPlans,
  useOrganizationById,
  useOrganizationPlan,
  useOrganizationScopes,
  useProject,
  useProjectAddonPrice,
} from '@/lib/react-query/hooks'
import { navigateToUpgradeWizard } from '@/lib/open-upgrade-wizard'
import { SSO_ADDON_DESCRIPTION, ssoAddonStatus } from '@/lib/sso-addon'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { EnableSSOAddonDialog } from './EnableSSOAddonDialog'
import { DisableSSOAddonDialog } from './DisableSSOAddonDialog'

/** The API refused to say: the caller may not read the project's add-ons. */
function isPermissionError(error: unknown): boolean {
  const code = (error as { code?: number } | null)?.code
  return code === 401 || code === 403
}

type SSOAddonCardProps = {
  projectId: string
  /**
   * The page is an environment's settings. An environment has the add-on of
   * its project, so the card only says whether it is on.
   */
  inEnvironment?: boolean
}

/**
 * The project's Single sign-on add-on. It lets the project's teams verify
 * domains, connect identity providers and turn on Require SSO through the
 * Teams API; signing in with single sign-on is for the Console only, so the
 * card says nothing about it. Built like the Premium Geo DB card, and behind
 * the single sign-on experiment with the rest of it.
 */
export function SSOAddonCard({
  projectId,
  inEnvironment = false,
}: SSOAddonCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const shown = features.billing && features.sso
  const { project } = useProject(projectId)
  const orgId = project?.teamId
  const { organization } = useOrganizationById(orgId)
  const planQuery = useOrganizationPlan(shown ? orgId : null)
  const { plans } = useBillingPlans()
  const { access } = useOrganizationScopes(orgId)
  const addonsQuery = useQuery(
    projectAddonsQueryOptions(shown ? projectId : null),
  )
  const { addonPrice } = useProjectAddonPrice(
    shown && !inEnvironment ? projectId : null,
    ADDON_KEY_SSO,
  )
  const [showEnable, setShowEnable] = useState(false)
  const [showDisable, setShowDisable] = useState(false)
  const [reEnabling, setReEnabling] = useState(false)
  const [refreshing, setRefreshing] = useState(false)

  const plan = planQuery.plan
  const addons = addonsQuery.data?.addons
  const ssoAddon = useMemo(
    () => findActiveOrPendingAddon(addons, ADDON_KEY_SSO),
    [addons],
  )
  const status = ssoAddonStatus(plan, addons, ADDON_KEY_SSO)
  const isScheduledForRemoval = isAddonScheduledForRemoval(ssoAddon)
  const canUpgrade =
    status === 'unavailable' &&
    hasUpgradeablePlanWithAddon(plan, plans, ADDON_KEY_SSO)
  const canManage = canShowProjectSettings(access, features)
  const monthlyPriceLabel = addonPrice
    ? formatCurrency(addonPrice.monthlyPrice, addonPrice.currency)
    : null

  const loading =
    !project || addonsQuery.isLoading || (!!orgId && planQuery.isLoading)
  const denied = isPermissionError(addonsQuery.error)
  const failed = !denied && (addonsQuery.isError || !!planQuery.error)

  if (!shown) return null

  const refreshAddonQueries = () =>
    Promise.all([
      queryClient.refetchQueries({
        queryKey: projectAddonsQueryOptions(projectId).queryKey,
      }),
      queryClient.refetchQueries({ queryKey: ['project', projectId] }),
      ...(orgId ? [refetchOrganizationBillingQueries(queryClient, orgId)] : []),
    ])

  const handleRetry = () => {
    void addonsQuery.refetch()
    if (planQuery.error) void planQuery.refetch()
  }

  // A payment waiting for 3-D Secure: check whether it went through
  const handleRefresh = async () => {
    if (!ssoAddon) return
    setRefreshing(true)
    try {
      await sdk.forConsole.projects.confirmAddonPayment({
        projectId,
        addonId: ssoAddon.$id,
      })
      await refreshAddonQueries()
      toast.success(t('Single sign-on add-on has been enabled'))
    } catch (error) {
      const candidate = error as {
        type?: string
        code?: number
        message?: string
      }
      if (candidate?.type === 'addon_not_found' || candidate?.code === 404) {
        await refreshAddonQueries()
        toast.success(t('Single sign-on add-on has been enabled'))
        return
      }
      if (candidate?.code === 402) {
        await addonsQuery.refetch()
        toast.error(
          candidate.message ??
            t(
              'Payment could not be authorized. Please try enabling the addon again.',
            ),
        )
        return
      }
      toast.error(getErrorMessage(error))
    } finally {
      setRefreshing(false)
    }
  }

  // Scheduled for removal: enabling it again keeps it
  const handleReEnable = async () => {
    setReEnabling(true)
    try {
      if (!organization?.$id) {
        throw new Error(
          t(
            'Add a payment method to your organization before enabling this addon.',
          ),
        )
      }
      await enableSSOAddon({
        target: { scope: 'project', projectId },
        organizationId: organization.$id,
        paymentMethodId: organization.paymentMethodId,
        missingPaymentMethodMessage: t(
          'Add a payment method to your organization before enabling this addon.',
        ),
        queryClient,
      })
      toast.success(t('Single sign-on add-on has been re-enabled'))
    } catch (error) {
      toast.error(getErrorMessage(error))
    } finally {
      setReEnabling(false)
    }
  }

  const statusBadge = (() => {
    if (loading) return <Skeleton className="h-4 w-16 rounded-full" />
    if (denied || failed) return null
    if (status === 'unavailable') {
      return (
        <Badge variant="info" className="text-[10px] shrink-0">
          {t('Unavailable')}
        </Badge>
      )
    }
    if (status === 'pending') {
      return (
        <Badge variant="warning" className="text-[10px] shrink-0">
          {t('Payment pending')}
        </Badge>
      )
    }
    if (status === 'active' && isScheduledForRemoval) {
      return (
        <Badge variant="warning" className="text-[10px] shrink-0">
          {t('Scheduled for removal')}
        </Badge>
      )
    }
    if (status === 'active') {
      return (
        <Badge variant="success" className="text-[10px] shrink-0">
          {t('Active')}
        </Badge>
      )
    }
    return (
      <Badge variant="info" className="text-[10px] shrink-0">
        {t('Not enabled')}
      </Badge>
    )
  })()

  const statusCopy = (() => {
    if (loading) return null
    if (denied) {
      return t("You don't have permission to manage this project's add-ons.")
    }
    if (failed) return t("Couldn't load the add-on's status.")
    if (inEnvironment) {
      return t(
        "This environment has the add-on of its project. Change it in the project's settings.",
      )
    }
    if (!canManage) {
      return t("You don't have permission to manage this project's add-ons.")
    }
    if (status === 'unavailable' && canUpgrade) {
      return t(
        'This add-on is not available on your current plan. Upgrade your plan to enable it.',
      )
    }
    if (status === 'unavailable') {
      return t('This add-on is not available on your current plan.')
    }
    if (status === 'pending') {
      return t(
        "A payment is awaiting confirmation. If you've completed authentication, click refresh to check the payment status.",
      )
    }
    if (status === 'active' && isScheduledForRemoval) {
      return t(
        'The add-on will be removed at the end of your current billing cycle.',
      )
    }
    if (status === 'active') return null
    return monthlyPriceLabel
      ? t('{price}/month, prorated for the current billing cycle.').replace(
          '{price}',
          monthlyPriceLabel,
        )
      : t('Billed monthly, prorated for the current billing cycle.')
  })()

  const footerAction = (() => {
    if (loading || denied) return null
    if (failed) {
      return (
        <Button
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          disabled={addonsQuery.isFetching}
          onClick={handleRetry}
        >
          {t('Try again')}
        </Button>
      )
    }
    if (inEnvironment || !canManage) return null
    if (status === 'unavailable') {
      return canUpgrade ? (
        <Button
          size="sm"
          className="h-9 text-[13px]"
          onClick={() => navigateToUpgradeWizard(navigate, orgId)}
        >
          {t('Upgrade plan')}
        </Button>
      ) : null
    }
    if (status === 'pending') {
      return (
        <Button
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          disabled={refreshing}
          onClick={() => void handleRefresh()}
        >
          {t('Refresh')}
        </Button>
      )
    }
    if (status === 'active' && isScheduledForRemoval) {
      return (
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={reEnabling}
          onClick={() => void handleReEnable()}
        >
          {t('Keep add-on')}
        </Button>
      )
    }
    if (status === 'active') {
      return (
        <Button
          variant="outline"
          size="sm"
          className="h-9 text-[13px]"
          onClick={() => setShowDisable(true)}
        >
          {t('Disable')}
        </Button>
      )
    }
    return (
      <Button
        size="sm"
        className="h-9 text-[13px]"
        onClick={() => setShowEnable(true)}
      >
        {t('Enable add-on')}
      </Button>
    )
  })()

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Single sign-on')}
            </h3>
            {statusBadge}
          </div>
        </div>

        <div className="border-t border-border" />

        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground max-w-2xl">
            {t(SSO_ADDON_DESCRIPTION)}
          </p>
        </div>

        {statusCopy ? (
          <>
            <div className="border-t border-border" />
            <div className="px-6 py-4">
              <p className="text-[13px] text-muted-foreground">{statusCopy}</p>
            </div>
          </>
        ) : null}

        {footerAction ? (
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-wrap items-center gap-3">
            {footerAction}
            {status === 'active' &&
            !isScheduledForRemoval &&
            monthlyPriceLabel ? (
              <span className="text-[13px] text-muted-foreground">
                {t('{price}/month').replace('{price}', monthlyPriceLabel)}
              </span>
            ) : null}
          </div>
        ) : null}
      </div>

      {!inEnvironment ? (
        <EnableSSOAddonDialog
          open={showEnable}
          onOpenChange={setShowEnable}
          projectId={projectId}
        />
      ) : null}
      {ssoAddon && !inEnvironment ? (
        <DisableSSOAddonDialog
          open={showDisable}
          onOpenChange={setShowDisable}
          projectId={projectId}
          addonId={ssoAddon.$id}
        />
      ) : null}
    </>
  )
}
