import { useMemo, useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Check, ChevronDown, Search, X } from 'lucide-react'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useAuth } from '@/components/global/auth/RequireAuth'
import {
  organizationQueryOptions,
  organizationsQueryOptions,
  updateAccountPrefs,
} from '@/lib/react-query/hooks'
import { partnerClientsQueryOptions } from '@/lib/react-query/hooks/partners'
import {
  isPartnerOrganization,
  organizationHomeLink,
  partitionConsoleOrganizations,
  type PartnerAwareOrganization,
} from '@/lib/partners'
import { getPlanBadgeColor, getPlanDisplayName } from '@/lib/utils/plan-badge'
import { getPlanNameFromTier } from '@/lib/utils/plan-filter'
import { cn } from '@/lib/utils'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { prefetchOrganizationOverviewData } from '@/lib/organization-overview-prefetch'

type OrganizationSwitcherProps = {
  organizationId: string
  isMobile?: boolean
  className?: string
}

export function OrganizationSwitcher({
  organizationId,
  isMobile = false,
  className,
}: OrganizationSwitcherProps) {
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { account } = useAuth()
  const { isCloud } = useConsoleProfile()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const { data: organizationsData } = useQuery(organizationsQueryOptions())
  const { data: selectedOrg } = useQuery(organizationQueryOptions(organizationId))

  const teams = (organizationsData?.teams ?? []) as PartnerAwareOrganization[]
  const selected =
    (selectedOrg as PartnerAwareOrganization | null) ||
    teams.find((org) => org.$id === organizationId) ||
    null

  const { partnerPlatform, developersPlatform } = useMemo(() => {
    const query = search.trim().toLowerCase()
    const matches = (org: PartnerAwareOrganization) =>
      !query || (org.name || org.$id).toLowerCase().includes(query)
    const partitioned = partitionConsoleOrganizations(teams)
    return {
      partnerPlatform: partitioned.partnerPlatform.filter(matches),
      developersPlatform: partitioned.developersPlatform.filter(matches),
    }
  }, [organizationsData, search, teams])

  const handleSelect = async (org: PartnerAwareOrganization) => {
    setOpen(false)
    if (org.$id === organizationId) return
    const isPartner = isPartnerOrganization(org)
    try {
      if (isPartner) {
        await queryClient.ensureQueryData(organizationQueryOptions(org.$id))
        await queryClient.ensureQueryData(partnerClientsQueryOptions(org.$id))
      } else {
        await prefetchOrganizationOverviewData(queryClient, org.$id)
      }
    } catch {
      // Navigation still works if prefetch fails.
    }
    void navigate(organizationHomeLink(org.$id, isPartner))
    const prefs = (account as { prefs?: Record<string, unknown> } | null)?.prefs
    try {
      await updateAccountPrefs({
        ...prefs,
        organization: org.$id,
      })
    } catch {
      // Prefs are best-effort.
    }
  }

  const content = (
    <OrganizationSwitcherContent
      search={search}
      onSearchChange={setSearch}
      partnerPlatform={partnerPlatform}
      developersPlatform={developersPlatform}
      selectedId={organizationId}
      isCloud={isCloud}
      onSelect={handleSelect}
    />
  )

  if (isMobile) {
    return (
      <>
        <button
          type="button"
          {...analyticsAttrs('organization-switcher')}
          onClick={() => setOpen(true)}
          className={cn(
            'flex w-full items-center gap-2 overflow-visible rounded-md border border-border bg-background px-2.5 py-2 text-start transition-colors hover:bg-accent cursor-pointer',
            className,
          )}
        >
          <TriggerBody organization={selected} />
        </button>
        <Dialog open={open} onOpenChange={setOpen}>
          <DialogContent
            className="fixed inset-0 start-0 top-0 z-[132] flex h-[100dvh] max-h-none w-[100dvw] max-w-none translate-x-0 translate-y-0 flex-col gap-0 rounded-none border-0 p-0 sm:max-w-none sm:rounded-none"
            overlayClassName="z-[131]"
            showCloseButton={false}
          >
            <DialogTitle className="sr-only">Switch organization</DialogTitle>
            <div className="flex items-center justify-between border-b border-border px-4 py-3">
              <h2 className="text-[15px] font-semibold text-foreground">
                Switch organization
              </h2>
              <button
                type="button"
                onClick={() => setOpen(false)}
                className="flex h-8 w-8 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
              >
                <X className="h-5 w-5" />
              </button>
            </div>
            {content}
          </DialogContent>
        </Dialog>
      </>
    )
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <button
          type="button"
          {...analyticsAttrs('organization-switcher')}
          className={cn(
            'flex h-9 max-w-full min-w-0 cursor-pointer items-center gap-2 overflow-visible rounded-md px-2 py-1.5 text-start transition-colors hover:bg-accent disabled:pointer-events-none disabled:opacity-50',
            className,
          )}
        >
          <TriggerBody organization={selected} />
        </button>
      </PopoverTrigger>
      <PopoverContent
        side="bottom"
        align="start"
        sideOffset={8}
        className="w-[320px] border-border bg-popover p-0"
      >
        {content}
      </PopoverContent>
    </Popover>
  )
}

