import { useEffect, useMemo, useRef, useState } from 'react'
import { Check, ChevronDown, Folder, Loader2 } from 'lucide-react'
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
import { Skeleton } from '@/components/ui/skeleton'
import { InitialsAvatar } from '@/components/global/shared/Avatar'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { useQuery } from '@tanstack/react-query'
import {
  formatProjectNameForDisplay,
  useProject,
  useProjectsForTeamInfinite,
  organizationProjectScopeQueryOptions,
} from '@/lib/react-query/hooks'

const PROJECT_PICKER_PAGE_SIZE = 25

type AgentProjectPickerProps = {
  /** Organization to list projects from (required for org-scoped agent). */
  organizationId: string | null | undefined
  value: string
  onChange: (projectId: string) => void
  disabled?: boolean
  className?: string
  /** `compact` for the composer footer; `form` matches standard form controls. */
  size?: 'compact' | 'form'
}

export function AgentProjectPicker({
  organizationId,
  value,
  onChange,
  disabled = false,
  className,
  size = 'compact',
}: AgentProjectPickerProps) {
  const t = useT()
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const listScrollRef = useRef<HTMLDivElement>(null)
  const sentinelRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const timer = window.setTimeout(() => setDebouncedSearch(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    if (!open) setSearch('')
  }, [open])

  const listOrgId = !disabled && open ? organizationId : null
  const { data: pickerProjectScopeData } = useQuery(
    organizationProjectScopeQueryOptions(listOrgId),
  )
  const pickerProjectScope = pickerProjectScopeData ?? null

  const {
    projects,
    isFetching,
    isFetchingNextPage,
    hasNextPage,
    fetchNextPage,
  } = useProjectsForTeamInfinite(
    listOrgId,
    PROJECT_PICKER_PAGE_SIZE,
    open ? debouncedSearch || undefined : undefined,
    undefined,
    pickerProjectScope,
  )

  const { project: selectedProject } = useProject(value || undefined)

  const isFormSize = size === 'form'
  const iconClassName = isFormSize ? 'h-4 w-4' : 'h-3.5 w-3.5'
  const itemClassName = isFormSize
    ? 'px-2.5 py-2 text-[13px]'
    : 'px-2 py-1.5 text-[12px]'

  const selectedLabel = useMemo(() => {
    if (!value) return t('Select project')
    if (selectedProject?.name) {
      return formatProjectNameForDisplay(selectedProject.name)
    }
    const fromList = projects.find((project) => project.$id === value)
    if (fromList?.name) return formatProjectNameForDisplay(fromList.name)
    return t('Project')
  }, [projects, selectedProject?.name, t, value])

  const showListSkeleton = isFetching && projects.length === 0

  useEffect(() => {
    const sentinel = sentinelRef.current
    const root = listScrollRef.current
    if (
      !sentinel ||
      !root ||
      !open ||
      !hasNextPage ||
      isFetchingNextPage ||
      !fetchNextPage
    ) {
      return
    }

    const observer = new IntersectionObserver(
      (entries) => {
        const [entry] = entries
        if (entry.isIntersecting && hasNextPage && !isFetchingNextPage) {
          void fetchNextPage()
        }
      },
      { root, rootMargin: '120px', threshold: 0.1 },
    )

    observer.observe(sentinel)
    return () => observer.disconnect()
  }, [fetchNextPage, hasNextPage, isFetchingNextPage, open, projects.length])

  const selectProject = (projectId: string) => {
    onChange(projectId)
    setOpen(false)
  }

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button
          type="button"
          variant={isFormSize ? 'outline' : 'ghost'}
          size="sm"
          disabled={disabled || !organizationId}
          className={cn(
            isFormSize
              ? 'h-9 w-full justify-start gap-1.5 px-3 text-[13px] font-normal text-foreground'
              : 'h-7 max-w-[180px] gap-1 px-2 text-[11px] font-medium text-muted-foreground hover:text-foreground',
            className,
          )}
          aria-label={t('Project')}
          aria-expanded={open}
          role="combobox"
          {...analyticsAttrs('agent-project-picker')}
        >
          {value && selectedProject?.name ? (
            <InitialsAvatar
              name={selectedProject.name}
              size="xs"
              className={cn(
                'shrink-0 text-[8px]',
                isFormSize ? 'h-4 w-4' : 'h-3.5 w-3.5',
              )}
            />
          ) : (
            <Folder
              className={cn('shrink-0 text-muted-foreground', iconClassName)}
              aria-hidden
            />
          )}
          <span className="min-w-0 flex-1 truncate text-start">
            {selectedLabel}
          </span>
          <ChevronDown
            className={cn(
              'shrink-0 opacity-70',
              isFormSize ? 'h-4 w-4' : 'h-3 w-3',
            )}
          />
        </Button>
      </PopoverTrigger>
      <PopoverContent
        align="start"
        className={cn(
          'p-0',
          isFormSize
            ? 'w-[var(--radix-popover-trigger-width)] min-w-[280px]'
            : 'w-[260px]',
        )}
        onWheelCapture={(event) => {
          event.stopPropagation()
        }}
      >
        <Command shouldFilter={false} className="overflow-hidden">
          <div className="relative">
            <CommandInput
              placeholder={t('Search projects...')}
              value={search}
              onValueChange={setSearch}
              className={cn(
                isFormSize ? 'h-9 text-[13px]' : 'h-8 text-[12px]',
                isFetching && 'pe-8',
              )}
            />
            <div
              className={cn(
                'pointer-events-none absolute end-3 top-1/2 -translate-y-1/2 transition-opacity duration-200',
                isFetching ? 'opacity-100' : 'opacity-0',
              )}
              aria-hidden
            >
              <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
            </div>
          </div>
          <CommandList
            ref={listScrollRef}
            className="min-h-[180px] max-h-[240px] overflow-y-auto overscroll-contain"
          >
            {showListSkeleton ? (
              <div className="space-y-0.5 p-1" aria-hidden>
                {Array.from({ length: 5 }, (_, index) => (
                  <div
                    key={index}
                    className="flex items-center gap-2 rounded-sm px-2 py-1.5"
                  >
                    <Skeleton className="h-4 w-4 shrink-0 rounded-sm" />
                    <Skeleton
                      className="h-4 rounded-sm"
                      style={{ width: `${55 + (index % 3) * 12}%` }}
                    />
                  </div>
                ))}
              </div>
            ) : (
              <>
                <CommandEmpty>{t('No projects found')}</CommandEmpty>
                <CommandGroup>
                  {projects.map((project) => {
                    const selected = value === project.$id
                    const label = formatProjectNameForDisplay(project.name)
                    const paused = Boolean(project.paused)
                    return (
                      <CommandItem
                        key={project.$id}
                        value={`${project.$id} ${label}`}
                        onSelect={() => selectProject(project.$id)}
                        className={cn(
                          'gap-2',
                          itemClassName,
                          selected && 'bg-accent/50',
                        )}
                      >
                        <InitialsAvatar
                          name={project.name}
                          size="xs"
                          className={cn(
                            'shrink-0 text-[8px]',
                            isFormSize ? 'h-4 w-4' : 'h-3.5 w-3.5',
                          )}
                        />
                        <span className="min-w-0 flex-1 truncate">
                          {paused ? `${label} ${t('(Paused)')}` : label}
                        </span>
                        {selected ? (
                          <Check className="h-3.5 w-3.5 shrink-0 text-foreground" />
                        ) : null}
                      </CommandItem>
                    )
                  })}

                  {hasNextPage ? (
                    <div
                      ref={sentinelRef}
                      className="h-px w-full shrink-0"
                      aria-hidden
                    />
                  ) : null}
                  {isFetchingNextPage ? (
                    <div className="flex items-center justify-center py-2">
                      <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                    </div>
                  ) : null}
                </CommandGroup>
              </>
            )}
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
