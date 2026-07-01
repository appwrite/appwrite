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
} from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'

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
  className,
}: BranchSelectorProps) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')

  const hasRepository = !!(
    projectId &&
    installationId &&
    providerRepositoryId
  )

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300)
    return () => clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const { data: initialBranchesData, isLoading: initialLoading } = useQuery({
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

  useEffect(() => {
    if (sortedInitialBranches.length > 0 && !value) {
      const defaultBranch = sortedInitialBranches.find(
        (b) => b.name === 'main' || b.name === 'master',
      )
      onChange(defaultBranch?.name || sortedInitialBranches[0].name)
    }
  }, [sortedInitialBranches, value, onChange])

  const labelContent = (
    <>
      {label}
      {labelTooltip && (
        <Tooltip>
          <TooltipTrigger asChild>
            <button
              type="button"
              className="inline-flex ms-1.5 align-middle text-muted-foreground hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-ring rounded"
              aria-label="More info"
            >
              <Info className="h-3.5 w-3.5" />
            </button>
          </TooltipTrigger>
          <TooltipContent side="top" className="max-w-[240px] z-[200]">
            {labelTooltip}
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
          <Label htmlFor="branch-selector-loading" className="text-[13px] mb-2 block">
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
          <span className="truncate">Loading branches...</span>
        </div>
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
            <span className="truncate">{value || placeholder}</span>
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
                placeholder="Find a branch..."
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
            <CommandList className="max-h-[240px] overflow-y-auto overscroll-contain">
              {!isLoadingList && displayBranches.length === 0 && debouncedSearch && (
                <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
                  No branches found
                </CommandEmpty>
              )}
              {!isLoadingList && displayBranches.length === 0 && !debouncedSearch && (
                <CommandEmpty className="py-4 text-center text-[13px] text-muted-foreground">
                  No branches available
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
                    Type to search all branches
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
