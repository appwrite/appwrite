import { useState, type LucideIcon } from 'react'
import {
  Activity,
  AppWindow,
  Building,
  Building2,
  Cable,
  Check,
  ChevronDown,
  Clock,
  Cloud,
  Compass,
  Cookie,
  Fingerprint,
  Globe,
  Globe2,
  Hash,
  KeyRound,
  Languages,
  LocateFixed,
  Mailbox,
  MapPin,
  Monitor,
  Network,
  Route,
  SearchCode,
  Send,
  Server,
  Tags,
  UserRound,
  Wifi,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { useModalAwarePopover } from '@/lib/layout/modal-portal-host'
import {
  FIREWALL_CONDITION_ATTRIBUTE_GROUPS,
  FIREWALL_CONDITION_ATTRIBUTES,
  isPremiumAttribute,
  type FirewallConditionAttribute,
} from '@/lib/firewall/conditions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

export const ATTRIBUTE_ICONS: Record<FirewallConditionAttribute, LucideIcon> = {
  ip: Fingerprint,
  host: Server,
  path: Route,
  method: Send,
  protocol: Network,
  headers: Tags,
  query: SearchCode,
  queryKeys: KeyRound,
  accept: Check,
  acceptLanguage: Languages,
  cookie: Cookie,
  country: Globe2,
  continent: Globe,
  city: Building2,
  state: MapPin,
  postalCode: Mailbox,
  latitude: LocateFixed,
  longitude: Compass,
  timeZone: Clock,
  weatherCode: Cloud,
  isp: Wifi,
  autonomousSystemNumber: Hash,
  autonomousSystemOrganization: Building,
  connectionType: Cable,
  connectionUsageType: Activity,
  connectionOrganization: Building,
  os: Monitor,
  browser: AppWindow,
  userAgent: UserRound,
}

interface ConditionAttributeSelectProps {
  value: FirewallConditionAttribute
  onValueChange: (value: FirewallConditionAttribute) => void
  disabled?: boolean
  premiumGeoEnabled: boolean
  triggerClassName?: string
}

export function ConditionAttributeSelect({
  value,
  onValueChange,
  disabled = false,
  premiumGeoEnabled,
  triggerClassName,
}: ConditionAttributeSelectProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const { rootRef, portalContainer, modal, handleOpenChange } =
    useModalAwarePopover()

  const selectedLabel =
    FIREWALL_CONDITION_ATTRIBUTES.find((attr) => attr.value === value)?.label ??
    value
  const SelectedIcon = ATTRIBUTE_ICONS[value] ?? Route

  return (
    <div ref={rootRef} className="contents">
      <Popover
        open={open}
        modal={modal}
        onOpenChange={(nextOpen) => {
          handleOpenChange(nextOpen)
          setOpen(nextOpen)
        }}
      >
        <PopoverTrigger asChild>
          <Button
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            disabled={disabled}
            className={cn(
              'h-9 min-w-0 flex-1 justify-between gap-2 text-[13px] font-normal',
              triggerClassName,
            )}
          >
            <span className="flex min-w-0 items-center gap-2 truncate">
              <SelectedIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
              <span className="truncate">{t(selectedLabel)}</span>
            </span>
            <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          container={portalContainer}
          className="max-h-[min(320px,var(--radix-popover-content-available-height))] w-[var(--radix-popover-trigger-width)] overflow-hidden p-0"
          align="start"
          onWheelCapture={(event) => {
            event.stopPropagation()
          }}
          onCloseAutoFocus={(event) => {
            if (portalContainer) event.preventDefault()
          }}
        >
          <Command className="overflow-hidden">
            <CommandInput
              placeholder={t('Search types...')}
              className="h-9 text-[13px]"
              onKeyDown={(event) => {
                if (
                  event.key === 'ArrowDown' ||
                  event.key === 'ArrowUp' ||
                  event.key === 'Home' ||
                  event.key === 'End' ||
                  event.key === 'Enter' ||
                  event.key === 'Escape'
                ) {
                  return
                }
                event.stopPropagation()
              }}
            />
            <CommandList className="min-h-[180px] max-h-[240px] overflow-y-auto overscroll-contain">
              <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
                {t('No types found')}
              </CommandEmpty>
              {FIREWALL_CONDITION_ATTRIBUTE_GROUPS.map((group) => (
                <CommandGroup key={group.label} heading={t(group.label)}>
                  {group.attributes.map((attr) => {
                    const ItemIcon = ATTRIBUTE_ICONS[attr.value] ?? Route
                    const premiumLocked =
                      isPremiumAttribute(attr.value) && !premiumGeoEnabled
                    const label = t(attr.label)
                    const groupLabel = t(group.label)

                    return (
                      <CommandItem
                        key={attr.value}
                        value={`${attr.value} ${label} ${groupLabel}`}
                        disabled={premiumLocked}
                        className="text-[13px]"
                        onSelect={() => {
                          onValueChange(attr.value)
                          setOpen(false)
                        }}
                      >
                        <span className="flex min-w-0 items-center gap-2">
                          <ItemIcon className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                          <span className="truncate">{label}</span>
                          {isPremiumAttribute(attr.value) ? (
                            <span className="rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground">
                              {premiumLocked
                                ? t('Premium Geo DB required')
                                : t('Premium')}
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
    </div>
  )
}
