'use client'

import { useState, type ReactNode } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { UpgradeCurtain } from '@/components/ui/upgrade-curtain'
import { EnablePremiumGeoDBDialog } from '@/components/pages/projects/$projectId/settings/_components/EnablePremiumGeoDBDialog'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  hasUpgradeablePlanWithAddon,
} from '@/lib/billing/addons'
import {
  useBillingPlans,
  useOrganizationPlan,
} from '@/lib/react-query/hooks'
import { navigateToUpgradeWizard } from '@/lib/open-upgrade-wizard'
import { useT } from '@/lib/i18n/translate'
import { useOptionalUsageFilters } from '../usage-filters-context'
import { UsageBreakdownListSkeleton } from './UsageBreakdownRows'

type UsagePremiumGeoDBCurtainProps = {
  children?: ReactNode
  className?: string
  onEnabled?: () => void
}

/**
 * Curtain overlay for usage views that require the Premium Geo DB addon.
 */
export function UsagePremiumGeoDBCurtain({
  children,
  className,
  onEnabled,
}: UsagePremiumGeoDBCurtainProps) {
  const t = useT()
  const navigate = useNavigate()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string | undefined
  const usageFilters = useOptionalUsageFilters()
  const organizationId = usageFilters?.organizationId
  const { features } = useConsoleProfile()
  const { plan } = useOrganizationPlan(organizationId)
  const { plans } = useBillingPlans()
  const [enableOpen, setEnableOpen] = useState(false)

  const planSupportsPremiumGeoDB = plan?.supportedAddons?.premiumGeoDB === true
  const canUpgradeToPremiumGeoDB =
    !planSupportsPremiumGeoDB &&
    hasUpgradeablePlanWithAddon(plan, plans, 'premiumGeoDB')
  const showCta =
    features.billing &&
    (planSupportsPremiumGeoDB || canUpgradeToPremiumGeoDB)

  const handleCta = () => {
    if (planSupportsPremiumGeoDB && projectId) {
      setEnableOpen(true)
      return
    }
    if (canUpgradeToPremiumGeoDB) {
      navigateToUpgradeWizard(navigate, organizationId)
    }
  }

  return (
    <>
      <UpgradeCurtain
        isLocked
        orgId={organizationId}
        className={className}
        title={t('Premium Geo DB required')}
        message={t(
          'Enable the Premium Geo DB addon for this project to view city and country usage breakdowns.',
        )}
        ctaLabel={
          planSupportsPremiumGeoDB
            ? t('Enable Premium Geo DB')
            : t('Upgrade plan')
        }
        onCtaClick={handleCta}
        showCta={showCta}
      >
        {children ?? <UsageBreakdownListSkeleton showLeadingIcon={false} />}
      </UpgradeCurtain>
      {projectId ? (
        <EnablePremiumGeoDBDialog
          open={enableOpen}
          onOpenChange={setEnableOpen}
          projectId={projectId}
          onEnabled={onEnabled}
        />
      ) : null}
    </>
  )
}
