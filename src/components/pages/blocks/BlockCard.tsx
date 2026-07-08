import { useMemo } from 'react'
import { Clock, Infinity as InfinityIcon, ShieldOff } from 'lucide-react'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { formatDistanceToNowStrict } from 'date-fns'
import type { Models } from '@appwrite.io/console'
import { Badge } from '@/components/ui/badge'
import { CopyableId } from '@/components/global/shared/CopyableId'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger} from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { getBlockModeMeta, getResourceTypeMeta } from './resource-type-meta'
import { BlockMode } from '@appwrite.io/console'

function formatRelative(iso?: string | null) {
  if (!iso) return null
  try {
    return formatDistanceToNowStrict(new Date(iso), { addSuffix: true })
  } catch {
    return null
  }
}

function formatUntil(iso?: string | null) {
  if (!iso) return null
  try {
    const ms = new Date(iso).getTime() - Date.now()
    if (ms <= 0) return 'expired'
    return formatDistanceToNowStrict(new Date(iso), { addSuffix: false })
  } catch {
    return null
  }
}

export function BlockCard({
  block,
  onDelete,
  deleting}: {
  block: Models.Block
  onDelete: () => void
  deleting?: boolean
}) {
  const meta = useMemo(
    () => getResourceTypeMeta(block.resourceType),
    [block.resourceType],
  )
  const Icon = meta.icon

  const isWildcard = !block.resourceId?.trim()
  const created = formatRelative(block.$createdAt)
  const until = block.expiredAt ? formatUntil(block.expiredAt) : null
  const isExpired = until === 'expired'
  const isReadonly = block.mode === BlockMode.Readonly
  const modeMeta = getBlockModeMeta(block.mode)
  const ModeIcon = modeMeta.icon

  return (
    <div
      data-deleting={deleting || undefined}
      className={cn(
        'flex items-start gap-3 px-4 py-3 transition-colors',
        'hover:bg-muted/40',
        'data-[deleting]:pointer-events-none data-[deleting]:opacity-50',
      )}
    >
      <div className="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md border border-border bg-background text-muted-foreground">
        <Icon className="h-4 w-4" />
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[13px] font-medium text-foreground">
            {meta.label}
          </span>
          {isWildcard ? (
            <Badge
              variant="secondary"
              className="h-5 px-1.5 text-[11px] font-normal"
            >
              All resources
            </Badge>
          ) : (
            <CopyableId id={block.resourceId} size="sm" maxWidth={240} />
          )}
          {isReadonly && (
            <Badge
              variant="warning"
              className="h-5 gap-1 px-1.5 text-[11px] font-normal"
              title={modeMeta.description}
            >
              <ModeIcon className="h-3 w-3" />
              {modeMeta.label}
            </Badge>
          )}
        </div>

        {block.reason && (
          <p className="mt-1 line-clamp-2 text-[12.5px] leading-relaxed text-muted-foreground">
            {block.reason}
          </p>
        )}
      </div>

      <div className="flex shrink-0 flex-col items-end gap-1 text-[11.5px] text-muted-foreground">
        {block.expiredAt ? (
          <Tooltip>
            <TooltipTrigger asChild>
              <span
                className={cn(
                  'inline-flex items-center gap-1',
                  isExpired && 'text-destructive',
                )}
              >
                <Clock className="h-3 w-3" />
                {isExpired ? 'Expired' : `Expires in ${until}`}
              </span>
            </TooltipTrigger>
            <TooltipContent side="top" className="text-[11px]">
              {new Date(block.expiredAt).toLocaleString()}
            </TooltipContent>
          </Tooltip>
        ) : (
          <span className="inline-flex items-center gap-1">
            <InfinityIcon className="h-3 w-3" />
            Permanent
          </span>
        )}
        {created && <span>Created {created}</span>}
      </div>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <RowActionsMenuTrigger aria-label="Block actions" />
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" className="w-40">
          <DropdownMenuItem
            onClick={onDelete}
            className="text-[13px]"
          >
            <MenuItemContent icon={ShieldOff}>Revoke</MenuItemContent>
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
