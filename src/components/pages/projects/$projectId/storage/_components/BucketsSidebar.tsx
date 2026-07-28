import { useState, useEffect, useMemo } from 'react'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import {
  AlertCircle,
  ArrowUpDown,
  ChevronLeft,
  ChevronRight,
  HardDrive,
  Plus,
  Search,
} from 'lucide-react'
import {
  keepPreviousData,
  useMutation,
  useQuery,
  useQueryClient,
} from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { ID } from '@appwrite.io/console'
import { cn } from '@/lib/utils'
import { SecondarySidebarHeader } from '@/lib/layout/secondary-sidebar-header'
import {
  SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
  SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS,
  secondarySidebarNavLinkClassName,
} from '@/lib/layout/secondary-sidebar-nav'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  bucketsQueryOptions,
  Dependencies,
  useProject,
  useOrganizationPlan,
  useOrganizationScopes,
  BUCKETS_DEFAULT_SORT_BY,
  BUCKETS_DEFAULT_SORT_ORDER,
} from '@/lib/react-query/hooks'
import { canCreateBucket } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import type { Models } from '@appwrite.io/console'
import { BucketContextMenu } from './BucketContextMenu'
import { CreateBucket } from './CreateBucket'
import { S3ConnectionCard } from './S3ConnectionCard'
import { useT } from '@/lib/i18n/translate'

const SIDEBAR_PAGE_SIZE = 100

type SortBy = 'name' | '$createdAt' | '$updatedAt'

type BucketsSidebarProps = {
  showBackButton?: boolean
}

