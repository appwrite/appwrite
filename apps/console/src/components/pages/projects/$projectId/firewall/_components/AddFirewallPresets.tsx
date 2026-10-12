import { useMemo, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { List, X } from 'lucide-react'
import { WafRuleAction } from '@appwrite.io/console'
import { toast } from 'sonner'
import { EnablePremiumGeoDBDialog } from '@/components/pages/projects/$projectId/settings/_components/EnablePremiumGeoDBDialog'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import { Label } from '@/components/ui/label'
import { NumberInput } from '@/components/ui/number-input'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import {
  hasUpgradeablePlanWithAddon,
} from '@/lib/billing/addons'
import {
  useBillingPlans,
  useCountries,
  useCreateFirewallRule,
  useOrganizationPlan,
  useProject,
} from '@/lib/react-query/hooks'
import {
  buildApiCountryFirewallRules,
  buildApiPathRateLimitRule,
  buildCountryOtpFirewallRules,
  buildOtpRateLimitRule,
  buildPremiumNetworkDenyRule,
  buildPresetCountryBlockPreviewConditionSets,
  buildPresetCountryPreviewConditions,
  buildPresetPremiumDenyPreviewConditions,
  buildPresetRateLimitOtpPreviewConditionSets,
  buildPresetRateLimitPreviewConditions,
  getFirewallRulePresets,
  getGroupedFirewallRulePresets,
  presetRequiresPremiumGeo,
  resolveOtpFlows,
  type FirewallRulePresetDefinition,
  type OtpFirewallFlow,
  type OtpFlowSelection,
} from '@/lib/firewall/rule-presets'
import type { FirewallResourceSelection } from '@/lib/firewall/conditions'
import { exceedsFirewallUsageConditionLimit } from '@/lib/firewall/usage'
import { useFirewallPremiumGeoEnabled } from '@/lib/firewall/use-premium-geo-enabled'
import { navigateToUpgradeWizard } from '@/lib/open-upgrade-wizard'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { FIREWALL_RATE_LIMIT_INTERVAL_MAX } from '@/lib/firewall/actions'
import { createTimeUnitPair, type TimeUnit } from '@/lib/utils/time-unit-converter'
import {
  FIREWALL_RATE_LIMIT_INTERVAL_UNITS,
  FirewallRateLimitIntervalField,
  firewallRateLimitIntervalSeconds,
} from './FirewallRateLimitIntervalField'
import { FirewallPresetImpactPreview } from './FirewallPresetImpactPreview'

type AddFirewallPresetsProps = {
  projectId: string
  resourceSelection: FirewallResourceSelection
  canWrite: boolean
  createDisabled: boolean
  createDisabledTooltip: string
}

type ActiveModal =
  | {
      kind: 'rateLimit'
      preset: Extract<FirewallRulePresetDefinition, { kind: 'rateLimit' }>
    }
  | {
      kind: 'country'
      preset: Extract<FirewallRulePresetDefinition, { kind: 'country' }>
    }
  | {
      kind: 'premiumDeny'
      preset: Extract<FirewallRulePresetDefinition, { kind: 'premiumDeny' }>
    }
  | null

function flowLabel(t: (key: string) => string, flow: OtpFirewallFlow): string {
  switch (flow) {
    case 'phoneSend':
      return t('Phone OTP send')
    case 'emailSend':
      return t('Email OTP send')
    case 'phoneVerify':
      return t('Phone OTP verification')
    case 'emailVerify':
      return t('Email OTP verification')
    default:
      return flow
  }
}

function OtpScopeFields({
  includePhone,
  includeEmail,
  includeSend,
  includeVerify,
  onPhoneChange,
  onEmailChange,
  onSendChange,
  onVerifyChange,
  disabled,
}: OtpFlowSelection & {
  onPhoneChange: (checked: boolean) => void
  onEmailChange: (checked: boolean) => void
  onSendChange: (checked: boolean) => void
  onVerifyChange: (checked: boolean) => void
  disabled?: boolean
}) {
  const t = useT()

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-2">
        <Label className="text-[13px] font-medium leading-snug">
          {t('Channels')}
        </Label>
        <div className="grid grid-cols-2 gap-x-4 gap-y-2">
          <label className="flex cursor-pointer items-center gap-2 text-[13px] leading-snug">
            <Checkbox
              checked={includePhone}
              disabled={disabled}
              onCheckedChange={(checked) => onPhoneChange(checked === true)}
            />
            <span>{t('Phone')}</span>
          </label>
          <label className="flex cursor-pointer items-center gap-2 text-[13px] leading-snug">
            <Checkbox
              checked={includeEmail}
              disabled={disabled}
              onCheckedChange={(checked) => onEmailChange(checked === true)}
            />
            <span>{t('Email')}</span>
          </label>
        </div>
      </div>
      <div className="flex flex-col gap-2">
        <Label className="text-[13px] font-medium leading-snug">
          {t('Steps')}
        </Label>
        <div className="grid grid-cols-2 gap-x-4 gap-y-2">
          <label className="flex cursor-pointer items-center gap-2 text-[13px] leading-snug">
            <Checkbox
              checked={includeSend}
              disabled={disabled}
              onCheckedChange={(checked) => onSendChange(checked === true)}
            />
            <span>{t('OTP send')}</span>
          </label>
          <label className="flex cursor-pointer items-center gap-2 text-[13px] leading-snug">
            <Checkbox
              checked={includeVerify}
              disabled={disabled}
              onCheckedChange={(checked) => onVerifyChange(checked === true)}
            />
            <span>{t('OTP verification')}</span>
          </label>
        </div>
      </div>
    </div>
  )
}

