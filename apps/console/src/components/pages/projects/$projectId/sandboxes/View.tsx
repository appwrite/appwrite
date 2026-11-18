import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from '@tanstack/react-router'
import { ID } from '@appwrite.io/console'
import { Copy, SquareTerminal, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
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
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { Pagination } from '@/components/global/shared/Pagination'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import type { Sandbox } from '@/lib/appwrite/sandboxes'
import { canWriteSandboxes } from '@/lib/console-access-checks'
import {
  useCreateSandbox,
  useDeleteSandbox,
  useOrganizationScopes,
  useProject,
  useSandboxes,
} from '@/lib/react-query/hooks'
import { DEFAULT_PAGE_SIZE } from '@/lib/react-query/hooks/constants'
import { useT } from '@/lib/i18n/translate'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { ServiceHeader } from '../shared/ServiceHeader'
import {
  CreateSandbox,
  SANDBOX_IMAGES,
  type CreateSandboxData,
} from './CreateSandbox'
import { SandboxStatusBadge } from './SandboxStatusBadge'

const HEAD_CLASS =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

export function imageName(image: string): string {
  return SANDBOX_IMAGES.find((option) => option.id === image)?.name ?? image
}

export function View() {
  const t = useT()
  const navigate = useNavigate()
  const { projectId } = useParams({ strict: false }) as { projectId: string }

  const { project } = useProject(projectId)
  const { features } = useConsoleProfile()
  const { access } = useOrganizationScopes(project?.teamId)
  const canWrite = canWriteSandboxes(access, features)
  const writeTooltip = canWrite
    ? undefined
    : t("You don't have permission to create sandboxes.")

  // Keep the current page visible until the next one has loaded.
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const { isFetching } = useSandboxes(projectId, requestedPage - 1, pageSize)
  const { sandboxes, total } = useSandboxes(
    projectId,
    displayedPage - 1,
    pageSize,
  )
  useEffect(() => {
    if (!isFetching && requestedPage !== displayedPage) {
      setDisplayedPage(requestedPage)
    }
  }, [isFetching, requestedPage, displayedPage])

  const [createOpen, setCreateOpen] = useState(false)
  const [deleteTarget, setDeleteTarget] = useState<Sandbox | null>(null)
  const createMutation = useCreateSandbox(projectId)
  const deleteMutation = useDeleteSandbox(projectId)

  const handleCreate = async (data: CreateSandboxData) => {
    try {
      const sandbox = await createMutation.mutateAsync({
        sandboxId: data.sandboxId || ID.unique(),
        image: data.image,
        specification: data.specification,
        variables:
          Object.keys(data.variables).length > 0 ? data.variables : undefined,
      })
      setCreateOpen(false)
      toast.success(t('Sandbox created'))
      navigate({
        to: '/projects/$projectId/sandboxes/$sandboxId',
        params: { projectId, sandboxId: sandbox.$id },
      })
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  const handleDelete = async () => {
    if (!deleteTarget) return
    try {
      await deleteMutation.mutateAsync(deleteTarget.$id)
      toast.success(t('Sandbox deleted'))
      setDeleteTarget(null)
    } catch (error) {
      toast.error(getErrorMessage(error))
    }
  }

  return (
    <div className="flex flex-col">
      <ServiceHeader
        title={t('Sandboxes')}
        fullWidthBorder
        createLabel={t('Create sandbox')}
        onCreate={() => setCreateOpen(true)}
        createDisabled={!canWrite}
        createDisabledTooltip={writeTooltip}
      />

      <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
        {sandboxes.length > 0 ? (
          <>
            <div className="rounded-lg border border-border bg-card overflow-hidden">
              <Table>
                <TableHeader>
                  <TableRow className="hover:bg-transparent border-b border-border">
                    <TableHead className={HEAD_CLASS}>
                      {t('Sandbox ID')}
                    </TableHead>
                    <TableHead className={HEAD_CLASS}>{t('Image')}</TableHead>
                    <TableHead className={HEAD_CLASS}>{t('Status')}</TableHead>
                    <TableHead className={HEAD_CLASS}>{t('Created')}</TableHead>
                    <TableHead className={`${HEAD_CLASS} text-end w-[100px]`} />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sandboxes.map((sandbox) => (
                    <TableRow
                      key={sandbox.$id}
                      className="cursor-pointer transition-colors border-b border-border/50 hover:bg-muted/30"
                      onClick={(event) => {
                        const target = event.target as HTMLElement
                        if (target.closest('a, button, [role="menuitem"]')) {
                          return
                        }
                        navigate({
                          to: '/projects/$projectId/sandboxes/$sandboxId',
                          params: { projectId, sandboxId: sandbox.$id },
                        })
                      }}
                    >
                      <TableCell className="px-4 py-3">
                        <Link
                          to="/projects/$projectId/sandboxes/$sandboxId"
                          params={{ projectId, sandboxId: sandbox.$id }}
                          className="flex min-w-0 items-center gap-2"
                        >
                          <SquareTerminal className="h-4 w-4 shrink-0 text-muted-foreground" />
                          <span className="truncate font-mono text-[13px] font-medium text-foreground">
                            {sandbox.$id}
                          </span>
                        </Link>
                      </TableCell>
                      <TableCell className="px-4 py-3 text-[13px] text-muted-foreground">
                        {imageName(sandbox.image)}
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <SandboxStatusBadge status={sandbox.status} />
                      </TableCell>
                      <TableCell className="px-4 py-3">
                        <DateTooltip
                          date={sandbox.$createdAt}
                          className="text-[12px] text-muted-foreground font-mono"
                        />
                      </TableCell>
                      <TableCell className="px-4 py-3 text-end">
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem
                              onClick={() => {
                                void navigator.clipboard.writeText(sandbox.$id)
                                toast.success(t('Copied to clipboard'))
                              }}
                            >
                              <MenuItemContent icon={Copy}>
                                {t('Copy ID')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              disabled={!canWrite}
                              onClick={() => setDeleteTarget(sandbox)}
                            >
                              <MenuItemContent icon={Trash2}>
                                {t('Delete')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>
            <Pagination
              currentPage={displayedPage}
              totalItems={total}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={setRequestedPage}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setRequestedPage(1)
                setDisplayedPage(1)
              }}
              itemLabel={t('sandboxes')}
            />
          </>
        ) : (
          <EmptyState
            icon={SquareTerminal}
            variant="card"
            title={t('Run code in a sandbox')}
            description={t(
              'A sandbox is a live workspace with a terminal and a file system. Create one to run commands, start processes and manage files, from the Console or the API.',
            )}
            action={
              <Button
                size="sm"
                className="h-9 text-[13px]"
                disabled={!canWrite}
                title={writeTooltip}
                onClick={() => setCreateOpen(true)}
              >
                {t('Create sandbox')}
              </Button>
            }
          />
        )}
      </div>

      <CreateSandbox
        open={createOpen}
        onOpenChange={setCreateOpen}
        onCreate={handleCreate}
        isLoading={createMutation.isPending}
      />
      <ConfirmActionDialog
        open={deleteTarget !== null}
        onOpenChange={(open) => !open && setDeleteTarget(null)}
        title={t('Delete sandbox')}
        description={t(
          'The sandbox, its files and its processes are deleted. This cannot be undone.',
        )}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        onConfirm={handleDelete}
        isConfirming={deleteMutation.isPending}
      />
    </div>
  )
}
