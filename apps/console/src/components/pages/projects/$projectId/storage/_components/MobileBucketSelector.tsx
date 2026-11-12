import { useState, useEffect, useMemo } from 'react'
import { useNavigate, useParams } from '@tanstack/react-router'
import { ChevronDown, Folder, Loader2, Plus } from 'lucide-react'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { sdk } from '@/lib/appwrite/sdk'
import { ID } from '@appwrite.io/console'
import {
  bucketsQueryOptions,
  Dependencies,
  useBucket,
  useOrganizationPlan,
  useOrganizationScopes,
  useProject,
} from '@/lib/react-query/hooks'
import { canCreateBucket } from '@/lib/console-access-checks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { Button } from '@/components/ui/button'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command'
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import type { Models } from '@appwrite.io/console'
import { CreateBucket } from './CreateBucket'
import { useT } from '@/lib/i18n/translate'

const PICK_LIMIT = 25

export function MobileBucketSelector() {
  const t = useT()
  const { projectId, bucketId: value } = useParams({ strict: false })
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [createOpen, setCreateOpen] = useState(false)

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const { plan: organizationPlan } = useOrganizationPlan(project?.teamId)
  const noCreate = !canCreateBucket(access, features)
  const bucketsLimit = organizationPlan?.buckets ?? 0

  const { data: totalCount = 0 } = useQuery({
    ...bucketsQueryOptions(projectId, 0, 1, ''),
    select: (d) => d.total ?? 0,
  })
  const createDisabled =
    noCreate || (bucketsLimit > 0 && totalCount >= bucketsLimit)

  useEffect(() => {
    const timer = window.setTimeout(
      () => setDebouncedSearch(search.trim()),
      300,
    )
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { data, isFetching } = useQuery({
    ...bucketsQueryOptions(
      projectId,
      0,
      PICK_LIMIT,
      debouncedSearch || undefined,
      undefined,
      'name',
      'asc',
    ),
    enabled: !!projectId && open,
    placeholderData: keepPreviousData,
  })

  const buckets = useMemo(
    () => (data?.buckets ?? []) as Models.Bucket[],
    [data?.buckets],
  )

  const { data: activeBucket } = useBucket(projectId, value)
  const selectedName =
    activeBucket?.name ??
    buckets.find((b) => b.$id === value)?.name ??
    (value ? t('Bucket') : t('Select bucket'))

  const createMutation = useMutation({
    mutationFn: async (form: { bucketId?: string; name: string }) => {
      if (!projectId) throw new Error('Project ID is required')
      const projectSdk = sdk.forProject(projectId)
      const newId = form.bucketId || ID.unique()
      return await projectSdk.storage.createBucket({
        bucketId: newId,
        name: form.name,
      })
    },
    onSuccess: (bucket) => {
      toast.success(`${bucket.name} ${t('has been created')}`)
      void queryClient.invalidateQueries({ queryKey: Dependencies.BUCKETS })
      setCreateOpen(false)
      setOpen(false)
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
    <TooltipProvider delayDuration={0}>
      <div className="flex min-w-0 shrink-0 items-center gap-2 border-b border-border bg-background px-3 py-2.5 lg:hidden">
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 min-w-0 flex-1 justify-between gap-2 px-3 text-[13px] font-normal"
            >
              <span className="flex min-w-0 items-center gap-2">
                <Folder className="h-4 w-4 shrink-0 text-muted-foreground" />
                <span className="truncate">{selectedName}</span>
              </span>
              {isFetching ? (
                <Loader2 className="h-4 w-4 shrink-0 animate-spin text-muted-foreground" />
              ) : (
                <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
              )}
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-[min(100vw-2rem,380px)] p-0" align="start">
            <Command shouldFilter={false}>
              <CommandInput
                placeholder={t('Search buckets...')}
                value={search}
                onValueChange={setSearch}
              />
              <CommandList className="min-h-[180px] max-h-[240px]">
                <CommandEmpty>{t('No buckets found.')}</CommandEmpty>
                <CommandGroup>
                  {buckets.map((bucket) => (
                    <CommandItem
                      key={bucket.$id}
                      value={bucket.$id}
                      onSelect={() => {
                        setOpen(false)
                        navigate({
                          to: '/projects/$projectId/storage/$bucketId',
                          params: { projectId: projectId!, bucketId: bucket.$id },
                          search: () => ({}),
                        })
                      }}
                      className="gap-2"
                    >
                      <Folder className="h-4 w-4 shrink-0 text-muted-foreground" />
                      <span className="truncate">{bucket.name}</span>
                    </CommandItem>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
        <Tooltip>
          <TooltipTrigger asChild>
            <span className="inline-flex">
              <Button
                size="icon"
                variant="outline"
                className="h-9 w-9 shrink-0"
                disabled={createDisabled}
                onClick={() => setCreateOpen(true)}
                aria-label={t('Create bucket')}
                {...analyticsAttrs('create-bucket')}
              >
                <Plus className="h-4 w-4" />
              </Button>
            </span>
          </TooltipTrigger>
          {createDisabled ? (
            <TooltipContent className="max-w-xs text-xs">
              {noCreate
                ? t("You don't have permission to create buckets.")
                : t('Bucket limit reached for your plan.')}
            </TooltipContent>
          ) : null}
        </Tooltip>
      </div>

      <CreateBucket
        open={createOpen}
        onOpenChange={setCreateOpen}
        onCreate={(data) => createMutation.mutate(data)}
        isLoading={createMutation.isPending}
      />
    </TooltipProvider>
  )
}