export function BucketsSidebar({ showBackButton = false }: BucketsSidebarProps) {
  const t = useT()
  const { projectId, bucketId: activeBucketId } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [sortBy, setSortBy] = useState<SortBy>('name')
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('asc')
  const [createOpen, setCreateOpen] = useState(false)

  useEffect(() => {
    const timer = window.setTimeout(
      () => setDebouncedSearch(search.trim()),
      300,
    )
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    setPage(1)
  }, [debouncedSearch, sortBy, sortOrder])

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const noCreatePermission = !canCreateBucket(access, features)

  const { data, isFetching } = useQuery({
    ...bucketsQueryOptions(
      projectId,
      page - 1,
      SIDEBAR_PAGE_SIZE,
      debouncedSearch || undefined,
      undefined,
      sortBy,
      sortOrder,
    ),
    placeholderData: keepPreviousData,
  })

  const buckets = useMemo(
    () => (data?.buckets ?? []) as Models.Bucket[],
    [data?.buckets],
  )
  const total = data?.total ?? 0
  const totalPages = Math.max(1, Math.ceil(total / SIDEBAR_PAGE_SIZE))

  const { data: totalBucketsCount = 0 } = useQuery({
    ...bucketsQueryOptions(
      projectId,
      0,
      1,
      '',
      undefined,
      BUCKETS_DEFAULT_SORT_BY,
      BUCKETS_DEFAULT_SORT_ORDER,
    ),
    select: (d) => d.total ?? 0,
  })
  const bucketsLimit = organizationPlan?.buckets ?? 0
  const isCreateDisabled =
    noCreatePermission ||
    (bucketsLimit > 0 && totalBucketsCount >= bucketsLimit)

  const createPermissionTooltip = t(
    "You don't have permission to perform this action.",
  )

  const createBucketMutation = useMutation({
    mutationFn: async (data: { bucketId?: string; name: string }) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId)
      const newId = data.bucketId || ID.unique()
      return await projectSdk.storage.createBucket({
        bucketId: newId,
        name: data.name,
      })
    },
    onSuccess: (bucket) => {
      toast.success(`${bucket.name} ${t('has been created')}`)
      void queryClient.invalidateQueries({ queryKey: Dependencies.BUCKETS })
      setCreateOpen(false)
      navigate({
        to: '/projects/$projectId/storage/$bucketId',
        params: { projectId: projectId!, bucketId: bucket.$id },
        search: () => ({}),
      })
    },
    onError: (error: unknown) => {
      toast.error(getErrorMessage(error))
    },
  })

  return (
    <div className="flex h-full min-h-0 min-w-0 flex-col">
      <SecondarySidebarHeader
        title={t('Storage')}
        showBackButton={showBackButton}
        backLabel={t('Back to project')}
        onBack={() =>
          navigate({
            to: '/projects/$projectId',
            params: { projectId: projectId! },
          })
        }
      />

      <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
        <div className="shrink-0 space-y-2 border-b border-border px-2 py-2">
          <div className="flex min-w-0 items-center gap-2">
            <div className="relative min-w-0 flex-1">
              <Search className="absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
              <Input
                type="text"
                placeholder={t('Search buckets...')}
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="h-8 ps-8 pe-2 text-[13px]"
              />
            </div>
            <DropdownMenu>
              <TooltipProvider delayDuration={0}>
                <Tooltip>
                  <TooltipTrigger asChild>
                    <DropdownMenuTrigger asChild>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="h-8 w-8 shrink-0"
                        aria-label={t('Sort buckets')}
                      >
                        <ArrowUpDown className="h-3.5 w-3.5" />
                      </Button>
                    </DropdownMenuTrigger>
                  </TooltipTrigger>
                  <TooltipContent side="top" className="text-xs">
                    {t('Sort by attribute and direction')}
                  </TooltipContent>
                </Tooltip>
              </TooltipProvider>
              <DropdownMenuContent align="end" className="w-52">
                <DropdownMenuLabel className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                  {t('Sort buckets')}
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuRadioGroup
                  value={`${sortBy}-${sortOrder}`}
                  onValueChange={(value) => {
                    const [by, dir] = value.split('-')
                    if (
                      by &&
                      (dir === 'asc' || dir === 'desc') &&
                      (by === 'name' ||
                        by === '$createdAt' ||
                        by === '$updatedAt')
                    ) {
                      setSortBy(by as SortBy)
                      setSortOrder(dir)
                      setPage(1)
                    }
                  }}
                >
                  <DropdownMenuRadioItem value="name-asc">
                    {t('Name (A → Z)')}
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="name-desc">
                    {t('Name (Z → A)')}
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$createdAt-asc">
                    {t('Created (oldest first)')}
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$createdAt-desc">
                    {t('Created (newest first)')}
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$updatedAt-asc">
                    {t('Updated (oldest first)')}
                  </DropdownMenuRadioItem>
                  <DropdownMenuRadioItem value="$updatedAt-desc">
                    {t('Updated (newest first)')}
                  </DropdownMenuRadioItem>
                </DropdownMenuRadioGroup>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>

        <div className="shrink-0 px-2 py-2">
          {isCreateDisabled && noCreatePermission ? (
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="block w-full">
                  <Button
                    variant="outline"
                    size="sm"
                    className="h-9 w-full gap-2 ps-6 pe-6 text-[13px] font-medium"
                    type="button"
                    disabled
                    {...analyticsAttrs('create-bucket')}
                  >
                    <Plus className="h-4 w-4" />
                    {t('Create bucket')}
                  </Button>
                </span>
              </TooltipTrigger>
              <TooltipContent side="right">{createPermissionTooltip}</TooltipContent>
            </Tooltip>
          ) : (
            <Button
              variant="outline"
              size="sm"
              className="h-9 w-full gap-2 ps-6 pe-6 text-[13px] font-medium"
              type="button"
              disabled={isCreateDisabled}
              onClick={() => setCreateOpen(true)}
              {...analyticsAttrs('create-bucket')}
            >
              <Plus className="h-4 w-4" />
              {t('Create bucket')}
            </Button>
          )}
        </div>

        <div className="min-h-0 flex-1 overflow-y-auto">
          {isFetching && buckets.length === 0 ? (
            <div className="p-2 text-center text-[12px] text-muted-foreground">
              {t('Loading…')}
            </div>
          ) : (
            <div className="space-y-0.5 px-2.5 py-2.5">
              {buckets.length === 0 ? (
                <div className="px-1 py-2 text-center text-[12px] text-muted-foreground">
                  {debouncedSearch
                    ? t('No buckets match your search.')
                    : t('No buckets yet.')}
                </div>
              ) : (
                buckets.map((bucket) => {
                  const isBucketSelected =
                    activeBucketId === bucket.$id
                  return (
                    <BucketContextMenu
                      key={bucket.$id}
                      projectId={projectId!}
                      bucket={{ id: bucket.$id, name: bucket.name }}
                    >
                      <Link
                        to="/projects/$projectId/storage/$bucketId"
                        params={{
                          projectId: projectId!,
                          bucketId: bucket.$id,
                        }}
                        search={() => ({})}
                        className={cn(
                          secondarySidebarNavLinkClassName(
                            isBucketSelected,
                            'transition-colors duration-150',
                          ),
                          SECONDARY_SIDEBAR_NAV_LINK_GRID_TRAILING_CLASS,
                        )}
                      >
                        <HardDrive className="h-3.5 w-3.5 shrink-0" />
                        <span className={SECONDARY_SIDEBAR_NAV_LINK_LABEL_CLASS}>
                          {bucket.name}
                        </span>
                        {bucket.enabled === false ? (
                          <AlertCircle className="h-3.5 w-3.5 shrink-0 text-amber-500" />
                        ) : null}
                      </Link>
                    </BucketContextMenu>
                  )
                })
              )}
            </div>
          )}
        </div>

        <div className="shrink-0 border-t border-border px-2 py-1.5">
          <div className="flex items-center justify-between gap-1 text-[11px] text-muted-foreground">
            <span className="shrink-0 tabular-nums">
              {total === 0
                ? `0 ${t('buckets')}`
                : `${(page - 1) * SIDEBAR_PAGE_SIZE + 1}-${Math.min(page * SIDEBAR_PAGE_SIZE, total)} ${t('of')} ${total.toLocaleString()} ${t('buckets')}`}
            </span>
            {total > SIDEBAR_PAGE_SIZE ? (
              <div className="flex items-center gap-0.5">
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6"
                  type="button"
                  onClick={() => setPage((p) => Math.max(1, p - 1))}
                  disabled={page <= 1}
                  aria-label={t('Previous page')}
                >
                  <ChevronLeft className="h-3 w-3" />
                </Button>
                <Button
                  variant="ghost"
                  size="icon"
                  className="h-6 w-6"
                  type="button"
                  onClick={() => setPage((p) => p + 1)}
                  disabled={page >= totalPages}
                  aria-label={t('Next page')}
                >
                  <ChevronRight className="h-3 w-3" />
                </Button>
              </div>
            ) : null}
          </div>
        </div>

        <div className="shrink-0 border-t border-border px-2 py-2">
          {projectId ? <S3ConnectionCard projectId={projectId} /> : null}
        </div>
      </div>

      <CreateBucket
        open={createOpen}
        onOpenChange={setCreateOpen}
        onCreate={(data) => createBucketMutation.mutate(data)}
        isLoading={createBucketMutation.isPending}
      />
    </div>
  )
}
