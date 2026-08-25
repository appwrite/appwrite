/**
 * Branch Selector Component
 *
 * Searchable dropdown for selecting a Git branch from a repository.
 * Loads the first page of branches initially and supports server-side search
 * when the user types (same behavior as the legacy console BranchSelector).
 */

import { useEffect, useMemo, useState } from 'react'
import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
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
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip'
import { ChevronDown, GitBranch, Info, Loader2 } from 'lucide-react'
import {
  repositoryBranchesQueryOptions,
  sortRepositoryBranches,
  useRepository,
} from '@/lib/react-query/hooks'
import { getVcsInstallationErrorKind } from '@/lib/utils/error-formatting'
import { VcsInstallationErrorAlert } from '@/components/global/shared/VcsInstallationError'
import { useVcsInstallationReconnect } from '@/lib/vcs/use-installation-reconnect'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

interface BranchSelectorProps {
  projectId: string | undefined
  installationId: string | null | undefined
  providerRepositoryId: string | null | undefined
  value: string
  onChange: (branch: string) => void
  label?: string
  /** Optional tooltip text shown next to the label (e.g. for sites: production branch explanation) */
  labelTooltip?: string
  placeholder?: string
  disabled?: boolean
  /**
   * Set by a parent that already explains a broken installation, so the same
   * failure is not reported twice in one card. The selector still falls back
   * to free-text entry, just without repeating the alert.
   */
  suppressInstallationError?: boolean
  className?: string
}

