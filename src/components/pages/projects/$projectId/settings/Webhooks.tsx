import { useState, useMemo, useEffect, useCallback } from 'react'
import { useDeleteWebhook, useProjectWebhooks } from '@/lib/react-query/hooks'
import { Button } from '@/components/ui/button'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
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
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Badge } from '@/components/ui/badge'
import {
  Copy,
  ExternalLink,
  FileJson,
  Link2,
  Loader2,
  MoreHorizontal,
  Pencil,
  Square,
  Trash2,
  Webhook as WebhookIcon,
} from 'lucide-react'
import { toast } from 'sonner'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Pagination } from '@/components/global/shared/Pagination'
import { WebhookDrawer } from './webhooks/WebhookDrawer'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import {
  buildConsoleUrl,
  copyToClipboard,
  openInNewTab,
  openInNewWindow,
  toPrettyJson,
} from '@/lib/utils/context-menu'
import type { Models } from '@appwrite.io/console'

interface WebhooksProps {
  projectId: string
  searchValue?: string
}

export function Webhooks({
  projectId,
  searchValue: searchValueProp = '',
}: WebhooksProps) {
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

  const blurActiveElement = useCallback(() => {
    if (document.activeElement instanceof HTMLElement) {
      document.activeElement.blur()
    }
  }, [])

  // Listen for create event from ServiceHeader
  useEffect(() => {
    const handleCreate = () => {
      blurActiveElement()
      window.setTimeout(() => {
        setSelectedWebhook(null)
        setDrawerOpen(true)
      }, 0)
    }
    window.addEventListener('settings-create-webhook', handleCreate)
    return () => {
      window.removeEventListener('settings-create-webhook', handleCreate)
    }
  }, [blurActiveElement])

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
    blurActiveElement()
    window.setTimeout(() => {
      setSelectedWebhook(webhook)
      setDrawerOpen(true)
    }, 0)
  }

  const requestDelete = (webhook: Models.Webhook) => {
    blurActiveElement()
    setWebhookToDelete(webhook)
  }

  const handleDelete = () => {
    if (!webhookToDelete) return

    deleteMutation.mutate(webhookToDelete.$id, {
      onSuccess: () => {
        toast.success('Webhook has been deleted')
        setWebhookToDelete(null)
        setSelectedWebhook(null)
      },
      onError: (error: Error) => {
        toast.error(getErrorMessage(error) || 'Failed to delete webhook')
      },
    })
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
          title={searchValueProp ? undefined : 'No webhooks yet'}
          description={
            searchValueProp
              ? undefined
              : 'Set up webhooks to receive real-time notifications about events in your project'
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
                    Webhook ID
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Name
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Events
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    URL
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider">
                    Enabled
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                    Created
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right">
                    Updated
                  </TableHead>
                  <TableHead className="px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider text-right w-[80px]" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {paginatedWebhooks.map((webhook) => (
                  <TableRow
                    key={webhook.$id}
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
                        {webhook.events?.length || 0} events
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
                          Enabled
                        </Badge>
                      ) : (
                        <Badge
                          variant="inactive"
                          className="text-[10px] shrink-0"
                        >
                          Disabled
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="px-4 py-3 text-right">
                      <DateTooltip date={webhook.$createdAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-right">
                      <DateTooltip date={webhook.$updatedAt} />
                    </TableCell>
                    <TableCell className="px-4 py-3 text-right">
                      <div
                        className="flex justify-end"
                        onClick={(event) => event.stopPropagation()}
                      >
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <Button
                              type="button"
                              variant="ghost"
                              size="sm"
                              className="h-8 w-8 p-0"
                              aria-label={`Actions for ${webhook.name}`}
                            >
                              <MoreHorizontal className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuItem
                              onClick={() => handleUpdate(webhook)}
                            >
                              <Pencil className="mr-1.5 h-4 w-4" />
                              Update
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuSub>
                              <DropdownMenuSubTrigger>
                                <Copy className="mr-1.5 h-4 w-4" />
                                Copy
                              </DropdownMenuSubTrigger>
                              <DropdownMenuSubContent>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard('ID', webhook.$id)
                                  }
                                >
                                  <Copy className="mr-1.5 h-4 w-4" />
                                  Copy ID
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard('Name', webhook.name)
                                  }
                                >
                                  <Copy className="mr-1.5 h-4 w-4" />
                                  Copy name
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard(
                                      'Link',
                                      getWebhookHref(webhook),
                                    )
                                  }
                                >
                                  <Link2 className="mr-1.5 h-4 w-4" />
                                  Copy link
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard(
                                      'JSON',
                                      toPrettyJson({
                                        id: webhook.$id,
                                        name: webhook.name,
                                        url: webhook.url,
                                        enabled: webhook.enabled,
                                        events: webhook.events || [],
                                      }),
                                    )
                                  }
                                >
                                  <FileJson className="mr-1.5 h-4 w-4" />
                                  Copy as JSON
                                </DropdownMenuItem>
                              </DropdownMenuSubContent>
                            </DropdownMenuSub>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              onClick={() =>
                                openInNewTab(getWebhookHref(webhook))
                              }
                            >
                              <ExternalLink className="mr-1.5 h-4 w-4" />
                              Open in new tab
                            </DropdownMenuItem>
                            <DropdownMenuItem
                              onClick={() =>
                                openInNewWindow(getWebhookHref(webhook))
                              }
                            >
                              <Square className="mr-1.5 h-4 w-4" />
                              Open in new window
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                              onClick={() => requestDelete(webhook)}
                            >
                              <Trash2 className="mr-1.5 h-4 w-4" />
                              Delete
                            </DropdownMenuItem>
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </div>
                    </TableCell>
                  </TableRow>
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
              itemLabel="webhooks"
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
          <DialogHeader className="px-6 pt-6 pb-4 text-left">
            <DialogTitle>Delete webhook</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Are you sure you want to delete{' '}
              <strong>{webhookToDelete?.name || 'this webhook'}</strong>? It
              will stop receiving events immediately. This action cannot be
              undone.
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
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              size="sm"
              className="h-9 text-[13px]"
              onClick={handleDelete}
              disabled={deleteMutation.isPending}
            >
              Delete
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  )
}
