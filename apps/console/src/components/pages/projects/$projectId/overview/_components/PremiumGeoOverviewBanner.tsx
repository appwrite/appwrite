'use client'

import { useMemo, useState, type MouseEvent } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { X } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { BlogPageAnchor } from '@/components/global/shared/BlogPageAnchor'
import { EnablePremiumGeoDBDialog } from '@/components/pages/projects/$projectId/settings/_components/EnablePremiumGeoDBDialog'
import { Switch } from '@/components/ui/switch'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  ADDON_KEY_PREMIUM_GEO_DB,
  findActiveOrPendingAddon,
  hasUpgradeablePlanWithAddon,
} from '@/lib/billing/addons'
import {
  isProjectEligibleForPremiumGeoOverviewBanner,
  PREMIUM_GEO_PROMO_DESCRIPTION,
  PREMIUM_GEO_PROMO_HINTS,
  PREMIUM_GEO_PROMO_LEARN_MORE_PATH,
} from '@/lib/billing/premium-geo-promo'
import { analyticsAttrs } from '@/lib/analytics-actions'
import {
  isPremiumGeoOverviewPromoDismissed,
  type UserPrefs,
} from '@/lib/user-prefs-keys'
import { useFirewallPremiumGeoEnabled } from '@/lib/firewall/use-premium-geo-enabled'
import { navigateToUpgradeWizard } from '@/lib/open-upgrade-wizard'
import { useT } from '@/lib/i18n/translate'
import {
  useBillingPlans,
  useOrganizationAddonPrice,
  useOrganizationById,
  useOrganizationPlan,
  useDismissPremiumGeoOverviewPromo,
  useProject,
  useProjectAddons,
} from '@/lib/react-query/hooks'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'

type PremiumGeoOverviewBannerProps = {
  projectId: string
}