function TriggerBody({
  organization,
}: {
  organization: PartnerAwareOrganization | null
}) {
  const name = organization?.name || 'Organization'
  const plan = getPlanNameFromTier(
    organization?.billingPlanId || organization?.billingPlan,
  )
  return (
    <>
      <InitialsAvatar name={name} size="sm" />
      <div className="flex min-w-0 flex-1 items-center gap-2 overflow-visible">
        <p
          className="min-w-0 flex-1 truncate text-[13px] font-medium text-foreground"
          title={name}
        >
          {name}
        </p>
        {organization ? (
          <span
            className={cn(
              'shrink-0 rounded-md px-1.5 py-0.5 text-[10px] font-medium capitalize',
              getPlanBadgeColor(plan),
            )}
          >
            {getPlanDisplayName(plan)}
          </span>
        ) : null}
      </div>
      <ChevronDown className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
    </>
  )
}

function OrganizationSwitcherContent({
  search,
  onSearchChange,
  partnerPlatform,
  developersPlatform,
  selectedId,
  isCloud,
  onSelect,
}: {
  search: string
  onSearchChange: (value: string) => void
  partnerPlatform: PartnerAwareOrganization[]
  developersPlatform: PartnerAwareOrganization[]
  selectedId: string
  isCloud: boolean
  onSelect: (org: PartnerAwareOrganization) => void
}) {
  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
        <Search className="h-3.5 w-3.5 text-muted-foreground" />
        <input
          type="text"
          placeholder="Find organization..."
          value={search}
          onChange={(event) => onSearchChange(event.target.value)}
          className="flex-1 bg-transparent text-[13px] text-foreground placeholder:text-muted-foreground focus:outline-none"
        />
      </div>
      <div className="min-h-[180px] max-h-[320px] overflow-y-auto p-1.5">
        <OrgGroup
          label="Partner platform"
          orgs={partnerPlatform}
          selectedId={selectedId}
          isCloud={isCloud}
          onSelect={onSelect}
        />
        <OrgGroup
          label="Developers platform"
          orgs={developersPlatform}
          selectedId={selectedId}
          isCloud={isCloud}
          onSelect={onSelect}
        />
        {partnerPlatform.length === 0 && developersPlatform.length === 0 ? (
          <p className="px-2 py-4 text-center text-[12px] text-muted-foreground">
            No organizations found
          </p>
        ) : null}
      </div>
    </div>
  )
}

function OrgGroup({
  label,
  orgs,
  selectedId,
  isCloud,
  onSelect,
}: {
  label: string
  orgs: PartnerAwareOrganization[]
  selectedId: string
  isCloud: boolean
  onSelect: (org: PartnerAwareOrganization) => void
}) {
  if (orgs.length === 0) return null
  return (
    <div className="mb-1.5">
      <p className="px-2 py-1.5 text-[11px] font-medium text-muted-foreground">
        {label}
      </p>
      <div className="space-y-0.5">
        {orgs.map((org) => {
          const plan = getPlanNameFromTier(org.billingPlanId || org.billingPlan)
          return (
            <button
              key={org.$id}
              type="button"
              onClick={() => onSelect(org)}
              className={cn(
                'flex w-full cursor-pointer items-center gap-2.5 rounded-md px-2 py-1.5 text-start transition-colors',
                selectedId === org.$id ? 'bg-accent' : 'hover:bg-accent/50',
              )}
            >
              <InitialsAvatar name={org.name || org.$id} size="sm" />
              <div className="flex min-w-0 flex-1 items-center gap-2">
                <span className="truncate text-[13px] font-medium text-foreground">
                  {org.name || org.$id}
                </span>
                {isCloud ? (
                  <span
                    className={cn(
                      'shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium capitalize',
                      getPlanBadgeColor(plan),
                    )}
                  >
                    {getPlanDisplayName(plan)}
                  </span>
                ) : null}
              </div>
              {selectedId === org.$id ? (
                <Check className="h-3.5 w-3.5 shrink-0 text-foreground" />
              ) : null}
            </button>
          )
        })}
      </div>
    </div>
  )
}