export function BranchSelector({
  projectId,
  installationId,
  providerRepositoryId,
  value,
  onChange,
  label = 'Branch',
  labelTooltip,
  placeholder = 'Select branch',
  disabled = false,
  suppressInstallationError = false,
  className,
}: BranchSelectorProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  const hasRepository = !!(projectId && installationId && providerRepositoryId)

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { data: repository, isPending: repositoryPending } = useRepository(
    projectId,
    installationId,
    providerRepositoryId,
  )

  const {
    data: initialBranchesData,
    isLoading: initialLoading,
    isFetching: initialFetching,
    error: initialBranchesError,
    refetch: refetchBranches,
  } = useQuery({
    ...repositoryBranchesQueryOptions(
      projectId,
      installationId,
      providerRepositoryId,
    ),
    enabled: hasRepository,
  })

  const { data: searchBranchesData, isFetching: searchFetching } = useQuery({
    ...repositoryBranchesQueryOptions(
      projectId,
      installationId,
      providerRepositoryId,
      debouncedSearch,
    ),
    enabled: hasRepository && open && !!debouncedSearch,
    placeholderData: keepPreviousData,
  })

  const sortedInitialBranches = useMemo(
    () => sortRepositoryBranches(initialBranchesData?.branches ?? []),
    [initialBranchesData?.branches],
  )

  const sortedSearchBranches = useMemo(
    () => sortRepositoryBranches(searchBranchesData?.branches ?? []),
    [searchBranchesData?.branches],
  )

  const displayBranches = debouncedSearch
    ? sortedSearchBranches
    : sortedInitialBranches

  const isSearching = !!debouncedSearch && searchFetching
  const isLoadingList = debouncedSearch ? isSearching : initialLoading

  // Without this the component falls back to a plain text input, which looks
  // like "this repository has no branches" rather than "we could not ask".
  const installationErrorKind =
    getVcsInstallationErrorKind(initialBranchesError)
  const { provider, organization, reconnectUrl } = useVcsInstallationReconnect(
    projectId,
    installationId,
  )

  // Same resolution as the old console's productionBranchFieldset, which waits
  // on the repository lookup before falling back to 'main'.
  const defaultBranch = repository?.defaultBranch

  useEffect(() => {
    if (value) return
    if (hasRepository && repositoryPending) return

    onChange(defaultBranch ?? 'main')
  }, [defaultBranch, hasRepository, repositoryPending, value, onChange])

  const labelContent = (
    <>
      {t(label)}
      {labelTooltip && (
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              className="inline-flex ms-1.5 align-middle text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
              aria-label={t('More info')}
            >
              <Info className="h-3.5 w-3.5" />
            </button>
          </TooltipTrigger>
          <TooltipContent side="top" className="max-w-[240px] z-[200]">
            {t(labelTooltip)}
          </TooltipContent>
        </Tooltip>
      )}
    </>
  )

  if (!hasRepository) {
    return (
      <div className={className}>
        {label && (
          <Label htmlFor="branch-input" className="text-[13px] mb-2 block">
            {labelContent}
          </Label>
        )}
        <Input
          id="branch-input"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder="main"
          disabled={disabled}
          className="h-9 font-mono text-[13px]"
        />
      </div>
    )
  }

  if (initialLoading && sortedInitialBranches.length === 0) {
    return (
      <div className={className}>
        {label && (
          <Label
            htmlFor="branch-selector-loading"
            className="text-[13px] mb-2 block"
          >
            {labelContent}
          </Label>
        )}
        <div
          id="branch-selector-loading"
          className="flex h-9 w-full min-w-0 shrink-0 items-center gap-2 rounded-md border border-input bg-transparent px-3 text-[13px] text-muted-foreground dark:bg-input/30"
          aria-busy
          aria-live="polite"
        >
          <Loader2
            className="h-4 w-4 shrink-0 animate-spin text-muted-foreground"
            aria-hidden
          />
          <span className="truncate">{t('Loading branches...')}</span>
        </div>
      </div>
    )
  }

  // Keep the free-text input so an in-flight form is still submittable, but say
  // why the branch list is missing instead of leaving it unexplained.
  if (installationErrorKind && !suppressInstallationError) {
    return (
      <div className={className}>
        {label && (
          <Label htmlFor="branch-input" className="text-[13px] mb-2 block">
            {labelContent}
          </Label>
        )}
        <Input
          id="branch-input"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder="main"
          disabled={disabled}
          className="h-9 font-mono text-[13px]"
        />
        <VcsInstallationErrorAlert
          kind={installationErrorKind}
          provider={provider}
          organization={organization}
          reconnectUrl={reconnectUrl}
          onRetry={() => refetchBranches()}
          isRetrying={initialFetching}
          className="mt-2"
        >
          {t(
            'Branches could not be loaded, so enter the branch name manually.',
          )}
        </VcsInstallationErrorAlert>
      </div>
    )
  }

  if (sortedInitialBranches.length === 0) {
    return (
      <div className={className}>
        {label && (
          <Label htmlFor="branch-input" className="text-[13px] mb-2 block">
            {labelContent}
          </Label>
        )}
        <Input
          id="branch-input"
          value={value}
          onChange={(e) => onChange(e.target.value)}
          placeholder="main"
          disabled={disabled}
          className="h-9 font-mono text-[13px]"
        />
      </div>
    )
  }

  return (
    <div className={className}>
      {label && (
        <Label htmlFor="branch-selector" className="text-[13px] mb-2 block">
          {labelContent}
        </Label>
      )}
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            id="branch-selector"
            type="button"
            variant="outline"
            role="combobox"
            aria-expanded={open}
            disabled={disabled}
            className={cn(
              'h-9 w-full min-w-0 justify-between gap-2 text-[13px] font-normal',
              !value && 'text-muted-foreground',
            )}
          >
            <span className="truncate">{value || t(placeholder)}</span>
            <ChevronDown className="h-4 w-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent
          className="max-h-[min(320px,var(--radix-popover-content-available-height))] w-[var(--radix-popover-trigger-width)] overflow-hidden p-0"
          align="start"
          onWheelCapture={(event) => {
            event.stopPropagation()
          }}
        >
          <Command shouldFilter={false}>
            <div className="relative">
              <CommandInput
                placeholder={t('Find a branch...')}
                value={search}
                onValueChange={setSearch}
                className={cn('h-9 text-[13px]', isLoadingList && 'pe-8')}
              />
              <div
                className={cn(
                  'pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 transition-opacity duration-200',
                  isLoadingList ? 'opacity-100' : 'opacity-0',
                )}
                aria-hidden
              >
                <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
              </div>
            </div>
            <CommandList className="min-h-[180px] max-h-[240px] overflow-y-auto overscroll-contain">
              {!isLoadingList &&
                displayBranches.length === 0 &&
                debouncedSearch && (
                  <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
                    {t('No branches found')}
                  </CommandEmpty>
                )}
              {!isLoadingList &&
                displayBranches.length === 0 &&
                !debouncedSearch && (
                  <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
                    {t('No branches available')}
                  </CommandEmpty>
                )}
              <CommandGroup>
                {displayBranches.map((branch) => (
                  <CommandItem
                    key={branch.name}
                    value={branch.name}
                    className={cn(
                      'text-[13px]',
                      branch.name === value && 'font-medium',
                    )}
                    onSelect={() => {
                      onChange(branch.name)
                      setOpen(false)
                    }}
                  >
                    <div className="flex min-w-0 items-center gap-2">
                      <GitBranch className="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                      <span className="truncate">{branch.name}</span>
                    </div>
                  </CommandItem>
                ))}
                {!debouncedSearch && displayBranches.length > 0 && (
                  <div className="border-t border-border px-3 py-2 text-[11px] text-muted-foreground">
                    {t('Type to search all branches')}
                  </div>
                )}
              </CommandGroup>
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
    </div>
  )
}
