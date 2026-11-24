'use client'

import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { AlertCircle } from 'lucide-react'
import {
  hasUpgradeablePlanWithAddon,
  isAddonScheduledForRemoval,
} from '@/lib/billing/addons'
import {
  confirmAddonPayment,
  refreshAddonQueries,
} from '@/lib/billing/enable-addon'
import { enableSSOAddon } from '@/lib/billing/enable-sso-addon'
import { canShowProjectSettings } from '@/lib/console-access-checks'
import { useBillingPlans, useOrganizationScopes } from '@/lib/react-query/hooks'
import { navigateToUpgradeWizard } from '@/lib/open-upgrade-wizard'
import {
  ORGANIZATION_SSO_ADDON_DESCRIPTION,
  SSO_ADDON_DESCRIPTION,
} from '@/lib/sso-addon'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { EnableSSOAddonDialog } from './EnableSSOAddonDialog'
import { DisableSSOAddonDialog } from './DisableSSOAddonDialog'
import {
  toAddonTarget,
  usePlansSellingAddon,
  useSSOAddon,
  type SSOAddonTarget,
} from './use-sso-addon'

/** The card on a page, for what points to it (`[data-card-id="sso-addon"]`). */
export const SSO_ADDON_CARD_ID = 'sso-addon'

/** The API refused to say: the caller may not read the add-ons. */
function isPermissionError(error: unknown): boolean {
  const code = (error as { code?: number } | null)?.code
  return code === 401 || code === 403
}

type SSOAddonCardProps = {
  target: SSOAddonTarget
  /**
   * An organization's add-on is off while its domains, identity providers or
   * Require SSO remain: say that single sign-on is paused, and what still works.
   */
  paused?: boolean
}

/**
 * A Single sign-on add-on, built like the Premium Geo DB card and behind the
 * single sign-on experiment with the rest of it.
 *
 * - A project's (`sso`) lets its teams verify domains, connect identity
 *   providers and turn on Require SSO through the Teams API. Signing in with
 *   single sign-on is for the Console only, so the card says nothing about it.
 *   Inside an environment it shows the project's add-on and changes nothing.
 * - An organization's (`ssoOrg`) is single sign-on into the Console for people
 *   on its verified domains, set up on its Single sign-on page.
 */
export function SSOAddonCard({ target, paused = false }: SSOAddonCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const shown = features.billing && features.sso
  const inEnvironment = target.scope === 'project' && !!target.inEnvironment
  const {
    key,
    orgId,
    organization,
    targetReady,
    planQuery,
    plan,
    addonsQuery,
    addon,
    status,
    addonPrice,
  } = useSSOAddon(target, { enabled: shown, withPrice: !inEnvironment })
  const { plans } = useBillingPlans()
  const sellers = usePlansSellingAddon(key)
  // Project add-ons follow the project's roles, an organization's its owners
  const { access } = useOrganizationScopes(
    orgId,
    undefined,
    target.scope === 'organization' ? { projectId: null } : undefined,
  )
  const [showEnable, setShowEnable] = useState(false)
  const [showDisable, setShowDisable] = useState(false)
  const [reEnabling, setReEnabling] = useState(false)
  const [refreshing, setRefreshing] = useState(false)

  const addonTarget = toAddonTarget(target)
  const isScheduledForRemoval = isAddonScheduledForRemoval(addon)
  const canUpgrade =
    status === 'unavailable' && hasUpgradeablePlanWithAddon(plan, plans, key)
  const canManage =
    target.scope === 'project'
      ? canShowProjectSettings(access, features)
      : !features.orgRoles || access.isOwner
  const monthlyPriceLabel = addonPrice
    ? formatCurrency(addonPrice.monthlyPrice, addonPrice.currency)
    : null

  const loading =
    !targetReady || addonsQuery.isLoading || (!!orgId && planQuery.isLoading)
  const denied = isPermissionError(addonsQuery.error)
  const failed = !denied && (addonsQuery.isError || !!planQuery.error)

  if (!shown) return null

  const handleRetry = () => {
    void addonsQuery.refetch()
    if (planQuery.error) void planQuery.refetch()
  }

  // A payment waiting for 3-D Secure: check whether it went through
  const handleRefresh = async () => {
    if (!addon) return
    setRefreshing(true)
    try {
      await confirmAddonPayment(addonTarget, addon.$id)
      await refreshAddonQueries(queryClient, addonTarget, key, orgId)
      toast.success(t('Single sign-on add-on has been enabled'))
    } catch (error) {
      const candidate = error as {
        type?: string
        code?: number
        message?: string
      }
      if (candidate?.type === 'addon_not_found' || candidate?.code === 404) {
        await refreshAddonQueries(queryClient, addonTarget, key, orgId)
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
        target: addonTarget,
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

  const noPermission =
    target.scope === 'project'
      ? t("You don't have permission to manage this project's add-ons.")
      : t("You don't have permission to manage this organization's add-ons.")

  const statusCopy = (() => {
    if (loading) return null
    if (denied) return noPermission
    if (failed) return t("Couldn't load the add-on's status.")
    if (inEnvironment) {
      return t(
        "This environment has the add-on of its project. Change it in the project's settings.",
      )
    }
    if (!canManage) return noPermission
    if (status === 'unavailable' && canUpgrade) {
      return sellers
        ? t(
            'This add-on is available on {plans}. Upgrade your plan to enable it.',
          ).replace('{plans}', sellers)
        : t(
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

  const showPaused = paused && !loading && status !== 'active'

  return (
    <>
      <div
        data-card-id={SSO_ADDON_CARD_ID}
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
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
            {target.scope === 'project'
              ? t(SSO_ADDON_DESCRIPTION)
              : t(ORGANIZATION_SSO_ADDON_DESCRIPTION)}
          </p>
        </div>

        {statusCopy || showPaused ? (
          <>
            <div className="border-t border-border" />
            <div className="px-6 py-4 space-y-3">
              {showPaused ? (
                <Alert className="border-amber-500/30 bg-amber-500/5">
                  <AlertCircle className="h-4 w-4 text-amber-500" />
                  <AlertTitle className="text-[13px] font-medium text-amber-600 dark:text-amber-400">
                    {t('Single sign-on is paused')}
                  </AlertTitle>
                  <AlertDescription className="text-[12px] text-amber-600/80 dark:text-amber-400/80">
                    {t(
                      'Until the add-on is active, nobody signs in through your identity providers and Require SSO has no effect. You can still delete domains and identity providers, and turn Require SSO off.',
                    )}
                  </AlertDescription>
                </Alert>
              ) : null}
              {statusCopy ? (
                <p className="text-[13px] text-muted-foreground">
                  {statusCopy}
                </p>
              ) : null}
            </div>
          </>
        ) : null}

        {footerAction ? (
          <div
            data-sso-addon-actions
            className="px-6 py-4 border-t border-border bg-muted/30 flex flex-wrap items-center gap-3"
          >
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
          target={target}
        />
      ) : null}
      {addon && !inEnvironment ? (
        <DisableSSOAddonDialog
          open={showDisable}
          onOpenChange={setShowDisable}
          target={target}
          addonId={addon.$id}
        />
      ) : null}
    </>
  )
}
