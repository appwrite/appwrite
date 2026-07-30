import { useState, useMemo, useEffect } from 'react'
import {
  fetchProjectWebhook,
  useDeleteWebhook,
  useProjectWebhooks} from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow} from '@/components/ui/table'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger} from '@/components/ui/dropdown-menu'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle} from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Loader2,
  Pencil,
  Square,
  Trash2,
  Webhook as WebhookIcon} from 'lucide-react'
import { toast } from 'sonner'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import {
  MenuItemContent,
  MenuItemIcon,
} from '@/components/global/shared/ContextMenuIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { WebhookDrawer } from './webhooks/WebhookDrawer'
import { WebhookContextMenu } from './webhooks/WebhookContextMenu'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildConsoleUrl,
  copyResourceAsJson,
  copyToClipboard,
  openInNewTab,
  openInNewWindow} from '@/lib/utils/context-menu'
import {
  openDialogAfterOverlayCloses,
  closeDialogBeforeOverlayUnmount,
} from '@/lib/utils/overlay-lock'
import { useT } from '@/lib/i18n/translate'
import type { Models } from '@appwrite.io/console'

interface WebhooksProps {
  projectId: string
  searchValue?: string
}

export function Webhooks({
  projectId,
  searchValue: searchValueProp = ''}: WebhooksProps) {
  const t = useT()
  const [currentPage, setCurrentPage] = useState(1)
  const [pageSize, setPageSize] = useState(25)
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [selectedWebhook, setSelectedWebhook] = useState<Models.Webhook | null>(
    null,
  )
  const [webhookToDelete, setWebhookToDelete] = useState<Models.Webhook | null>(
    null,
  )

  const { webhooks, isLoading } = useProjectWebhooks(projectId)
  const deleteMutation = useDeleteWebhook(projectId)

  // Listen for create event from ServiceHeader
  useEffect(() => {
    const handleCreate = () => {
      openDialogAfterOverlayCloses(() => {
        setSelectedWebhook(null)
        setDrawerOpen(true)
      })
    }
    window.addEventListener('settings-create-webhook', handleCreate)
    return () => {
      window.removeEventListener('settings-create-webhook', handleCreate)
    }
  }, [])

  // Filter webhooks by search
  const filteredWebhooks = useMemo(() => {
    if (!searchValueProp.trim()) return webhooks
    const search = searchValueProp.toLowerCase()
    return webhooks.filter(
      (webhook) =>
        webhook.name.toLowerCase().includes(search) ||
        webhook.url.toLowerCase().includes(search) ||
        webhook.$id.toLowerCase().includes(search),
    )
  }, [webhooks, searchValueProp])

  // Convert 1-indexed page to 0-indexed for pagination
  const pageIndexed = currentPage - 1
  const paginatedWebhooks = useMemo(() => {
    const start = pageIndexed * pageSize
    const end = start + pageSize
    return filteredWebhooks.slice(start, end)
  }, [filteredWebhooks, pageIndexed, pageSize])

  const handleUpdate = (webhook: Models.Webhook) => {
    openDialogAfterOverlayCloses(() => {
      setSelectedWebhook(webhook)
      setDrawerOpen(true)
    })
  }

  const requestDelete = (webhook: Models.Webhook) => {
    openDialogAfterOverlayCloses(() => {
      setWebhookToDelete(webhook)
    })
  }

  const handleDelete = () => {
    if (!webhookToDelete) return
    const webhookId = webhookToDelete.$id
    closeDialogBeforeOverlayUnmount(() => {
      setWebhookToDelete(null)
      setSelectedWebhook(null)
    })

    deleteMutation.mutate(webhookId, {
      onSuccess: () => {
        toast.success(t('Webhook has been deleted'))
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || t('Failed to delete webhook'))
      }})
  }

  const getWebhookHref = (webhook: Models.Webhook) =>
    buildConsoleUrl(
      `/projects/${projectId}/settings/webhooks?webhookId=${webhook.$id}`,
    )

  return (
    <div className="mx-auto w-full max-w-7xl flex-1 px-4 pb-4 sm:px-6 sm:pb-6">
      {isLoading ? (
        <div className="flex h-64 items-center justify-center">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      ) : paginatedWebhooks.length === 0 ? (
        <EmptyState
          icon={WebhookIcon}
          title={searchValueProp ? undefined : t('No webhooks yet')}
          description={
            searchValueProp
              ? undefined
              : t('Set up webhooks to receive real-time notifications about events in your project')
          }
          isEmpty={!searchValueProp}
          hasFilters={!!searchValueProp}
          variant="card"
        />
      ) : (
        <>
          <div className="rounded-lg border border-border bg-card">
            <Table>
              <TableHeader>
                <TableRow className="hover:bg-transparent border-b border-border">
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Webhook ID')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Name')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Events')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    URL
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    {t('Enabled')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                    {t('Created')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end">
                    {t('Updated')}
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-end w-[80px]" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {paginatedWebhooks.map((webhook) => (
                  <WebhookContextMenu
                    key={webhook.$id}
                    projectId={projectId}
                    webhook={webhook}
                    onUpdate={handleUpdate}
                    onDelete={requestDelete}
                  >
                  <TableRow
                    className="cursor-pointer"
                    onClick={() => handleUpdate(webhook)}
                  >
                    <TableCell className="px-4 py-3">
                      <CopyableId id={webhook.$id} size="xs" />
                    </TableCell>
                    <TableCell className="px-4 py-3 font-medium">
                      {webhook.name}
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <Badge variant="info" className="text-[10px] shrink-0">
                        {webhook.events?.length || 0} {t('events')}
                      </Badge>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <span className="truncate text-[13px] text-muted-foreground">
                        {webhook.url}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      {webhook.enabled ? (
                        <Badge
                          variant="success"
                          className="text-[10px] shrink-0"
                        >
                          {t('Enabled')}
                        </Badge>
                      ) : (
                        <Badge
                          variant="inactive"
                          className="text-[10px] shrink-0"
                        >
                          {t('Disabled')}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <DateTooltip date={webhook.$createdAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <DateTooltip date={webhook.$updatedAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-end">
                      <div
                        className="flex justify-end"
                        onClick={(event) => event.stopPropagation()}
                      >
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger
                              aria-label={`${t('Actions for')} ${webhook.name}`}
                            />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuItem
                              onClick={() => handleUpdate(webhook)}
                            >
                              <MenuItemContent icon={Pencil}>{t('Update')}</MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuSub>
                              <DropdownMenuSubTrigger>
                                <MenuItemIcon icon={Copy} />
                                {t('Copy')}
                              </DropdownMenuSubTrigger>
                              <DropdownMenuSubContent>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard('ID', webhook.$id)
                                  }
                                >
                                  <MenuItemContent icon={Copy}>
                                    {t('Copy ID')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard('Name', webhook.name)
                                  }
                                >
                                  <MenuItemContent icon={Copy}>
                                    {t('Copy name')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard(
                                      'Link',
                                      getWebhookHref(webhook),
                                    )
                                  }
                                >
                                  <MenuItemContent icon={Link2}>
                                    {t('Copy link')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    void copyResourceAsJson(() =>
                                      fetchProjectWebhook(
                                        projectId,
                                        webhook.$id,
                                      ),
                                    )
                                  }
                                >
                                  <MenuItemContent icon={FileJson}>
                                    {t('Copy as JSON')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                              </DropdownMenuSubContent>
                            </DropdownMenuSub>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              onClick={() =>
                                openInNewTab(getWebhookHref(webhook))
                              }
                            >
                              <MenuItemContent icon={ExternalLink}>
                                {t('Open in new tab')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              onClick={() =>
                                openInNewWindow(getWebhookHref(webhook))
                              }
                            >
                              <MenuItemContent icon={Square}>
                                {t('Open in new window')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              onClick={() => requestDelete(webhook)}
                            >
                              <MenuItemContent icon={Trash2}>{t('Delete')}</MenuItemContent>
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </TableCell>
                  </TableRow>
                  </WebhookContextMenu>
                ))}
              </TableBody>
            </Table>
          </div>

          {filteredWebhooks.length > 0 && (
            <Pagination
              currentPage={currentPage}
              totalItems={filteredWebhooks.length}
              pageSize={pageSize}
              pageSizeOptions={[10, 25, 50, 100]}
              onPageChange={setCurrentPage}
              onPageSizeChange={(size) => {
                setPageSize(size)
                setCurrentPage(1)
              }}
              itemLabel={t('webhooks')}
            />
          )}
        </>
      )}

      <WebhookDrawer
        open={drawerOpen}
        onOpenChange={setDrawerOpen}
        projectId={projectId}
        webhook={selectedWebhook}
        onSuccess={() => setSelectedWebhook(null)}
        onDelete={requestDelete}
      />

      <Dialog
        open={!!webhookToDelete}
        onOpenChange={(open) => {
          if (!open && !deleteMutation.isPending) setWebhookToDelete(null)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete webhook')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')}{' '}
              <strong>{webhookToDelete?.name || t('this webhook')}</strong>?{' '}
              {t('It will stop receiving events immediately. This action cannot be undone.')}
            </DialogDescription>
          </DialogHeader>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              onClick={() => setWebhookToDelete(null)}
              disabled={deleteMutation.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
