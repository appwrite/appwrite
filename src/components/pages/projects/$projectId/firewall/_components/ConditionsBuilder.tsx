import { useMemo } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import {
  AppWindow,
  Building2,
  Fingerprint,
  Globe,
  Globe2,
  MapPin,
  Monitor,
  Plus,
  Route,
  SearchCode,
  Send,
  Server,
  Tags,
  Trash2,
  UserRound,
  type LucideIcon,
} from 'lucide-react'
import {
  FIREWALL_CONDITION_ATTRIBUTE_GROUPS,
  FIREWALL_HTTP_METHODS,
  createEmptyConditionDraft,
  getOperatorsForAttribute,
  isDynamicKeyAttribute,
  isNoValueOperator,
  isOperatorAllowedForAttribute,
  isPremiumAttribute,
  isTextMatchOperator,
  type FirewallConditionAttribute,
  type FirewallConditionDraft,
  type FirewallConditionOperator,
  type FirewallResourceType,
} from '@/lib/firewall/conditions'
import {
  FIREWALL_CREATABLE_ACTIONS,
  getFirewallActionDotClass,
  getFirewallActionLabel,
  type FirewallCreatableAction,
} from '@/lib/firewall/actions'
import {
  useContinents,
  useCountries,
  useProjectAddons,
} from '@/lib/react-query/hooks'
import {
  ADDON_KEY_PREMIUM_GEO_DB,
  findActiveOrPendingAddon,
} from '@/lib/billing/addons'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'
import type { ReactNode } from 'react'

const ATTRIBUTE_ICONS: Record<FirewallConditionAttribute, LucideIcon> = {
  ip: Fingerprint,
  host: Server,
  path: Route,
  method: Send,
  headers: Tags,
  query: SearchCode,
  country: Globe2,
  continent: Globe,
  city: Building2,
  state: MapPin,
  os: Monitor,
  browser: AppWindow,
  userAgent: UserRound,
}

/** Examples only. Never use bare `/` - it looks like a real value when the field is empty. */
const PATH_PLACEHOLDERS: Record<FirewallResourceType, string> = {
  api: 'e.g. /v1/account',
  functions: 'e.g. /api',
  sites: 'e.g. /about',
}

interface ConditionsBuilderProps {
  conditions: FirewallConditionDraft[]
  onChange: (
    next:
      | FirewallConditionDraft[]
      | ((prev: FirewallConditionDraft[]) => FirewallConditionDraft[]),
  ) => void
  disabled?: boolean
  resourceType?: FirewallResourceType
  /** When set, renders the Vercel-style Then action row. */
  action?: FirewallCreatableAction
  onActionChange?: (action: FirewallCreatableAction) => void
  /** When true, shows the Then action but does not allow changing it. */
  actionReadOnly?: boolean
  /** Extra fields under the Then action (rate limit / redirect). */
  actionExtras?: ReactNode
  className?: string
}

function RailLabel({ children }: { children: ReactNode }) {
  return (
    <span className="relative z-10 bg-card px-1.5 text-[13px] font-semibold leading-none text-foreground">
      {children}
    </span>
  )
}

/**
 * Key input for headers / query conditions. The API prefix (`headers.` /
 * `query.`) is added automatically on submit.
 */
function ConditionKeyInput({
  attribute,
  value,
  disabled,
  onChange,
}: {
  attribute: 'headers' | 'query'
  value: string
  disabled?: boolean
  onChange: (value: string) => void
}) {
  const t = useT()
  return (
    <Input
      value={value}
      disabled={disabled}
      placeholder={
        attribute === 'headers'
          ? t('Key, e.g. x-custom-header')
          : t('Key, e.g. token')
      }
      className="h-9 w-full font-mono text-[13px]"
      onChange={(e) => onChange(e.target.value)}
    />
  )
}

