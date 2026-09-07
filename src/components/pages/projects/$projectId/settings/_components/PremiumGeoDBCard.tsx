'use client'

import { useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  ADDON_KEY_PREMIUM_GEO_DB,
  findActiveOrPendingAddon,
  getAddonConfirmSearchParams,
  hasUpgradeablePlanWithAddon,
  isAddonScheduledForRemoval,
  isPaymentAuthentication,
  resolveStripeProviderMethodId,
} from '@/lib/billing/addons'
import { refetchOrganizationBillingQueries } from '@/lib/billing/refetch-organization-billing-queries'
import {
  projectAddonsQueryOptions,
  useBillingPlans,
  useOrganizationById,
  useOrganizationPlan,
  useProject,
  useProjectAddonPrice,
  useProjectAddons,
} from '@/lib/react-query/hooks'
import { navigateToUpgradeWizard } from '@/lib/open-upgrade-wizard'
import { confirmPayment } from '@/lib/utils/stripe'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { formatCurrency } from '@/components/pages/organizations/$orgId/billing/utils'
import { Activity, BarChart3, Check, Plus, Shield } from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'
import { EnablePremiumGeoDBDialog } from './EnablePremiumGeoDBDialog'
import { DisablePremiumGeoDBDialog } from './DisablePremiumGeoDBDialog'

/** Standard vs premium fields from cloud geoRecord / Cloud Session model. */
const STANDARD_GEO_FIELDS = [
  'Country',
  'Continent',
  'EU membership',
  'Currency',
] as const

const PREMIUM_GEO_BENEFITS = [
  {
    icon: Shield,
    title: 'Stronger firewall rules',
    description:
      'Block or allow traffic by city, state, ISP, ASN, and connection type for precise access control.',
  },
  {
    icon: Activity,
    title: 'Richer session and request context',
    description:
      'Attach detailed geolocation to Auth sessions, activity logs, and audit trails on every request.',
  },
  {
    icon: BarChart3,
    title: 'Deeper usage insights',
    description:
      'Break down API traffic by city, ISP, and network attributes to spot abuse and regional patterns.',
  },
] as const

function PremiumGeoBenefitList() {
  const t = useT()

  return (
    <div className="space-y-3">
      <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
        {t('How this affects your app')}
      </p>
      <div className="rounded-lg border border-border bg-background">
        {PREMIUM_GEO_BENEFITS.map(({ icon: Icon, title, description }, index) => (
          <div key={title}>
            <div className="flex items-start gap-3 px-4 py-3">
              <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icon className="h-4 w-4" />
              </div>
              <div className="min-w-0">
                <p className="text-[13px] font-medium text-foreground">
                  {t(title)}
                </p>
                <p className="mt-1 text-[12px] text-muted-foreground">
                  {t(description)}
                </p>
              </div>
            </div>
            {index < PREMIUM_GEO_BENEFITS.length - 1 ? (
              <div className="border-t border-border" />
            ) : null}
          </div>
        ))}
      </div>
    </div>
  )
}

const PREMIUM_GEO_FIELD_GROUPS = [
  {
    title: 'Location details',
    fields: [
      'City',
      'State / region',
      'Postal code',
      'Timezone',
      'Coordinates',
    ],
  },
  {
    title: 'Network details',
    fields: [
      'ISP',
      'ASN',
      'Connection type',
      'Connection usage',
      'Organization',
    ],
  },
] as const

function GeoFieldList({
  fields,
  icon: Icon,
}: {
  fields: readonly string[]
  icon: typeof Check
}) {
  const t = useT()

  return (
    <ul className="grid gap-1.5 sm:grid-cols-2">
      {fields.map((field) => (
        <li
          key={field}
          className="flex min-w-0 items-center gap-2 text-[12px] text-muted-foreground"
        >
          <Icon className="h-3.5 w-3.5 shrink-0 text-foreground" />
          <span className="truncate">{t(field)}</span>
        </li>
      ))}
    </ul>
  )
}