export function PremiumGeoOverviewBanner({
  projectId,
}: PremiumGeoOverviewBannerProps) {
  const t = useT()
  const navigate = useNavigate()
  const { account } = useAuth()
  const dismissPromo = useDismissPremiumGeoOverviewPromo()
  const { project, projectData, isLoading: projectLoading } = useProject(projectId)
  const organizationId = project?.teamId
  const createdAt = project?.createdAt ?? projectData?.$createdAt
  const { organization } = useOrganizationById(organizationId)

  const { plan, isFetched: planFetched } = useOrganizationPlan(organizationId)
  const { plans, isLoading: plansLoading } = useBillingPlans()
  const { premiumGeoEnabled, billingEnabled, isLoading: geoLoading } =
    useFirewallPremiumGeoEnabled(projectId)
  const { addons, isLoading: addonsLoading } = useProjectAddons(
    billingEnabled ? projectId : null,
  )
  const { addonPrice } = useOrganizationAddonPrice(
    billingEnabled ? organizationId : null,
    billingEnabled ? ADDON_KEY_PREMIUM_GEO_DB : null,
  )
  const [optimisticDismissed, setOptimisticDismissed] = useState(false)
  const dismissedFromPrefs = useMemo(
    () =>
      isPremiumGeoOverviewPromoDismissed(
        account?.prefs as UserPrefs | undefined,
        projectId,
      ),
    [account?.prefs, projectId],
  )
  const dismissed = optimisticDismissed || dismissedFromPrefs
  const [enableOpen, setEnableOpen] = useState(false)
  const [toggleChecked, setToggleChecked] = useState(false)

  const planSupportsPremiumGeoDB = plan?.supportedAddons?.premiumGeoDB === true
  const canUpgradeToPremiumGeoDB =
    !planSupportsPremiumGeoDB &&
    hasUpgradeablePlanWithAddon(plan, plans, 'premiumGeoDB')
  const hasPendingAddon =
    findActiveOrPendingAddon(addons, ADDON_KEY_PREMIUM_GEO_DB)?.status ===
    'pending'
  const paymentMethodMissing =
    planSupportsPremiumGeoDB && !organization?.paymentMethodId

  const monthlyLabel = addonPrice
    ? formatCurrency(addonPrice.monthlyPrice, addonPrice.currency)
    : null
  const prerequisitesReady =
    !projectLoading &&
    !!organizationId &&
    planFetched &&
    !plansLoading &&
    !geoLoading &&
    !addonsLoading

  const showBanner =
    prerequisitesReady &&
    billingEnabled &&
    !dismissed &&
    !premiumGeoEnabled &&
    !hasPendingAddon &&
    isProjectEligibleForPremiumGeoOverviewBanner(createdAt) &&
    (planSupportsPremiumGeoDB || canUpgradeToPremiumGeoDB)

  if (!showBanner) {
    return null
  }

  const persistDismiss = () => {
    setOptimisticDismissed(true)
    dismissPromo.mutate(projectId)
  }

  const handleDismiss = (event: MouseEvent<HTMLButtonElement>) => {
    event.preventDefault()
    event.stopPropagation()
    persistDismiss()
  }

  const handleToggleChange = (checked: boolean) => {
    if (!checked) {
      setToggleChecked(false)
      return
    }

    if (canUpgradeToPremiumGeoDB) {
      setToggleChecked(false)
      navigateToUpgradeWizard(navigate, organizationId)
      return
    }

    if (paymentMethodMissing) {
      setToggleChecked(false)
      return
    }

    if (planSupportsPremiumGeoDB) {
      setToggleChecked(true)
      setEnableOpen(true)
    }
  }

  const handleEnableDialogOpenChange = (open: boolean) => {
    setEnableOpen(open)
    if (!open) {
      setToggleChecked(false)
    }
  }

  const switchControl = (
    <Switch
      id="premium-geo-overview-toggle"
      checked={toggleChecked}
      onCheckedChange={handleToggleChange}
      disabled={paymentMethodMissing && !canUpgradeToPremiumGeoDB}
      aria-label={t('Enable Premium Geo DB')}
    />
  )

  return (
    <>
      <section
        className="border-b border-border"
        aria-labelledby="premium-geo-overview-promo-title"
      >
        <div className="mx-auto w-full max-w-7xl px-4 py-4 sm:px-6">
          <div className="flex items-center gap-4 sm:gap-5">
            <div className="shrink-0 self-start pt-1">
              {paymentMethodMissing ? (
                <Tooltip>
                  <TooltipTrigger asChild>
                    <span className="inline-flex">{switchControl}</span>
                  </TooltipTrigger>
                  <TooltipContent side="bottom" className="max-w-xs text-[12px]">
                    {t(
                      'Add a payment method to your organization before enabling this addon.',
                    )}
                  </TooltipContent>
                </Tooltip>
              ) : (
                switchControl
              )}
            </div>

            <div className="min-w-0 flex-1 space-y-1">
              <p className="text-[13px] leading-snug">
                <span
                  id="premium-geo-overview-promo-title"
                  className="font-semibold text-foreground"
                >
                  {t('Advanced security')}
                </span>
                <span className="px-1.5 text-muted-foreground/40">·</span>
                <span className="font-medium text-muted-foreground">
                  {t('Premium Geo DB')}
                </span>
              </p>
              <p className="text-[13px] leading-relaxed text-muted-foreground">
                {t(PREMIUM_GEO_PROMO_DESCRIPTION)}{' '}
                {monthlyLabel
                  ? t('{price}/mo, prorated.').replace('{price}', monthlyLabel)
                  : t('Billed monthly, prorated.')}
                <span className="px-1.5 text-muted-foreground/40">·</span>
                <span className="text-[12px] text-muted-foreground/75">
                  {PREMIUM_GEO_PROMO_HINTS.map((hint, index) => (
                    <span key={hint}>
                      {index > 0 ? (
                        <span className="text-muted-foreground/40"> · </span>
                      ) : null}
                      {t(hint)}
                    </span>
                  ))}
                  <span className="text-muted-foreground/40"> · </span>
                  {t('+ more')}
                </span>
                <span className="text-muted-foreground/40"> · </span>
                <BlogPageAnchor
                  href={PREMIUM_GEO_PROMO_LEARN_MORE_PATH}
                  target="_blank"
                  rel="noopener noreferrer"
                  {...analyticsAttrs('premium-geo-overview-promo-learn-more')}
                  className="font-medium text-foreground underline underline-offset-2 hover:text-primary"
                >
                  {t('Learn more')}
                </BlogPageAnchor>
              </p>
            </div>

            <button
              type="button"
              className="flex h-8 w-8 shrink-0 cursor-pointer items-center justify-center self-start rounded-md text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground disabled:opacity-50"
              aria-label={t('Dismiss banner')}
              disabled={dismissPromo.isPending}
              onClick={handleDismiss}
            >
              <X className="h-4 w-4" aria-hidden />
            </button>
          </div>
        </div>
      </section>
      <EnablePremiumGeoDBDialog
        open={enableOpen}
        onOpenChange={handleEnableDialogOpenChange}
        projectId={projectId}
        onEnabled={() => {
          persistDismiss()
          setToggleChecked(false)
        }}
      />
    </>
  )
}