function ConditionValueInput({
  attribute,
  operator,
  value,
  disabled,
  pathPlaceholder,
  onChange,
}: {
  attribute: FirewallConditionAttribute
  operator: FirewallConditionOperator
  value: string
  disabled?: boolean
  pathPlaceholder: string
  onChange: (value: string) => void
}) {
  const t = useT()
  const { data: countriesData, isLoading: countriesLoading } = useCountries()
  const { data: continentsData } = useContinents()

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

  switch (attribute) {
    case 'method':
      return (
        <Select
          value={value || undefined}
          disabled={disabled}
          onValueChange={onChange}
        >
          <SelectTrigger className="h-9 w-full">
            <SelectValue placeholder={t('Select method')} />
          </SelectTrigger>
          <SelectContent>
            {FIREWALL_HTTP_METHODS.map((method) => (
              <SelectItem key={method} value={method}>
                {method}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )

    case 'country':
      // Text-match operators need a free-text value; a country picker can only
      // express an exact code, matching usage where country is a string column.
      if (isTextMatchOperator(operator)) {
        return (
          <Input
            value={value}
            disabled={disabled}
            placeholder={t('e.g. US')}
            className="h-9 w-full font-mono text-[13px]"
            onChange={(e) => onChange(e.target.value)}
          />
        )
      }
      return (
        <SearchableSelect
          value={value.toUpperCase()}
          onValueChange={onChange}
          items={countryItems}
          placeholder={t('Select a country')}
          searchPlaceholder={t('Search countries...')}
          emptyMessage={t('No country found.')}
          disabled={disabled}
          isFetching={countriesLoading}
          triggerClassName="h-9 w-full"
        />
      )

    case 'continent':
      // Text-match operators need a free-text value, same as country.
      if (isTextMatchOperator(operator)) {
        return (
          <Input
            value={value}
            disabled={disabled}
            placeholder={t('e.g. EU')}
            className="h-9 w-full font-mono text-[13px]"
            onChange={(e) => onChange(e.target.value)}
          />
        )
      }
      return (
        <Select
          value={value || undefined}
          disabled={disabled}
          onValueChange={onChange}
        >
          <SelectTrigger className="h-9 w-full">
            <SelectValue placeholder={t('Select a continent')} />
          </SelectTrigger>
          <SelectContent>
            {(continentsData?.continents ?? []).map((continent) => (
              <SelectItem
                key={continent.code}
                value={continent.code.toUpperCase()}
              >
                {continent.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )

    case 'path':
      return (
        <Input
          value={value}
          disabled={disabled}
          placeholder={pathPlaceholder}
          className="h-9 w-full font-mono text-[13px]"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    case 'ip':
      // CIDR blocks only match on equal / not equal server-side.
      return (
        <Input
          value={value}
          disabled={disabled}
          placeholder={
            isTextMatchOperator(operator)
              ? t('e.g. 203.0.113.10')
              : t('e.g. 203.0.113.10 or CIDR range 203.0.113.0/24')
          }
          className="h-9 w-full font-mono text-[13px]"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    case 'host':
      return (
        <Input
          value={value}
          disabled={disabled}
          placeholder={t('e.g. api.example.com')}
          className="h-9 w-full font-mono text-[13px]"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    case 'city':
      return (
        <Input
          value={value}
          disabled={disabled}
          placeholder={t('e.g. London')}
          className="h-9 w-full font-mono text-[13px]"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    case 'state':
      return (
        <Input
          value={value}
          disabled={disabled}
          placeholder={t('e.g. California')}
          className="h-9 w-full font-mono text-[13px]"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    case 'os':
      return (
        <Input
          value={value}
          disabled={disabled}
          placeholder={t('e.g. Windows')}
          className="h-9 w-full font-mono text-[13px]"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    case 'browser':
      return (
        <Input
          value={value}
          disabled={disabled}
          placeholder={t('e.g. Chrome')}
          className="h-9 w-full font-mono text-[13px]"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    case 'userAgent':
      return (
        <Input
          value={value}
          disabled={disabled}
          placeholder={t('e.g. curl/8.0')}
          className="h-9 w-full font-mono text-[13px]"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    case 'headers':
    case 'query':
      return (
        <Input
          value={value}
          disabled={disabled}
          placeholder={t('Value')}
          className="h-9 w-full font-mono text-[13px]"
          onChange={(e) => onChange(e.target.value)}
        />
      )

    default:
      return (
        <Input
          value={value}
          disabled={disabled}
          placeholder={t('Value')}
          className="h-9 w-full"
          onChange={(e) => onChange(e.target.value)}
        />
      )
  }
}

export function ConditionsBuilder({
  conditions,
  onChange,
  disabled,
  resourceType = 'api',
  action,
  onActionChange,
  actionReadOnly = false,
  actionExtras,
  className,
}: ConditionsBuilderProps) {
  const t = useT()
  const showThen =
    action != null && (onActionChange != null || actionReadOnly)
  const pathPlaceholder = PATH_PLACEHOLDERS[resourceType] ?? 'e.g. /v1/account'

  // Premium geo attributes (city / state) are only selectable when the project
  // has the Premium Geo DB addon active - the API rejects them otherwise.
  // Profiles without billing (self-hosted) have no addon gating.
  const { features } = useConsoleProfile()
  const params = useParams({ strict: false })
  const projectId = params.projectId as string | undefined
  const { addons } = useProjectAddons(
    features.billing ? (projectId ?? null) : null,
  )
  const premiumGeoEnabled =
    !features.billing ||
    findActiveOrPendingAddon(addons, ADDON_KEY_PREMIUM_GEO_DB)?.status ===
      'active'

  const updateAt = (index: number, patch: Partial<FirewallConditionDraft>) => {
    // Functional update so rapid typing never applies against a stale conditions array.
    onChange((prev) =>
      prev.map((condition, i) =>
        i === index ? { ...condition, ...patch } : condition,
      ),
    )
  }

  const setAttribute = (
    index: number,
    attribute: FirewallConditionAttribute,
  ) => {
    onChange((prev) =>
      prev.map((condition, i) => {
        if (i !== index) return condition
        const nextOperator = isOperatorAllowedForAttribute(
          attribute,
          condition.operator,
        )
          ? condition.operator
          : ('equal' as FirewallConditionOperator)
        return {
          ...condition,
          attribute,
          operator: nextOperator,
          // Clear value/key when switching attribute; method/country need discrete picks.
          value: '',
          key: '',
        }
      }),
    )
  }

  const removeAt = (index: number) => {
    onChange((prev) => {
      if (prev.length <= 1) {
        return [createEmptyConditionDraft()]
      }
      return prev.filter((_, i) => i !== index)
    })
  }

  const addCondition = () => {
    onChange((prev) => [...prev, createEmptyConditionDraft()])
  }

  return (
    <div
      className={cn(
        'overflow-hidden rounded-xl border border-border bg-card',
        className,
      )}
    >
      <div className="border-b border-border px-5 py-3.5">
        <h3 className="text-[14px] font-semibold text-foreground">
          {t('Configure')}
        </h3>
      </div>

      <div className="relative px-5 py-5">
        <div className="relative grid grid-cols-[3rem_minmax(0,1fr)] gap-x-3 gap-y-3">
          {/* Rail centered in the label column; labels/+ sit above it with opaque bg */}
          <div
            className="pointer-events-none absolute inset-y-0 start-0 w-12"
            aria-hidden
          >
            <div className="absolute inset-y-0 left-1/2 w-px -translate-x-1/2 bg-border" />
          </div>
          {conditions.map((condition, index) => {
            const AttributeIcon =
              ATTRIBUTE_ICONS[condition.attribute] ?? Route
            const operators = getOperatorsForAttribute(condition.attribute)
            return (
              <div key={condition.id} className="contents">
                <div className="relative z-10 flex items-center justify-center self-center">
                  <RailLabel>{index === 0 ? t('If') : t('And')}</RailLabel>
                </div>
                <div className="rounded-xl border border-border bg-background p-2.5">
                  <div className="flex items-start gap-2">
                    <div className="flex min-w-0 flex-1 flex-col gap-2">
                      <div className="flex min-w-0 items-center gap-2">
                        <Select
                          value={condition.attribute}
                          disabled={disabled}
                          onValueChange={(value) =>
                            setAttribute(
                              index,
                              value as FirewallConditionAttribute,
                            )
                          }
                        >
                          <SelectTrigger className="h-9 min-w-0 flex-1">
                            <span className="flex min-w-0 items-center gap-2">
                              <AttributeIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                              <SelectValue />
                            </span>
                          </SelectTrigger>
                          <SelectContent>
                            {FIREWALL_CONDITION_ATTRIBUTE_GROUPS.map(
                              (group) => (
                                <SelectGroup key={group.label}>
                                  <SelectLabel>{t(group.label)}</SelectLabel>
                                  {group.attributes.map((attr) => {
                                    const premiumLocked =
                                      isPremiumAttribute(attr.value) &&
                                      !premiumGeoEnabled
                                    return (
                                      <SelectItem
                                        key={attr.value}
                                        value={attr.value}
                                        disabled={premiumLocked}
                                      >
                                        <span className="flex items-center gap-2">
                                          {t(attr.label)}
                                          {isPremiumAttribute(attr.value) ? (
                                            <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                                              {premiumLocked
                                                ? t('Premium Geo DB required')
                                                : t('Premium')}
                                            </span>
                                          ) : null}
                                        </span>
                                      </SelectItem>
                                    )
                                  })}
                                </SelectGroup>
                              ),
                            )}
                          </SelectContent>
                        </Select>

                        <Select
                          value={condition.operator}
                          disabled={disabled}
                          onValueChange={(value) =>
                            updateAt(index, {
                              operator: value as FirewallConditionOperator,
                            })
                          }
                        >
                          <SelectTrigger className="h-9 w-[9.5rem] shrink-0">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {operators.map((op) => (
                              <SelectItem key={op.value} value={op.value}>
                                {t(op.label)}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>

                      {isDynamicKeyAttribute(condition.attribute) ? (
                        <div className="flex min-w-0 items-center gap-2">
                          <div className="min-w-0 flex-1">
                            <ConditionKeyInput
                              attribute={condition.attribute}
                              value={condition.key ?? ''}
                              disabled={disabled}
                              onChange={(nextKey) =>
                                updateAt(index, { key: nextKey })
                              }
                            />
                          </div>
                          {isNoValueOperator(condition.operator) ? null : (
                            <div className="min-w-0 flex-1">
                              <ConditionValueInput
                                attribute={condition.attribute}
                                operator={condition.operator}
                                value={condition.value}
                                disabled={disabled}
                                pathPlaceholder={pathPlaceholder}
                                onChange={(nextValue) =>
                                  updateAt(index, { value: nextValue })
                                }
                              />
                            </div>
                          )}
                        </div>
                      ) : isNoValueOperator(condition.operator) ? null : (
                        <ConditionValueInput
                          attribute={condition.attribute}
                          operator={condition.operator}
                          value={condition.value}
                          disabled={disabled}
                          pathPlaceholder={pathPlaceholder}
                          onChange={(nextValue) =>
                            updateAt(index, { value: nextValue })
                          }
                        />
                      )}
                    </div>

                    <Button
                      type="button"
                      variant="ghost"
                      size="icon"
                      className="h-9 w-9 shrink-0 self-center text-muted-foreground hover:text-foreground"
                      disabled={disabled}
                      onClick={() => removeAt(index)}
                      aria-label={t('Remove condition')}
                    >
                      <Trash2 className="h-3.5 w-3.5" />
                    </Button>
                  </div>
                </div>
              </div>
            )
          })}

          <div className="relative z-10 flex items-center justify-center self-center">
            {/* Opaque pad so the rail does not pass through the button */}
            <span className="bg-card p-1.5">
              <Button
                type="button"
                variant="outline"
                size="icon"
                className="h-7 w-7 rounded-md border-border bg-card text-muted-foreground hover:bg-card hover:text-foreground"
                disabled={disabled}
                onClick={addCondition}
                aria-label={t('Add condition')}
              >
                <Plus className="h-3.5 w-3.5" />
              </Button>
            </span>
          </div>
          <div aria-hidden />

          {showThen ? (
            <>
              <div className="relative z-10 flex justify-center self-start pt-2.5">
                <RailLabel>{t('Then')}</RailLabel>
              </div>
              <div className="space-y-3 rounded-xl border border-border bg-background p-2.5">
                {actionReadOnly ? (
                  <div className="flex h-9 w-full items-center gap-2 rounded-md border border-input bg-muted/40 px-3 text-[13px] text-muted-foreground sm:max-w-xs">
                    <span
                      className={cn(
                        'h-2 w-2 shrink-0 rounded-full',
                        getFirewallActionDotClass(String(action)),
                      )}
                    />
                    <span className="truncate">
                      {t(getFirewallActionLabel(String(action)))}
                    </span>
                  </div>
                ) : (
                  <Select
                    value={action}
                    disabled={disabled || !onActionChange}
                    onValueChange={(value) =>
                      onActionChange?.(value as FirewallCreatableAction)
                    }
                  >
                    <SelectTrigger className="h-9 w-full sm:max-w-xs">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {FIREWALL_CREATABLE_ACTIONS.map((item) => (
                        <SelectItem key={item} value={item}>
                          <span className="flex items-center gap-2">
                            <span
                              className={cn(
                                'h-2 w-2 shrink-0 rounded-full',
                                getFirewallActionDotClass(item),
                              )}
                            />
                            {t(getFirewallActionLabel(item))}
                          </span>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
                {actionExtras ? (
                  <div className="border-t border-border pt-3">
                    {actionExtras}
                  </div>
                ) : null}
              </div>
            </>
          ) : null}
        </div>
      </div>
    </div>
  )
}