function defaultRateLimitRuleName(
  t: (key: string) => string,
  flow: OtpFirewallFlow,
): string {
  switch (flow) {
    case 'phoneSend':
      return t('Rate limit phone OTP send')
    case 'emailSend':
      return t('Rate limit email OTP send')
    case 'phoneVerify':
      return t('Rate limit phone OTP verification')
    case 'emailVerify':
      return t('Rate limit email OTP verification')
    default:
      return t('Rate limit OTP')
  }
}

export function AddFirewallPresets({
  projectId,
  resourceSelection,
  canWrite,
  createDisabled,
  createDisabledTooltip,
}: AddFirewallPresetsProps) {
  const t = useT()
  const navigate = useNavigate()
  const { project } = useProject(projectId)
  const { plan } = useOrganizationPlan(project?.teamId)
  const { plans } = useBillingPlans()
  const { premiumGeoEnabled, billingEnabled } =
    useFirewallPremiumGeoEnabled(projectId)
  const presets = getFirewallRulePresets(resourceSelection.resourceType)
  const presetGroups = getGroupedFirewallRulePresets(resourceSelection.resourceType)
  const createMutation = useCreateFirewallRule(projectId)
  const [pickerOpen, setPickerOpen] = useState(false)
  const [premiumGeoDialogOpen, setPremiumGeoDialogOpen] = useState(false)
  const [activeModal, setActiveModal] = useState<ActiveModal>(null)
  const [limit, setLimit] = useState(5)
  const [intervalValue, setIntervalValue] = useState(1)
  const [intervalUnit, setIntervalUnit] = useState<TimeUnit>('hours')
  const [includePhone, setIncludePhone] = useState(true)
  const [includeEmail, setIncludeEmail] = useState(true)
  const [includeSend, setIncludeSend] = useState(true)
  const [includeVerify, setIncludeVerify] = useState(true)
  const [selectedCountries, setSelectedCountries] = useState<string[]>([])
  const [countryPickerValue, setCountryPickerValue] = useState('')

  const { data: countriesData, isFetching: countriesLoading } = useCountries()
  const countryItems = useMemo(
    () =>
      (countriesData?.countries ?? []).map((country) => {
        const code = country.code.toUpperCase()
        return {
          value: code,
          label: country.name,
          description: code,
          inlineDescription: true,
          searchText: `${country.name} ${code} ${country.code}`,
        }
      }),
    [countriesData?.countries],
  )

  const otpScopeSelection = (): OtpFlowSelection => ({
    phone: includePhone,
    email: includeEmail,
    send: includeSend,
    verify: includeVerify,
  })

  const selectedOtpFlows = (): OtpFirewallFlow[] =>
    resolveOtpFlows(otpScopeSelection())

  const presetImpactPreview = useMemo(() => {
    if (!activeModal) return null

    if (activeModal.kind === 'rateLimit') {
      const preset = activeModal.preset
      const flows =
        preset.target === 'otp' ? selectedOtpFlows() : []
      if (preset.target === 'otp' && flows.length > 1) {
        const conditionSets = buildPresetRateLimitOtpPreviewConditionSets(flows)
        return {
          conditions: conditionSets[0] ?? [],
          conditionSets,
          action: WafRuleAction.RateLimit,
          approximate: false,
          countryImpactMode: undefined,
        }
      }
      return {
        conditions: buildPresetRateLimitPreviewConditions(preset, flows),
        action: WafRuleAction.RateLimit,
        approximate: false,
        countryImpactMode: undefined,
      }
    }

    if (activeModal.kind === 'country') {
      const preset = activeModal.preset
      const flows =
        preset.scope === 'otp' ? selectedOtpFlows() : []

      if (preset.mode === 'block') {
        const conditionSets = buildPresetCountryBlockPreviewConditionSets({
          scope: preset.scope,
          flows,
          countryCodes: selectedCountries,
        })
        if (conditionSets.length > 1) {
          return {
            conditions: conditionSets[0] ?? [],
            conditionSets,
            action: WafRuleAction.Deny,
            approximate: false,
            countryImpactMode: 'block' as const,
          }
        }
        return {
          conditions:
            conditionSets[0] ??
            buildPresetCountryPreviewConditions({
              scope: preset.scope,
              mode: preset.mode,
              flows,
              countryCodes: selectedCountries,
            }),
          action: WafRuleAction.Deny,
          approximate: false,
          countryImpactMode: 'block' as const,
        }
      }

      return {
        conditions: buildPresetCountryPreviewConditions({
          scope: preset.scope,
          mode: preset.mode,
          flows,
          countryCodes: selectedCountries,
        }),
        action: WafRuleAction.Deny,
        approximate: exceedsFirewallUsageConditionLimit(
          buildPresetCountryPreviewConditions({
            scope: preset.scope,
            mode: preset.mode,
            flows,
            countryCodes: selectedCountries,
          }),
        ),
        countryImpactMode: 'allow' as const,
      }
    }

    if (activeModal.kind === 'premiumDeny') {
      return {
        conditions: buildPresetPremiumDenyPreviewConditions(
          activeModal.preset.variant,
        ),
        action: WafRuleAction.Deny,
        approximate: false,
        countryImpactMode: undefined,
      }
    }

    return null
  }, [
    activeModal,
    includePhone,
    includeEmail,
    includeSend,
    includeVerify,
    selectedCountries,
  ])

  if (presets.length === 0) return null

  const resolvedDisabled = createDisabled || !canWrite
  const disabledTooltip =
    !canWrite
      ? t("You don't have permission to create firewall rules.")
      : createDisabledTooltip

  const planSupportsPremiumGeoDB = plan?.supportedAddons?.premiumGeoDB === true
  const canUpgradeToPremiumGeoDB =
    billingEnabled &&
    !planSupportsPremiumGeoDB &&
    hasUpgradeablePlanWithAddon(plan, plans, 'premiumGeoDB')

  const openPremiumGeoGate = () => {
    setPickerOpen(false)
    if (planSupportsPremiumGeoDB) {
      setPremiumGeoDialogOpen(true)
      return
    }
    if (canUpgradeToPremiumGeoDB) {
      navigateToUpgradeWizard(navigate, project?.teamId)
      return
    }
    toast.error(t('Premium Geo DB required'))
  }

  const selectPreset = (preset: FirewallRulePresetDefinition) => {
    if (presetRequiresPremiumGeo(preset) && !premiumGeoEnabled) {
      openPremiumGeoGate()
      return
    }
    openPreset(preset)
  }

  const openPreset = (preset: FirewallRulePresetDefinition) => {
    setPickerOpen(false)
    if (preset.kind === 'rateLimit') {
      setLimit(preset.defaultLimit)
      const intervalParts = createTimeUnitPair(preset.defaultInterval, {
        units: FIREWALL_RATE_LIMIT_INTERVAL_UNITS,
      })
      setIntervalValue(intervalParts.value)
      setIntervalUnit(intervalParts.unit)
      if (preset.target === 'otp') {
        setIncludePhone(true)
        setIncludeEmail(true)
        setIncludeSend(true)
        setIncludeVerify(true)
      }
      setActiveModal({ kind: 'rateLimit', preset })
      return
    }
    if (preset.kind === 'premiumDeny') {
      setActiveModal({ kind: 'premiumDeny', preset })
      return
    }
    if (preset.scope === 'otp') {
      setIncludePhone(true)
      setIncludeEmail(true)
      setIncludeSend(true)
      setIncludeVerify(true)
    }
    setSelectedCountries([])
    setCountryPickerValue('')
    setActiveModal({ kind: 'country', preset })
  }

  const closeModal = () => {
    if (createMutation.isPending) return
    setActiveModal(null)
  }

  const addCountry = (code: string) => {
    const normalized = code.trim().toUpperCase()
    if (!normalized) return
    setSelectedCountries((prev) =>
      prev.includes(normalized) ? prev : [...prev, normalized],
    )
    setCountryPickerValue('')
  }

  const removeCountry = (code: string) => {
    setSelectedCountries((prev) => prev.filter((c) => c !== code))
  }

  const submitRateLimit = async () => {
    if (!activeModal || activeModal.kind !== 'rateLimit') return
    const { preset } = activeModal
    const interval = firewallRateLimitIntervalSeconds(intervalValue, intervalUnit)
    if (limit <= 0 || interval < 1) {
      toast.error(t('Request limit and interval must be greater than zero.'))
      return
    }
    if (interval > FIREWALL_RATE_LIMIT_INTERVAL_MAX) {
      toast.error(t('Interval must be between 1 second and 24 hours.'))
      return
    }
    try {
      if (preset.target === 'path') {
        await createMutation.mutateAsync(
          buildApiPathRateLimitRule({
            pathPrefix: preset.pathPrefix,
            method: preset.method,
            limit,
            interval,
            name: t(preset.label),
            description: t(
              'Created from a scraping prevention preset. Review traffic in the impact preview before tightening limits.',
            ),
          }),
        )
        toast.success(t('Firewall rule created'))
        setActiveModal(null)
        return
      }

      const flows = selectedOtpFlows()
      if (flows.length === 0) {
        toast.error(
          t('Select at least one channel and step (phone, email, send, or verification).'),
        )
        return
      }
      for (const flow of flows) {
        await createMutation.mutateAsync(
          buildOtpRateLimitRule({
            flow,
            limit,
            interval,
            name: defaultRateLimitRuleName(t, flow),
            description: t(
              'Created from an OTP protection preset. Adjust limits using the impact preview on future edits.',
            ),
          }),
        )
      }
      toast.success(
        flows.length === 1
          ? t('Firewall rule created')
          : `${flows.length} ${t('firewall rules created')}`,
      )
      setActiveModal(null)
    } catch (error) {
      toast.error(
        getErrorMessage(error as Error, t('Failed to create firewall rule')),
      )
    }
  }

  const submitCountry = async () => {
    if (!activeModal || activeModal.kind !== 'country') return
    const { preset } = activeModal
    if (selectedCountries.length === 0) {
      toast.error(t('Select at least one country.'))
      return
    }

    const mode = preset.mode
    let rules

    if (preset.scope === 'api') {
      rules = buildApiCountryFirewallRules({
        mode,
        countryCodes: selectedCountries,
        ruleNameForCountry: (countryCode) => {
          if (mode === 'block' && countryCode) {
            return `${t('Block API')}: ${countryCode}`
          }
          return t('Allow API only from selected countries')
        },
      })
    } else {
      const flows = selectedOtpFlows()
      if (flows.length === 0) {
        toast.error(
          t('Select at least one channel and step (phone, email, send, or verification).'),
        )
        return
      }
      rules = buildCountryOtpFirewallRules({
        mode,
        flows,
        countryCodes: selectedCountries,
        ruleNameForFlow: (flow, countryCode) => {
          const flowName = flowLabel(t, flow)
          if (mode === 'block' && countryCode) {
            return `${t('Block')} ${countryCode}: ${flowName}`
          }
          return `${t('Allow only selected countries')}: ${flowName}`
        },
      })
    }

    try {
      for (const rule of rules) {
        await createMutation.mutateAsync(rule)
      }
      toast.success(
        rules.length === 1
          ? t('Firewall rule created')
          : `${rules.length} ${t('firewall rules created')}`,
      )
      setActiveModal(null)
    } catch (error) {
      toast.error(
        getErrorMessage(error as Error, t('Failed to create firewall rule')),
      )
    }
  }

  const submitPremiumDeny = async () => {
    if (!activeModal || activeModal.kind !== 'premiumDeny') return
    if (!premiumGeoEnabled) {
      openPremiumGeoGate()
      return
    }
    const { preset } = activeModal
    try {
      await createMutation.mutateAsync(
        buildPremiumNetworkDenyRule({
          variant: preset.variant,
          name: t(preset.label),
          description: t(
            'Created from a scraping prevention preset. Requires Premium Geo DB.',
          ),
        }),
      )
      toast.success(t('Firewall rule created'))
      setActiveModal(null)
    } catch (error) {
      toast.error(
        getErrorMessage(error as Error, t('Failed to create firewall rule')),
      )
    }
  }

  const showPremiumDenyBodyContent = !premiumGeoEnabled && billingEnabled

  const triggerButton = (
    <Button
      type="button"
      variant="outline"
      size="sm"
      disabled={resolvedDisabled}
      className="h-9 gap-1.5 text-[13px] cursor-pointer"
      aria-label={t('Add preset')}
    >
      <List className="h-4 w-4" />
      {t('Add preset')}
    </Button>
  )

  const presetPicker = (
    <Popover open={pickerOpen} onOpenChange={setPickerOpen} modal>
      <PopoverTrigger asChild>{triggerButton}</PopoverTrigger>
      <PopoverContent
        align="end"
        className="flex max-h-[min(360px,var(--radix-popover-content-available-height))] w-[min(100vw-2rem,22rem)] flex-col overflow-hidden p-0"
      >
        <Command className="flex max-h-[min(360px,var(--radix-popover-content-available-height))] flex-col overflow-hidden">
          <CommandInput
            placeholder={t('Search presets...')}
            className="h-9"
          />
          <CommandList className="min-h-0 max-h-[280px] flex-1 overflow-y-auto overscroll-contain">
            <CommandEmpty>{t('No preset found.')}</CommandEmpty>
            {presetGroups.map((group) => (
              <CommandGroup key={group.id} heading={t(group.label)}>
                {group.presets.map((preset) => {
                  const isPremium = presetRequiresPremiumGeo(preset)
                  const premiumLocked = isPremium && !premiumGeoEnabled
                  return (
                    <CommandItem
                      key={preset.id}
                      value={`${t(preset.label)} ${t(preset.description)} ${t(group.label)}`}
                      onSelect={() => selectPreset(preset)}
                      className="px-3 py-2"
                    >
                      <span className="flex w-full min-w-0 items-center gap-2">
                        <span className="min-w-0 flex-1 truncate text-[13px] font-medium">
                          {t(preset.label)}
                        </span>
                        {isPremium && premiumLocked ? (
                          <span className="shrink-0 text-[11px] text-muted-foreground">
                            {t('Add-on')}
                          </span>
                        ) : null}
                      </span>
                    </CommandItem>
                  )
                })}
              </CommandGroup>
            ))}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )

  return (
    <>
      {resolvedDisabled ? (
        <TooltipProvider delayDuration={0}>
          <Tooltip>
            <TooltipTrigger asChild>
              <div>{triggerButton}</div>
            </TooltipTrigger>
            <TooltipContent side="bottom">
              <p>{disabledTooltip}</p>
            </TooltipContent>
          </Tooltip>
        </TooltipProvider>
      ) : (
        presetPicker
      )}

      <Dialog open={activeModal?.kind === 'rateLimit'} onOpenChange={(open) => !open && closeModal()}>
        <DialogContent className="flex max-h-[90dvh] flex-col sm:max-w-lg p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>
              {activeModal?.kind === 'rateLimit'
                ? t(activeModal.preset.label)
                : t('Rate limit OTP')}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {activeModal?.kind === 'rateLimit' &&
              activeModal.preset.target === 'path'
                ? t(
                    'Set a per-IP request quota for matching API traffic. Review the impact preview before tightening limits.',
                  )
                : t(
                    'Set a per-IP request quota for matching OTP traffic. Review traffic after creating the rule.',
                  )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 pb-4 pt-0">
            {activeModal?.kind === 'rateLimit' &&
            activeModal.preset.target === 'otp' ? (
              <OtpScopeFields
                includePhone={includePhone}
                includeEmail={includeEmail}
                includeSend={includeSend}
                includeVerify={includeVerify}
                onPhoneChange={setIncludePhone}
                onEmailChange={setIncludeEmail}
                onSendChange={setIncludeSend}
                onVerifyChange={setIncludeVerify}
                disabled={createMutation.isPending}
              />
            ) : null}
            <div className="flex flex-col gap-2.5">
              <Label className="text-[13px] font-medium leading-snug">
                {t('Request limit')}
              </Label>
              <NumberInput
                value={limit}
                min={1}
                onValueChange={setLimit}
                className="h-9 text-[13px]"
              />
            </div>
            <FirewallRateLimitIntervalField
              value={intervalValue}
              unit={intervalUnit}
              onValueChange={setIntervalValue}
              onUnitChange={setIntervalUnit}
              disabled={createMutation.isPending}
            />
            {presetImpactPreview ? (
              <FirewallPresetImpactPreview
                projectId={projectId}
                resourceType={resourceSelection.resourceType}
                resourceId={resourceSelection.resourceId}
                conditions={presetImpactPreview.conditions}
                conditionSets={presetImpactPreview.conditionSets}
                action={presetImpactPreview.action}
                approximatePreview={presetImpactPreview.approximate}
                countryImpactMode={presetImpactPreview.countryImpactMode}
              />
            ) : null}
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={closeModal}
              disabled={createMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              onClick={() => void submitRateLimit()}
              disabled={createMutation.isPending}
            >
              {activeModal?.kind === 'rateLimit' &&
              activeModal.preset.target === 'otp' &&
              selectedOtpFlows().length > 1
                ? t('Create rules')
                : t('Create rule')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={activeModal?.kind === 'country'} onOpenChange={(open) => !open && closeModal()}>
        <DialogContent className="flex max-h-[90dvh] flex-col sm:max-w-lg p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>
              {activeModal?.kind === 'country'
                ? t(activeModal.preset.label)
                : t('Country OTP restriction')}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {activeModal?.kind === 'country' && activeModal.preset.scope === 'api'
                ? activeModal.preset.mode === 'allow'
                  ? t(
                      'Creates one deny rule. Denies traffic when the country is not in your allow list. Unresolved geo is allowed.',
                    )
                  : t(
                      'Creates one deny rule per country. Matching API requests receive a 403 response.',
                    )
                : activeModal?.kind === 'country' &&
                    activeModal.preset.mode === 'allow'
                  ? t(
                      'Creates one deny rule per selected flow. Denies traffic when the country is not in your allow list. Unresolved geo is allowed.',
                    )
                  : t(
                      'Creates one deny rule per country and flow. Matching OTP requests receive a 403 response.',
                    )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-6 pb-4 pt-0">
            {activeModal?.kind === 'country' &&
            activeModal.preset.scope === 'otp' ? (
              <OtpScopeFields
                includePhone={includePhone}
                includeEmail={includeEmail}
                includeSend={includeSend}
                includeVerify={includeVerify}
                onPhoneChange={setIncludePhone}
                onEmailChange={setIncludeEmail}
                onSendChange={setIncludeSend}
                onVerifyChange={setIncludeVerify}
                disabled={createMutation.isPending}
              />
            ) : null}
            <div className="flex flex-col gap-2.5">
              <div className="space-y-1.5">
                <Label className="text-[13px] font-medium leading-snug">
                  {t('Countries')}
                </Label>
                <p className="text-[12px] leading-snug text-muted-foreground">
                  {t('Select at least one country.')}
                </p>
              </div>
              <div className="flex flex-col gap-2">
                <SearchableSelect
                  value={countryPickerValue}
                  onValueChange={(code) => addCountry(code)}
                  items={countryItems.filter(
                    (item) => !selectedCountries.includes(item.value),
                  )}
                  placeholder={t('Add a country')}
                  searchPlaceholder={t('Search countries...')}
                  emptyMessage={t('No country found.')}
                  disabled={createMutation.isPending}
                  isFetching={countriesLoading}
                  triggerClassName="h-9 w-full"
                  listClassName="min-h-0"
                />
                {selectedCountries.length > 0 ? (
                  <div className="flex flex-wrap gap-1.5">
                    {selectedCountries.map((code) => {
                      const name =
                        countryItems.find((item) => item.value === code)
                          ?.label ?? code
                      return (
                        <span
                          key={code}
                          className="inline-flex items-center gap-1 rounded-md border border-border bg-muted/50 px-2 py-0.5 text-[12px]"
                        >
                          <span className="font-medium">{code}</span>
                          <span className="text-muted-foreground">{name}</span>
                          <button
                            type="button"
                            className="rounded p-0.5 text-muted-foreground hover:text-foreground"
                            aria-label={t('Remove')}
                            onClick={() => removeCountry(code)}
                          >
                            <X className="h-3 w-3" />
                          </button>
                        </span>
                      )
                    })}
                  </div>
                ) : null}
              </div>
            </div>
            {presetImpactPreview ? (
              <FirewallPresetImpactPreview
                projectId={projectId}
                resourceType={resourceSelection.resourceType}
                resourceId={resourceSelection.resourceId}
                conditions={presetImpactPreview.conditions}
                conditionSets={presetImpactPreview.conditionSets}
                action={presetImpactPreview.action}
                approximatePreview={presetImpactPreview.approximate}
                countryImpactMode={presetImpactPreview.countryImpactMode}
              />
            ) : null}
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={closeModal}
              disabled={createMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              onClick={() => void submitCountry()}
              disabled={createMutation.isPending}
            >
              {activeModal?.kind === 'country' &&
              selectedCountries.length > 0 &&
              (() => {
                if (activeModal.preset.scope === 'api') {
                  return (
                    activeModal.preset.mode === 'block' &&
                    selectedCountries.length > 1
                  )
                }
                const flows = selectedOtpFlows()
                const ruleCount =
                  activeModal.preset.mode === 'block'
                    ? selectedCountries.length * flows.length
                    : flows.length
                return ruleCount > 1
              })()
                ? t('Create rules')
                : t('Create rule')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog
        open={activeModal?.kind === 'premiumDeny'}
        onOpenChange={(open) => !open && closeModal()}
      >
        <DialogContent className="flex max-h-[90dvh] flex-col sm:max-w-lg p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>
              {activeModal?.kind === 'premiumDeny'
                ? t(activeModal.preset.label)
                : t('Block network traffic')}
            </DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {activeModal?.kind === 'premiumDeny'
                ? t(activeModal.preset.description)
                : null}
            </DialogDescription>
          </DialogHeader>
          <div className="min-h-0 flex-1 space-y-5 overflow-y-auto border-t border-border px-6 pb-4 pt-0">
            {showPremiumDenyBodyContent ? (
              <div className="space-y-3">
                <p className="text-[13px] text-muted-foreground">
                  {t(
                    'Enable the Premium Geo DB addon for this project to use connection and ISP conditions in firewall rules.',
                  )}
                </p>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={openPremiumGeoGate}
                >
                  {planSupportsPremiumGeoDB
                    ? t('Enable Premium Geo DB')
                    : t('Upgrade plan')}
                </Button>
              </div>
            ) : null}
            {presetImpactPreview ? (
              <FirewallPresetImpactPreview
                projectId={projectId}
                resourceType={resourceSelection.resourceType}
                resourceId={resourceSelection.resourceId}
                conditions={presetImpactPreview.conditions}
                conditionSets={presetImpactPreview.conditionSets}
                action={presetImpactPreview.action}
                approximatePreview={presetImpactPreview.approximate}
                countryImpactMode={presetImpactPreview.countryImpactMode}
              />
            ) : null}
          </div>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              onClick={closeModal}
              disabled={createMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              onClick={() => void submitPremiumDeny()}
              disabled={createMutation.isPending || !premiumGeoEnabled}
            >
              {t('Create rule')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <EnablePremiumGeoDBDialog
        open={premiumGeoDialogOpen}
        onOpenChange={setPremiumGeoDialogOpen}
        projectId={projectId}
      />
    </>
  )
}
