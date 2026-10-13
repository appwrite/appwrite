import type { Models } from '@appwrite.io/console'
import { Copy, FileJson, Pencil, Trash2 } from 'lucide-react'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Badge } from '@/components/ui/badge'
import { Checkbox } from '@/components/ui/checkbox'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'
import { copyResourceAsJson, copyToClipboard } from '@/lib/utils/context-menu'
import {
  formatBitrate,
  formatResolution,
  getQualityTier,
} from '@/lib/utils/video-format'
import { isPresetSpec } from '@/lib/videos/profile-presets'
import { VideoTermHint } from './VideoTermHint'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold uppercase tracking-wider text-muted-foreground'

const MONO_CELL_CLASS = 'px-4 py-3 font-mono text-[12px] text-muted-foreground'

interface ProfilesListTableProps {
  profiles: Models.VideoProfile[]
  canWrite: boolean
  onUpdate: (profile: Models.VideoProfile) => void
  onDelete: (profile: Models.VideoProfile) => void
  selectedIds: Set<string>
  onToggleSelected: (profileId: string) => void
  onToggleAllSelected: (selectAll: boolean) => void
}

export function ProfilesListTable({
  profiles,
  canWrite,
  onUpdate,
  onDelete,
  selectedIds,
  onToggleSelected,
  onToggleAllSelected,
}: ProfilesListTableProps) {
  const t = useT()
  const selectedCount = profiles.filter((profile) =>
    selectedIds.has(profile.$id),
  ).length
  const allSelected = profiles.length > 0 && selectedCount === profiles.length

  return (
    <div className="overflow-hidden rounded-lg border border-border bg-card">
      <Table>
        <TableHeader>
          <TableRow className="border-b border-border hover:bg-transparent">
            {canWrite ? (
              <TableHead className="w-[40px] px-4">
                <Checkbox
                  checked={
                    allSelected
                      ? true
                      : selectedCount > 0
                        ? 'indeterminate'
                        : false
                  }
                  onCheckedChange={(checked) =>
                    onToggleAllSelected(checked === true)
                  }
                  aria-label={t('Select all profiles')}
                />
              </TableHead>
            ) : null}
            <TableHead className={HEAD_CLASS}>{t('Name')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Resolution')}</TableHead>
            <TableHead className={HEAD_CLASS}>
              <span className="inline-flex items-center gap-1">
                {t('Video bitrate')}
                <VideoTermHint term="bitrate" />
              </span>
            </TableHead>
            <TableHead className={HEAD_CLASS}>{t('Audio bitrate')}</TableHead>
            <TableHead className={HEAD_CLASS}>{t('Created')}</TableHead>
            <TableHead className={cn(HEAD_CLASS, 'w-[60px] text-end')} />
          </TableRow>
        </TableHeader>
        <TableBody>
          {profiles.map((profile) => {
            const isSelected = selectedIds.has(profile.$id)
            return (
              <TableRow
                key={profile.$id}
                className={cn(
                  'border-b border-border/50 transition-colors',
                  canWrite && 'cursor-pointer',
                  isSelected ? 'bg-muted' : 'hover:bg-muted/30',
                )}
                onClick={(event) => {
                  if (!canWrite) return
                  const target = event.target as HTMLElement
                  if (
                    target.closest('button') ||
                    target.closest('[role="checkbox"]') ||
                    target.closest('[role="menu"]')
                  ) {
                    return
                  }
                  onUpdate(profile)
                }}
              >
                {canWrite ? (
                  <TableCell
                    className="px-4 py-3"
                    onClick={(event) => event.stopPropagation()}
                  >
                    <Checkbox
                      checked={isSelected}
                      onCheckedChange={() => onToggleSelected(profile.$id)}
                      aria-label={`${t('Select profile')} ${profile.name}`}
                    />
                  </TableCell>
                ) : null}
                <TableCell className="px-4 py-3">
                  <div className="flex min-w-0 items-center gap-2">
                    <span className="truncate text-[13px] font-medium text-foreground">
                      {profile.name}
                    </span>
                    <Badge variant="outline" className="shrink-0 text-[10px]">
                      {getQualityTier(profile.width, profile.height)}
                    </Badge>
                    {isPresetSpec(profile) ? null : (
                      <Badge variant="info" className="shrink-0 text-[10px]">
                        {t('Custom')}
                      </Badge>
                    )}
                  </div>
                  <div className="mt-0.5">
                    <CopyableId id={profile.$id} size="xs" />
                  </div>
                </TableCell>
                <TableCell className={MONO_CELL_CLASS}>
                  {formatResolution(profile.width, profile.height)}
                </TableCell>
                <TableCell className={MONO_CELL_CLASS}>
                  {formatBitrate(profile.videoBitRate, 'kbps')}
                </TableCell>
                <TableCell className={MONO_CELL_CLASS}>
                  {formatBitrate(profile.audioBitRate, 'kbps')}
                </TableCell>
                <TableCell className="px-4 py-3">
                  <DateTooltip
                    date={profile.$createdAt}
                    className="whitespace-nowrap text-[12px] text-muted-foreground"
                  />
                </TableCell>
                <TableCell className="px-4 py-3 text-end">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <RowActionsMenuTrigger
                        aria-label={`${t('Actions for')} ${profile.name}`}
                      />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-56">
                      <DropdownMenuItem
                        disabled={!canWrite}
                        onClick={() => onUpdate(profile)}
                      >
                        <MenuItemContent icon={Pencil}>
                          {t('Update')}
                        </MenuItemContent>
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuSub>
                        <DropdownMenuSubTrigger>
                          <MenuItemIcon icon={Copy} />
                          {t('Copy')}
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent className="w-44">
                          <DropdownMenuItem
                            onClick={() => copyToClipboard(t('ID'), profile.$id)}
                          >
                            <MenuItemContent icon={Copy}>
                              {t('Copy ID')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            onClick={() =>
                              copyToClipboard(t('Name'), profile.name)
                            }
                          >
                            <MenuItemContent icon={Copy}>
                              {t('Copy name')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            onClick={() => copyResourceAsJson(() => profile)}
                          >
                            <MenuItemContent icon={FileJson}>
                              {t('Copy as JSON')}
                            </MenuItemContent>
                          </DropdownMenuItem>
                        </DropdownMenuSubContent>
                      </DropdownMenuSub>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem
                        disabled={!canWrite}
                        onClick={() => onDelete(profile)}
                      >
                        <MenuItemContent icon={Trash2}>
                          {t('Delete')}
                        </MenuItemContent>
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </TableCell>
              </TableRow>
            )
          })}
        </TableBody>
      </Table>
    </div>
  )
}