function PremiumGeoDataComparison() {
  const t = useT()

  return (
    <div className="space-y-3">
      <p className="text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
        {t('Geolocation attributes')}
      </p>
      <div className="rounded-lg border border-border bg-background overflow-hidden">
        <div className="px-4 py-3">
          <p className="text-[13px] font-medium text-foreground">
            {t('Included on every plan')}
          </p>
          <div className="mt-2">
            <GeoFieldList fields={STANDARD_GEO_FIELDS} icon={Check} />
          </div>
        </div>

        <div className="border-t border-border bg-muted/30 px-4 py-3">
          <p className="text-[13px] font-medium text-foreground">
            {t('Added with Premium Geo DB')}
          </p>
          <div className="mt-3 space-y-3">
            {PREMIUM_GEO_FIELD_GROUPS.map(({ title, fields }, index) => (
              <div key={title}>
                <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                  {t(title)}
                </p>
                <div className="mt-1.5">
                  <GeoFieldList fields={fields} icon={Plus} />
                </div>
                {index < PREMIUM_GEO_FIELD_GROUPS.length - 1 ? (
                  <div className="mt-3 border-t border-border/60" />
                ) : null}
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  )
}

type PremiumGeoDBCardProps = {
  projectId: string
}

export function PremiumGeoDBCard({ projectId }: PremiumGeoDBCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const { project } = useProject(projectId)
  const orgId = project?.teamId
  const { organization } = useOrganizationById(orgId)
  const { plan } = useOrganizationPlan(orgId)
  const { plans } = useBillingPlans()
  const { addons, refetch: refetchAddons } = useProjectAddons(
    features.billing ? projectId : null,
  )
  const { addonPrice } = useProjectAddonPrice(
    features.billing ? projectId : null,
    ADDON_KEY_PREMIUM_GEO_DB,
  )
  const [showEnable, setShowEnable] = useState(false)
  const [showDisable, setShowDisable] = useState(false)
  const [reEnabling, setReEnabling] = useState(false)
  const [refreshing, setRefreshing] = useState(false)
  const confirmHandledRef = useRef(false)

  const planSupportsPremiumGeoDB = plan?.supportedAddons?.premiumGeoDB === true
  const canUpgradeToPremiumGeoDB =
    !planSupportsPremiumGeoDB &&
    hasUpgradeablePlanWithAddon(plan, plans, 'premiumGeoDB')
  const premiumGeoDBAddon = useMemo(
    () => findActiveOrPendingAddon(addons, ADDON_KEY_PREMIUM_GEO_DB),
    [addons],
  )
  const isPending = premiumGeoDBAddon?.status === 'pending'
  const isActive = premiumGeoDBAddon?.status === 'active'
  const isScheduledForRemoval = isAddonScheduledForRemoval(premiumGeoDBAddon)
  const monthlyPriceLabel = addonPrice
    ? formatCurrency(addonPrice.monthlyPrice, addonPrice.currency)
    : null

  const confirmAddon = async (addonId: string) => {
    try {
      await sdk.forConsole.projects.confirmAddonPayment({
        projectId,
        addonId,
      })
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: projectAddonsQueryOptions(projectId).queryKey,
        }),
        queryClient.refetchQueries({ queryKey: ['project', projectId] }),
        ...(orgId
          ? [refetchOrganizationBillingQueries(queryClient, orgId)]
          : []),
      ])
      toast.success(t('Premium Geo DB addon has been enabled'))
    } catch (error) {
      const candidate = error as { type?: string; code?: number; message?: string }
      if (candidate?.type === 'addon_not_found' || candidate?.code === 404) {
        await Promise.all([
          queryClient.refetchQueries({
            queryKey: projectAddonsQueryOptions(projectId).queryKey,
          }),
          queryClient.refetchQueries({ queryKey: ['project', projectId] }),
          ...(orgId
            ? [refetchOrganizationBillingQueries(queryClient, orgId)]
            : []),
        ])
        toast.success(t('Premium Geo DB addon has been enabled'))
        return
      }
      if (candidate?.code === 402) {
        await refetchAddons()
        toast.error(
          candidate.message ??
            t(
              'Payment could not be authorized. Please try enabling the addon again.',
            ),
        )
        return
      }
      toast.error(getErrorMessage(error))
    }
  }

  useEffect(() => {
    if (!features.billing || typeof window === 'undefined') return
    if (confirmHandledRef.current) return

    const { type, addonId: searchAddonId } = getAddonConfirmSearchParams(
      window.location.search,
    )
    if (type !== 'confirm-addon') return
    confirmHandledRef.current = true

    void (async () => {
      let addonId = searchAddonId
      if (!addonId || addonId === 'undefined') {
        try {
          const addonList = await sdk.forConsole.projects.listAddons({
            projectId,
          })
          addonId =
            findActiveOrPendingAddon(addonList.addons, ADDON_KEY_PREMIUM_GEO_DB)
              ?.$id ?? null
        } catch (error) {
          toast.error(
            getErrorMessage(error) ||
              t('Unable to verify Premium Geo DB addon status. Please retry.'),
          )
          addonId = null
        }
      }

      if (addonId) {
        await confirmAddon(addonId)
      }

      void navigate({
        to: '/projects/$projectId/settings',
        params: { projectId },
        replace: true,
      })
    })()
    // eslint-disable-next-line react-hooks/exhaustive-deps -- run once for return URL
  }, [features.billing, projectId])

  if (!features.billing) return null

  const handleRefresh = async () => {
    if (!premiumGeoDBAddon) return
    setRefreshing(true)
    try {
      await confirmAddon(premiumGeoDBAddon.$id)
    } finally {
      setRefreshing(false)
    }
  }

  const handleReEnable = async () => {
    setReEnabling(true)
    try {
      const result = (await sdk.forConsole.projects.createPremiumGeoDBAddon({
        projectId,
      })) as Models.Addon | Models.PaymentAuthentication

      if (isPaymentAuthentication(result)) {
        const paymentMethodId = organization?.paymentMethodId
        if (!paymentMethodId || !organization?.$id) {
          throw new Error(
            t('Add a payment method to your organization before enabling this addon.'),
          )
        }
        const providerMethodId = await resolveStripeProviderMethodId({
          organizationId: organization.$id,
          paymentMethodId,
        })
        await confirmPayment({
          clientSecret: result.clientSecret,
          paymentMethod: providerMethodId,
        })
        await confirmAddon(result.addonId)
        return
      }

      await Promise.all([
        queryClient.refetchQueries({
          queryKey: projectAddonsQueryOptions(projectId).queryKey,
        }),
        queryClient.refetchQueries({ queryKey: ['project', projectId] }),
        ...(orgId
          ? [refetchOrganizationBillingQueries(queryClient, orgId)]
          : []),
      ])
      toast.success(t('Premium Geo DB addon has been re-enabled'))
    } catch (error) {
      toast.error(getErrorMessage(error))
    } finally {
      setReEnabling(false)
    }
  }

  const statusBadge = (() => {
    if (!planSupportsPremiumGeoDB) {
      return (
        <Badge variant="info" className="text-[10px] shrink-0">
          {t('Unavailable')}
        </Badge>
      )
    }
    if (isPending) {
      return (
        <Badge variant="warning" className="text-[10px] shrink-0">
          {t('Payment pending')}
        </Badge>
      )
    }
    if (isActive && isScheduledForRemoval) {
      return (
        <Badge variant="warning" className="text-[10px] shrink-0">
          {t('Scheduled for removal')}
        </Badge>
      )
    }
    if (isActive) {
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
    if (!planSupportsPremiumGeoDB && canUpgradeToPremiumGeoDB) {
      return t(
        'Premium Geo DB is not available on your current plan. Upgrade your plan to enable it.',
      )
    }
    if (!planSupportsPremiumGeoDB) {
      return t('Premium Geo DB is not available on your current plan.')
    }
    if (isPending) {
      return t(
        "A payment is awaiting confirmation. If you've completed authentication, click refresh to check the payment status.",
      )
    }
    if (isActive && isScheduledForRemoval) {
      return t(
        'Premium Geo DB will be removed at the end of your current billing cycle.',
      )
    }
    if (isActive) {
      return null
    }
    return monthlyPriceLabel
      ? t(
          'Enrich request and session data with premium geolocation. {price}/month, prorated for the current billing cycle.',
        ).replace('{price}', monthlyPriceLabel)
      : t(
          'Enrich request and session data with premium geolocation. Billed prorated for the current cycle.',
        )
  })()

  const footerAction = (() => {
    if (!planSupportsPremiumGeoDB && canUpgradeToPremiumGeoDB) {
      return (
        <Button
          size="sm"
          className="h-9 text-[13px]"
          onClick={() => navigateToUpgradeWizard(navigate, orgId)}
        >
          {t('Upgrade plan')}
        </Button>
      )
    }
    if (!planSupportsPremiumGeoDB) return null
    if (isPending) {
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
    if (isActive && isScheduledForRemoval) {
      return (
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={reEnabling}
          onClick={() => void handleReEnable()}
        >
          {t('Keep Premium Geo DB')}
        </Button>
      )
    }
    if (isActive) {
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
        {t('Enable Premium Geo DB')}
      </Button>
    )
  })()

  return (
    <>
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <div className="flex flex-wrap items-center gap-2">
            <h3 className="text-[15px] font-semibold text-foreground">
              {t('Premium Geo DB')}
            </h3>
            {statusBadge}
          </div>
        </div>

        <div className="border-t border-border" />

        <div className="px-6 py-4 @container">
          <div className="flex gap-6 @[600px]:flex-row flex-col">
            <div className="@[600px]:w-64 shrink-0">
              <p className="text-[13px] text-muted-foreground">
                {t(
                  'Strengthen security with precise Firewall rules and improve observability across sessions, activity, and usage analytics.',
                )}
              </p>
            </div>

            <div className="flex-1 min-w-0 space-y-4">
              <PremiumGeoBenefitList />

              <PremiumGeoDataComparison />
            </div>
          </div>
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
            {isActive && !isScheduledForRemoval && monthlyPriceLabel ? (
              <span className="text-[13px] text-muted-foreground">
                {t('{price}/month').replace('{price}', monthlyPriceLabel)}
              </span>
            ) : null}
          </div>
        ) : null}
      </div>

      <EnablePremiumGeoDBDialog
        open={showEnable}
        onOpenChange={setShowEnable}
        projectId={projectId}
      />
      {premiumGeoDBAddon ? (
        <DisablePremiumGeoDBDialog
          open={showDisable}
          onOpenChange={setShowDisable}
          projectId={projectId}
          addonId={premiumGeoDBAddon.$id}
        />
      ) : null}
    </>
  )
}
