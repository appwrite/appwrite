import { Copy, ExternalLink, Lock } from 'lucide-react'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
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
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import type { ReviewRepository } from '@/lib/appwrite/reviews'
import { useT } from '@/lib/i18n/translate'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { VcsIcon } from '@/lib/vcs/providers'
import { ReviewToggle } from './ReviewToggle'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

/** One row of the table: a GitHub repository and, when reviewed, its row. */
export type RepositoryRowData = {
  installationId: string
  providerRepositoryId: string
  /** `owner/name`, or the GitHub ID while the name is loading. */
  name: string
  private?: boolean
  /** Shown under the name when the project has several installations. */
  organization?: string
  repository: ReviewRepository | undefined
}

type RepositoriesTableProps = {
  projectId: string
  rows: RepositoryRowData[]
  canWrite: boolean
}

/**
 * Repositories with a switch that turns code review on or off. Both the list of
 * reviewed repositories and the list of an installation's repositories use it.
 */
export function RepositoriesTable({
  projectId,
  rows,
  canWrite,
}: RepositoriesTableProps) {
  const t = useT()

  return (
    <div className="rounded-lg border border-border bg-card overflow-hidden">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent border-b border-border">
            <TableHead className={HEAD_CLASS}>{t('Repository')}</TableHead>
            <TableHead className={`${HEAD_CLASS} text-end`}>
              {t('Reviewed since')}
            </TableHead>
            <TableHead className={`${HEAD_CLASS} text-end w-[72px]`}>
              {t('Review')}
            </TableHead>
            <TableHead className={`${HEAD_CLASS} w-[56px]`} />
          </TableRow>
        </TableHeader>
        <TableBody>
          {rows.map((row) => (
            <RepositoryRow
              key={row.providerRepositoryId}
              projectId={projectId}
              row={row}
              canWrite={canWrite}
            />
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

function RepositoryRow({
  projectId,
  row,
  canWrite,
}: {
  projectId: string
  row: RepositoryRowData
  canWrite: boolean
}) {
  const t = useT()
  const resolved = row.name !== row.providerRepositoryId
  const url = resolved ? `https://github.com/${row.name}` : undefined

  return (
    <TableRow className="border-b border-border/50">
      <TableCell className="px-4 py-3">
        <div className="flex min-w-0 items-center gap-2">
          <VcsIcon
            type="github"
            className="h-4 w-4 shrink-0 text-muted-foreground"
          />
          <div className="min-w-0">
            <div className="flex min-w-0 items-center gap-1.5">
              <span className="truncate text-[13px] font-medium text-foreground">
                {row.name}
              </span>
              {row.private && (
                <Lock
                  className="h-3.5 w-3.5 shrink-0 text-muted-foreground"
                  aria-label={t('Private')}
                />
              )}
            </div>
            {row.organization && (
              <div className="truncate text-[12px] text-muted-foreground">
                {row.organization}
              </div>
            )}
          </div>
        </div>
      </TableCell>
      <TableCell className="px-4 py-3 text-end">
        {row.repository ? (
          <DateTooltip
            date={row.repository.$createdAt}
            className="text-[12px] text-muted-foreground font-mono"
          />
        ) : (
          <span className="text-[12px] text-muted-foreground">-</span>
        )}
      </TableCell>
      <TableCell className="px-4 py-3 text-end">
        <ReviewToggle
          projectId={projectId}
          installationId={row.installationId}
          providerRepositoryId={row.providerRepositoryId}
          repository={row.repository}
          name={row.name}
          disabled={!canWrite}
        />
      </TableCell>
      <TableCell className="px-4 py-3 text-end">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <RowActionsMenuTrigger
              aria-label={`${t('Actions for')} ${row.name}`}
            />
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            {row.repository && (
              <DropdownMenuItem
                onClick={() => void copyToClipboard('ID', row.repository!.$id)}
              >
                <MenuItemContent icon={Copy}>{t('Copy ID')}</MenuItemContent>
              </DropdownMenuItem>
            )}
            {url && (
              <DropdownMenuItem asChild>
                <a href={url} target="_blank" rel="noreferrer">
                  <MenuItemContent icon={ExternalLink}>
                    {t('Open on GitHub')}
                  </MenuItemContent>
                </a>
              </DropdownMenuItem>
            )}
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </TableRow>
  )
}
