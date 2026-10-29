import { type ReactNode, useEffect, useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Plus, Search, Users } from 'lucide-react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canInviteOrgMember } from '@/lib/console-access-checks'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { InviteMembersDialog } from '@/components/pages/organizations/$orgId/overview/InviteMembers'
import { OrganizationMembersTable } from '@/components/pages/organizations/$orgId/overview/_components/OrganizationMembersTable'
import {
  activeProjectsQueryOptions,
  mapOrganizationMembershipsToTeamMembers,
  organizationMembershipsQueryOptions,
  useOrganizationPlan,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { ROWS_DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const MEMBERS_PAGE_SIZE_OPTIONS = [25, 50, 100]

export function OrganizationMembersManager({
  organizationId,
  header,
  inviteButtonClassName,
  searchWrapClassName,
  searchInputClassName,
  contentClassName,
}: {
  organizationId: string
  header: (parts: {
    inviteButton: ReactNode
    searchField: ReactNode
  }) => ReactNode
  inviteButtonClassName?: string
  searchWrapClassName?: string
  searchInputClassName?: string
  contentClassName?: string
}) {
  const t = useT()
  const [inviteOpen, setInviteOpen] = useState(false)
  const [searchValue, setSearchValue] = useState('')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(ROWS_DEFAULT_PAGE_SIZE)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(organizationId)
  const { plan } = useOrganizationPlan(organizationId)
  const canInvite = canInviteOrgMember(access, features)
  const inviteDisabledTooltip = canInvite
    ? undefined
    : t("You don't have permission to invite members.")
  const search = searchValue.trim()
  const searchActive = search.length > 0

  const requestedQuery = useQuery({
    ...organizationMembershipsQueryOptions(
      organizationId,
      requestedPage - 1,
      pageSize,
      search || undefined,
    ),
    placeholderData: keepPreviousData,
  })
  const displayedQuery = useQuery({
    ...organizationMembershipsQueryOptions(
      organizationId,
      displayedPage - 1,
      pageSize,
      search || undefined,
    ),
    placeholderData: keepPreviousData,
  })

  useEffect(() => {
    if (!requestedQuery.isFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [requestedQuery.isFetching, requestedPage, displayedPage])

  const memberships = mapOrganizationMembershipsToTeamMembers(
    displayedQuery.data,
    organizationId,
  )
  const memberTotal = displayedQuery.data?.total ?? memberships.length
  const isLoading = displayedQuery.isLoading && memberships.length === 0
  const showPagination = memberTotal > pageSize
  const supportsProjectRoles = Boolean(
    features.orgRoles && plan?.supportsProjectSpecificRoles,
  )

  const projectsQuery = useQuery(
    activeProjectsQueryOptions(organizationId, 0, 100),
  )
  const projectNameById = useMemo(() => {
    const map = new Map<string, string>()
    for (const project of projectsQuery.data?.projects ?? []) {
      map.set(project.$id, project.name ?? project.$id)
    }
    return map
  }, [projectsQuery.data?.projects])

  useEffect(() => {
    const totalPages = Math.max(1, Math.ceil(memberTotal / pageSize))
    if (memberTotal > 0 && requestedPage > totalPages) {
      setRequestedPage(totalPages)
    }
  }, [memberTotal, pageSize, requestedPage])

  const inviteButton = inviteDisabledTooltip ? (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex">
            <Button
              size="sm"
              variant="outline"
              className={inviteButtonClassName}
              disabled
              {...analyticsAttrs('invite-org-member')}
            >
              <Plus className="h-3.5 w-3.5" />
              {t('Invite')}
            </Button>
          </span>
        </TooltipTrigger>
        <TooltipContent className="max-w-xs text-xs">
          {inviteDisabledTooltip}
        </TooltipContent>
      </Tooltip>
    </TooltipProvider>
  ) : (
    <Button
      size="sm"
      variant="outline"
      className={inviteButtonClassName}
      onClick={() => setInviteOpen(true)}
      {...analyticsAttrs('invite-org-member')}
    >
      <Plus className="h-3.5 w-3.5" />
      {t('Invite')}
    </Button>
  )

  const searchField = (
    <div className={cn('relative', searchWrapClassName)}>
      <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
      <Input
        placeholder={t('Search members...')}
        value={searchValue}
        onChange={(event) => {
          setSearchValue(event.target.value)
          setRequestedPage(1)
          setDisplayedPage(1)
        }}
        className={searchInputClassName}
      />
    </div>
  )

  return (
    <>
      {header({ inviteButton, searchField })}
      {isLoading ? (
        <div className={cn('min-h-0 flex-1 overflow-y-auto p-4', contentClassName)}>
          <p className="text-[13px] text-muted-foreground">
            {t('Loading members...')}
          </p>
        </div>
      ) : memberships.length === 0 ? (
        <div className={cn('min-h-0 flex-1 overflow-y-auto p-4', contentClassName)}>
          <EmptyState
            icon={Users}
            title={searchActive ? undefined : t('No members found')}
            description={
              searchActive
                ? undefined
                : t('Invite organization members to collaborate on your projects')
            }
            isEmpty={!searchActive}
            hasFilters={searchActive}
            variant="card"
            action={
              !searchActive && canInvite ? (
                <Button size="sm" onClick={() => setInviteOpen(true)}>
                  {t('Invite')}
                </Button>
              ) : undefined
            }
          />
        </div>
      ) : (
        <div className={cn('min-h-0 flex-1 overflow-auto', contentClassName)}>
          <OrganizationMembersTable
            organizationId={organizationId}
            members={memberships}
            projectNameById={projectNameById}
            showRoles={Boolean(features.orgRoles)}
            showProjectRoles={supportsProjectRoles}
            showMfa={Boolean(features.accountMfa)}
          />
        </div>
      )}
      {showPagination ? (
        <div className="h-[54px] shrink-0 border-t border-border bg-background px-4">
          <Pagination
            currentPage={displayedPage}
            totalItems={memberTotal}
            pageSize={pageSize}
            pageSizeOptions={MEMBERS_PAGE_SIZE_OPTIONS}
            onPageChange={setRequestedPage}
            onPageSizeChange={(size) => {
              setRequestedPage(1)
              setDisplayedPage(1)
              setPageSize(size)
            }}
            itemLabel={t('members')}
            className="mt-0 h-full min-h-0 border-0 py-0"
          />
        </div>
      ) : null}
      <InviteMembersDialog
        open={inviteOpen}
        onOpenChange={setInviteOpen}
        organizationId={organizationId}
        currentMemberCount={memberTotal}
        memberLimit={plan?.members != null ? Number(plan.members) : null}
        organizationPlan={plan}
      />
    </>
  )
}
