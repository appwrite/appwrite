import { useEffect, useState, type ReactNode } from 'react'
import { Link } from '@tanstack/react-router'
import { useIsFetching, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowLeftRight,
  Globe,
  Import,
  ShoppingCart,
  Upload,
  type LucideIcon,
} from 'lucide-react'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import {
  canBuyOrTransferOrgDomain,
  canShowOrgDomainsTab,
} from '@/lib/console-access-checks'
import { analyticsAttrs, type AnalyticsActionId } from '@/lib/analytics-actions'
import { Button } from '@/components/ui/button'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { CreateDomainDialog } from '@/components/pages/organizations/$orgId/domains/CreateDomain'
import {
  DomainContextMenu,
  DomainRowActionsMenu,
} from '@/components/pages/organizations/$orgId/domains/_components/DomainContextMenu'
import { TransferDomain } from '@/components/pages/partners/$partnerId/_components/HandoffResource'
import {
  PartnerListPaginationFooter,
  PARTNER_LIST_PAGE_SIZE,
} from '@/components/pages/partners/$partnerId/_components/PartnerListPaginationFooter'
import { PARTNER_PANE_HEADER_BUTTON_CLASSNAME } from '@/components/pages/partners/$partnerId/_components/pane-header'
import {
  PartnerListPaneHeader,
  usePartnerProjectsFilterState,
} from '@/components/pages/partners/$partnerId/_components/ProjectsPaneHeader'
import { apiNavItemClassName } from '@/lib/api-explorer/nav-styles'
import {
  organizationDomainsQueryOptions,
  useOrganizationScopes,
} from '@/lib/react-query/hooks'
import { domainsFilterColumns } from '@/lib/table-filters'
import { buildConsoleUrl, openInNewTab } from '@/lib/utils/context-menu'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export function PartnerPaneHeaderActionButton({
  disabled,
  tooltip,
  onClick,
  analyticsAction,
  variant = 'outline',
  children,
}: {
  disabled?: boolean
  tooltip?: string
  onClick?: () => void
  analyticsAction: AnalyticsActionId
  variant?: 'brandCta' | 'outline'
  children: ReactNode
}) {
  const button = (
    <Button
      size="sm"
      variant={variant}
      className={PARTNER_PANE_HEADER_BUTTON_CLASSNAME}
      disabled={disabled}
      onClick={disabled ? undefined : onClick}
      {...analyticsAttrs(analyticsAction)}
    >
      {children}
    </Button>
  )
  if (!tooltip) return button
  return (
    <TooltipProvider delayDuration={0}>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-flex">{button}</span>
        </TooltipTrigger>
        <TooltipContent className="max-w-xs text-xs">{tooltip}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

export function ClientDomainsPane({
  partnerId,
  organizationId,
  variant = 'client',
}: {
  partnerId: string
  organizationId: string
  variant?: 'workspace' | 'client'
}) {
  const t = useT()
  const queryClient = useQueryClient()
  const [createOpen, setCreateOpen] = useState(false)
  const [handoffOpen, setHandoffOpen] = useState(false)
  const [handoffDomainId, setHandoffDomainId] = useState<string | undefined>()
  const [searchValue, setSearchValue] = useState('')
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(PARTNER_LIST_PAGE_SIZE)
  const resetListToFirstPage = () => {
    setRequestedPage(1)
    setDisplayedPage(1)
  }
  const {
    filtersOpen,
    setFiltersOpen,
    filterMap,
    filterQueries,
    hasFilters,
    applyFilter,
    removeFilter,
    clearAll,
    applyQuery,
  } = usePartnerProjectsFilterState(resetListToFirstPage)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(organizationId)
  const canManageDomains = canShowOrgDomainsTab(access, features)
  const canBuyOrTransfer = canBuyOrTransferOrgDomain(access, features)
  const permissionTooltip = t("You don't have permission to manage domains.")
  const createDisabledTooltip = canManageDomains ? undefined : permissionTooltip
  const buyTransferDisabledTooltip = canBuyOrTransfer
    ? undefined
    : permissionTooltip
  const search = searchValue.trim() || undefined
  const listHasFilters = Boolean(search) || hasFilters
  const isReloading =
    useIsFetching({
      queryKey: ['domains', 'organization', organizationId],
    }) > 0
  const handoffTargetClientId =
    organizationId === partnerId ? undefined : organizationId
  const HandoffIcon: LucideIcon = variant === 'workspace' ? Upload : Import

  const { isFetching: requestedFetching } = useQuery(
    organizationDomainsQueryOptions(
      organizationId,
      requestedPage - 1,
      pageSize,
      search,
      filterQueries,
    ),
  )
  const { data: displayedData, isLoading } = useQuery(
    organizationDomainsQueryOptions(
      organizationId,
      displayedPage - 1,
      pageSize,
      search,
      filterQueries,
    ),
  )
  const domains = displayedData?.domains ?? []
  const total = displayedData?.total ?? 0

  useEffect(() => {
    if (!requestedFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [requestedFetching, requestedPage, displayedPage])

  useEffect(() => {
    const totalPages = Math.max(1, Math.ceil(total / pageSize))
    if (total > 0 && requestedPage > totalPages) {
      setRequestedPage(totalPages)
    }
  }, [total, pageSize, requestedPage])

  const handleReload = () => {
    void queryClient.refetchQueries({
      queryKey: ['domains', 'organization', organizationId],
    })
  }

  const handleSearchChange = (value: string) => {
    setSearchValue(value)
    setRequestedPage(1)
    setDisplayedPage(1)
  }

  return (
    <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
      <PartnerListPaneHeader
        title={t('Domains')}
        searchValue={searchValue}
        onSearchChange={handleSearchChange}
        searchPlaceholder={t('Search domains...')}
        columns={domainsFilterColumns}
        filterMap={filterMap}
        filtersOpen={filtersOpen}
        onFiltersOpenChange={setFiltersOpen}
        onRemoveFilter={removeFilter}
        onClearAll={clearAll}
        onApplyFilter={applyFilter}
        onApplyQuery={applyQuery}
        resourceLabel={t('domains')}
        filterScope="partners.domains"
        teamId={organizationId}
        onReload={handleReload}
        isReloading={isReloading}
        actions={
          <>
            <PartnerPaneHeaderActionButton
              disabled={!canBuyOrTransfer}
              tooltip={buyTransferDisabledTooltip}
              onClick={() =>
                openInNewTab(
                  buildConsoleUrl(
                    `/organizations/${organizationId}/domains/buy`,
                  ),
                )
              }
              analyticsAction="buy-domain"
            >
              <ShoppingCart className="h-3.5 w-3.5" />
              {t('Buy domain')}
            </PartnerPaneHeaderActionButton>
            <PartnerPaneHeaderActionButton
              disabled={!canBuyOrTransfer}
              tooltip={buyTransferDisabledTooltip}
              onClick={() =>
                openInNewTab(
                  buildConsoleUrl(
                    `/organizations/${organizationId}/domains/transfer-in`,
                  ),
                )
              }
              analyticsAction="transfer-org-domain"
            >
              <ArrowLeftRight className="h-3.5 w-3.5" />
              {t('Transfer domain')}
            </PartnerPaneHeaderActionButton>
            <PartnerPaneHeaderActionButton
              disabled={!canManageDomains}
              tooltip={createDisabledTooltip}
              onClick={() => {
                setHandoffDomainId(undefined)
                setHandoffOpen(true)
              }}
              analyticsAction="handoff-domain"
            >
              <HandoffIcon className="h-3.5 w-3.5" />
              {t('Handoff domain')}
            </PartnerPaneHeaderActionButton>
          </>
        }
        primaryAction={
          <PartnerPaneHeaderActionButton
            disabled={!canManageDomains}
            tooltip={createDisabledTooltip}
            onClick={() => setCreateOpen(true)}
            analyticsAction="add-org-domain"
            variant="brandCta"
          >
            {t('Create domain')}
          </PartnerPaneHeaderActionButton>
        }
      />
      <div className="min-h-0 flex-1 overflow-y-auto p-4">
        {isLoading && domains.length === 0 ? (
          <p className="text-[13px] text-muted-foreground">
            {t('Loading domains...')}
          </p>
        ) : domains.length === 0 ? (
          <EmptyState
            icon={Globe}
            title={listHasFilters ? undefined : t('No domains yet')}
            description={
              listHasFilters
                ? undefined
                : t(
                    'Create a domain, hand one off from development, or buy or transfer one into this organization.',
                  )
            }
            isEmpty={!listHasFilters}
            hasFilters={listHasFilters}
            variant="card"
            action={
              !listHasFilters && canManageDomains ? (
                <div className="flex items-center gap-2">
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={() => {
                      setHandoffDomainId(undefined)
                      setHandoffOpen(true)
                    }}
                  >
                    <HandoffIcon className="h-3.5 w-3.5" />
                    {t('Handoff domain')}
                  </Button>
                  <Button
                    size="sm"
                    variant="brandCta"
                    onClick={() => setCreateOpen(true)}
                  >
                    {t('Create domain')}
                  </Button>
                </div>
              ) : undefined
            }
          />
        ) : (
          <ul className="space-y-0.5">
            {domains.map((domain) => (
              <li key={domain.$id}>
                <DomainContextMenu
                  orgId={organizationId}
                  domain={domain}
                  onHandoff={
                    canManageDomains
                      ? (domainId) => {
                          setHandoffDomainId(domainId)
                          setHandoffOpen(true)
                        }
                      : undefined
                  }
                  handoffIcon={HandoffIcon}
                  openResourceInNewTab
                >
                  <div className="flex items-center gap-1">
                    <Link
                      to="/organizations/$orgId/domains/$domainId"
                      params={{ orgId: organizationId, domainId: domain.$id }}
                      target="_blank"
                      rel="noopener noreferrer"
                      className={cn(
                        apiNavItemClassName(false),
                        'flex min-w-0 flex-1 cursor-pointer items-center gap-2',
                      )}
                    >
                      <Globe className="h-3.5 w-3.5 shrink-0" />
                      <span className="truncate">
                        {domain.domain || domain.$id}
                      </span>
                    </Link>
                    <DomainRowActionsMenu />
                  </div>
                </DomainContextMenu>
              </li>
            ))}
          </ul>
        )}
      </div>
      {domains.length > 0 ? (
        <PartnerListPaginationFooter
          currentPage={displayedPage}
          totalItems={total}
          pageSize={pageSize}
          onPageChange={setRequestedPage}
          onPageSizeChange={(size) => {
            setRequestedPage(1)
            setDisplayedPage(1)
            setPageSize(size)
          }}
          itemLabel={t('domains')}
        />
      ) : null}
      <CreateDomainDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        orgId={organizationId}
        openCreatedInNewTab
      />
      <TransferDomain
        open={handoffOpen}
        onOpenChange={(open) => {
          setHandoffOpen(open)
          if (!open) setHandoffDomainId(undefined)
        }}
        partnerId={partnerId}
        targetClientId={handoffTargetClientId}
        initialResourceId={handoffDomainId}
      />
    </div>
  )
}
