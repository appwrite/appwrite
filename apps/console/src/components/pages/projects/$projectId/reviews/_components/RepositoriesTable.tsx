import { useMemo, useState } from 'react'
import { Copy, ExternalLink, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
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
import {
  useDeleteReviewRepository,
  useRepository,
  useVcsInstallations,
} from '@/lib/react-query/hooks'
import type { ReviewRepository } from '@/lib/appwrite/reviews'
import { useT } from '@/lib/i18n/translate'
import { copyToClipboard } from '@/lib/utils/context-menu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { VcsIcon } from '@/lib/vcs/providers'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

type RepositoriesTableProps = {
  projectId: string
  repositories: ReviewRepository[]
  canWrite: boolean
}

export function RepositoriesTable({
  projectId,
  repositories,
  canWrite,
}: RepositoriesTableProps) {
  const t = useT()
  const { data: installationsData } = useVcsInstallations(projectId, 0, 100)
  const organizations = useMemo(
    () =>
      new Map(
        (installationsData?.installations ?? []).map((installation) => [
          installation.$id,
          installation.organization,
        ]),
      ),
    [installationsData],
  )

  const [removing, setRemoving] = useState<{
    repository: ReviewRepository
    name: string
  } | null>(null)
  const deleteMutation = useDeleteReviewRepository(projectId)

  const confirmRemove = () => {
    if (!removing) return
    deleteMutation.mutate(removing.repository.$id, {
      onSuccess: () => {
        toast.success(t('Code review turned off'), {
          description: removing.name,
        })
        setRemoving(null)
      },
      onError: (error) => {
        toast.error(getErrorMessage(error, t('Failed to turn off code review')))
      },
    })
  }

  return (
    <>
      <div className="rounded-lg border border-border bg-card overflow-hidden">
        <Table>
          <TableHeader>
            <TableRow className="hover:bg-transparent border-b border-border">
              <TableHead className={HEAD_CLASS}>{t('Repository')}</TableHead>
              <TableHead className={HEAD_CLASS}>{t('Installation')}</TableHead>
              <TableHead className={`${HEAD_CLASS} text-end`}>
                {t('Added')}
              </TableHead>
              <TableHead className={`${HEAD_CLASS} text-end w-[100px]`} />
            </TableRow>
          </TableHeader>
          <TableBody>
            {repositories.map((repository) => (
              <RepositoryRow
                key={repository.$id}
                projectId={projectId}
                repository={repository}
                organization={organizations.get(repository.installationId)}
                canWrite={canWrite}
                onRemove={(name) => setRemoving({ repository, name })}
              />
            ))}
          </TableBody>
        </Table>
      </div>

      <Dialog
        open={removing !== null}
        onOpenChange={(open) => !open && setRemoving(null)}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Remove repository')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              <span className="font-medium text-foreground">
                {removing?.name}
              </span>
              <br />
              {t(
                'Pull requests in this repository will no longer be reviewed. Past reviews stay in the project.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setRemoving(null)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={confirmRemove}
              disabled={deleteMutation.isPending}
            >
              {t('Remove')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}

function RepositoryRow({
  projectId,
  repository,
  organization,
  canWrite,
  onRemove,
}: {
  projectId: string
  repository: ReviewRepository
  organization: string | undefined
  canWrite: boolean
  onRemove: (name: string) => void
}) {
  const t = useT()
  // The row stores GitHub's repository ID only; the name comes from GitHub.
  const { data: providerRepository } = useRepository(
    projectId,
    repository.installationId,
    repository.providerRepositoryId,
  )
  const name = providerRepository
    ? `${providerRepository.organization}/${providerRepository.name}`
    : repository.providerRepositoryId
  const url = providerRepository
    ? `https://github.com/${providerRepository.organization}/${providerRepository.name}`
    : undefined

  return (
    <TableRow className="border-b border-border/50">
      <TableCell className="px-4 py-3">
        <div className="flex items-center gap-2">
          <VcsIcon type="github" className="h-4 w-4 text-muted-foreground" />
          <span className="text-[13px] font-medium text-foreground">
            {name}
          </span>
        </div>
      </TableCell>
      <TableCell className="px-4 py-3">
        <span className="text-[13px] text-muted-foreground">
          {organization ?? '-'}
        </span>
      </TableCell>
      <TableCell className="px-4 py-3 text-end">
        <DateTooltip
          date={repository.$createdAt}
          className="text-[12px] text-muted-foreground font-mono"
        />
      </TableCell>
      <TableCell className="px-4 py-3 text-end">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <RowActionsMenuTrigger aria-label={`${t('Actions for')} ${name}`} />
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-56">
            <DropdownMenuItem
              onClick={() => void copyToClipboard('ID', repository.$id)}
            >
              <MenuItemContent icon={Copy}>{t('Copy ID')}</MenuItemContent>
            </DropdownMenuItem>
            {url && (
              <DropdownMenuItem asChild>
                <a href={url} target="_blank" rel="noreferrer">
                  <MenuItemContent icon={ExternalLink}>
                    {t('Open on GitHub')}
                  </MenuItemContent>
                </a>
              </DropdownMenuItem>
            )}
            <DropdownMenuSeparator />
            <DropdownMenuItem
              disabled={!canWrite}
              onClick={() => onRemove(name)}
            >
              <MenuItemContent icon={Trash2}>{t('Remove')}</MenuItemContent>
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </TableRow>
  )
}
