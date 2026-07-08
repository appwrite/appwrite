import { Fragment, useMemo, useState } from 'react'
import { toast } from 'sonner'
import {
  Filter,
  Loader2,
  Search,
  ShieldOff,
  X,
} from 'lucide-react'
import { RefreshButton } from '@/components/global/shared/RefreshButton'
import { BlockMode } from '@appwrite.io/console'
import type { Models, BlockResourceType } from '@appwrite.io/console'
import { useBlocks, useDeleteBlock } from '@/lib/react-query/hooks/manager'
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { cn } from '@/lib/utils'
import { BlockCard } from './BlockCard'
import {
  ORDERED_RESOURCE_TYPES,
  RESOURCE_TYPE_META,
} from './resource-type-meta'

export function BlocksList({ projectId }: { projectId: string | null }) {
  const { data, isLoading, isFetching, refetch, isError, error } =
    useBlocks(projectId)

  const [selectedTypes, setSelectedTypes] = useState<Set<string>>(new Set())
  const [onlyExpiring, setOnlyExpiring] = useState(false)
  const [onlyReadonly, setOnlyReadonly] = useState(false)
  const [search, setSearch] = useState('')
  const [pendingDelete, setPendingDelete] = useState<{
    resourceType: string
    resourceId: string
  } | null>(null)

  const deleteMutation = useDeleteBlock()

  const blocks: Models.Block[] = data?.blocks ?? []

  const filtered = useMemo(() => {
    const s = search.trim().toLowerCase()
    return blocks.filter((b) => {
      if (selectedTypes.size > 0 && !selectedTypes.has(b.resourceType)) {
        return false
      }
      if (onlyExpiring && !b.expiredAt) return false
      if (onlyReadonly && b.mode !== BlockMode.Readonly) return false
      if (s) {
        const hay = `${b.resourceId ?? ''} ${b.reason ?? ''}`.toLowerCase()
        if (!hay.includes(s)) return false
      }
      return true
    })
  }, [blocks, selectedTypes, onlyExpiring, onlyReadonly, search])

  const handleDelete = (b: Models.Block) => {
    if (!projectId) return
    setPendingDelete({ resourceType: b.resourceType, resourceId: b.resourceId })
    deleteMutation.mutate(
      {
        projectId,
        resourceType: b.resourceType as BlockResourceType,
        resourceId: b.resourceId,
      },
      {
        onSuccess: () => {
          toast.success('Block revoked')
          setPendingDelete(null)
        },
        onError: (e) => {
          toast.error('Could not revoke block', {
            description: e instanceof Error ? e.message : String(e),
          })
          setPendingDelete(null)
        },
      },
    )
  }

  const activeFilterCount =
    selectedTypes.size + (onlyExpiring ? 1 : 0) + (onlyReadonly ? 1 : 0)

  return (
    <section className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4 flex items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <h3 className="text-[15px] font-semibold text-foreground">
            Active blocks
          </h3>
          <span className="text-[12px] text-muted-foreground">
            {projectId ? `${filtered.length} of ${blocks.length}` : ''}
          </span>
        </div>

        <div className="flex items-center gap-1.5">
          <div className="relative">
            <Search className="pointer-events-none absolute start-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
            <input
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder="Search..."
              disabled={!projectId}
              className="h-8 w-48 rounded-md border border-border bg-background ps-7.5 pe-7 text-[12px] text-foreground placeholder:text-muted-foreground focus:outline-none focus:ring-0 focus:border-border disabled:opacity-50"
              style={{ paddingInlineStart: '1.875rem' }}
            />
            {search && (
              <button
                type="button"
                onClick={() => setSearch('')}
                aria-label="Clear search"
                className="absolute end-1 top-1/2 flex h-6 w-6 -translate-y-1/2 items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground"
              >
                <X className="h-3 w-3" />
              </button>
            )}
          </div>

          <Popover>
            <PopoverTrigger asChild>
              <button
                type="button"
                disabled={!projectId}
                className={cn(
                  'flex h-8 items-center gap-1.5 rounded-md border border-border bg-background px-2.5 text-[12px] font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:opacity-50',
                  activeFilterCount > 0 && 'text-foreground',
                )}
              >
                <Filter className="h-3.5 w-3.5" />
                Filter
                {activeFilterCount > 0 && (
                  <span className="rounded-full bg-accent px-1.5 text-[10px] font-semibold">
                    {activeFilterCount}
                  </span>
                )}
              </button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-64 p-3">
              <p className="mb-2 text-[12px] font-medium text-foreground">
                Resource type
              </p>
              <div className="flex flex-wrap gap-1">
                {ORDERED_RESOURCE_TYPES.map((rt) => {
                  const meta = RESOURCE_TYPE_META[rt]
                  const active = selectedTypes.has(rt)
                  const Icon = meta.icon
                  return (
                    <button
                      key={rt}
                      type="button"
                      onClick={() => {
                        setSelectedTypes((prev) => {
                          const next = new Set(prev)
                          if (next.has(rt)) next.delete(rt)
                          else next.add(rt)
                          return next
                        })
                      }}
                      className={cn(
                        'flex items-center gap-1 rounded-md border px-2 py-1 text-[11.5px] transition-colors',
                        active
                          ? 'border-foreground/30 bg-accent text-foreground'
                          : 'border-border bg-background text-muted-foreground hover:bg-accent hover:text-foreground',
                      )}
                    >
                      <Icon className="h-3 w-3" />
                      {meta.label}
                    </button>
                  )
                })}
              </div>
              <div className="mt-3 space-y-2 border-t border-border pt-3">
                <div className="flex items-center justify-between">
                  <label className="flex cursor-pointer items-center gap-2 text-[12px] text-foreground">
                    <input
                      type="checkbox"
                      checked={onlyExpiring}
                      onChange={(e) => setOnlyExpiring(e.target.checked)}
                      className="h-3.5 w-3.5"
                    />
                    Has expiry
                  </label>
                  {activeFilterCount > 0 && (
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedTypes(new Set())
                        setOnlyExpiring(false)
                        setOnlyReadonly(false)
                      }}
                      className="text-[12px] text-muted-foreground hover:text-foreground"
                    >
                      Clear
                    </button>
                  )}
                </div>
                <label className="flex cursor-pointer items-center gap-2 text-[12px] text-foreground">
                  <input
                    type="checkbox"
                    checked={onlyReadonly}
                    onChange={(e) => setOnlyReadonly(e.target.checked)}
                    className="h-3.5 w-3.5"
                  />
                  Read-only only
                </label>
              </div>
            </PopoverContent>
          </Popover>

          <RefreshButton
            onClick={() => refetch()}
            isRefreshing={isFetching}
            disabled={!projectId}
            className="h-8 w-8"
          />
        </div>
      </div>

      <div className="border-t border-border" />

      {!projectId ? (
        <div className="px-6 py-10">
          <EmptyState
            icon={ShieldOff}
            title="No target selected"
            description="Enter a project ID above and load to see its blocks."
            iconSize="md"
          />
        </div>
      ) : isLoading ? (
        <div className="flex items-center justify-center py-16">
          <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
        </div>
      ) : isError ? (
        <div className="px-6 py-10">
          <EmptyState
            icon={ShieldOff}
            title="Could not load blocks"
            description={error instanceof Error ? error.message : String(error)}
            iconSize="md"
          />
        </div>
      ) : filtered.length === 0 ? (
        <div className="px-6 py-10">
          <EmptyState
            icon={ShieldOff}
            title={blocks.length === 0 ? 'No active blocks' : 'No matches'}
            description={
              blocks.length === 0
                ? 'This project is currently unrestricted.'
                : 'Adjust filters to see more blocks.'
            }
            iconSize="md"
          />
        </div>
      ) : (
        <ul className="divide-y divide-border">
          {filtered.map((b) => {
            const isDeleting =
              !!pendingDelete &&
              pendingDelete.resourceType === b.resourceType &&
              pendingDelete.resourceId === b.resourceId &&
              deleteMutation.isPending
            return (
              <Fragment
                key={`${b.resourceType}-${b.resourceId || '*'}-${b.$createdAt}`}
              >
                <li>
                  <BlockCard
                    block={b}
                    deleting={isDeleting}
                    onDelete={() => handleDelete(b)}
                  />
                </li>
              </Fragment>
            )
          })}
        </ul>
      )}
    </section>
  )
}
