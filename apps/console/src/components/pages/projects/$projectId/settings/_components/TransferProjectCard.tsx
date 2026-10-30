import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Copy, Link2, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildConsoleUrl, copyToClipboard } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'

const EXPIRATIONS = [
  { value: '1', label: '24 hours', seconds: 24 * 60 * 60 },
  { value: '7', label: '7 days', seconds: 7 * 24 * 60 * 60 },
  { value: '30', label: '30 days', seconds: 30 * 24 * 60 * 60 },
] as const

const DEFAULT_PAGE_SIZE = 10

const HEAD_CLASS_NAME =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

export function buildTransferUrl(transfer: Models.Transfer): string {
  const params = new URLSearchParams({
    transferId: transfer.$id,
    secret: transfer.secret,
  })
  return buildConsoleUrl(`/transfer?${params.toString()}`)
}

interface TransferProjectCardProps {
  projectId: string
  teamId: string
}

export function TransferProjectCard({
  projectId,
  teamId,
}: TransferProjectCardProps) {
  const t = useT()
  const queryClient = useQueryClient()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [expiration, setExpiration] = useState<string>('1')
  const [recipientId, setRecipientId] = useState('')
  const [link, setLink] = useState<string | null>(null)
  const [transferToDelete, setTransferToDelete] =
    useState<Models.Transfer | null>(null)
  const [requestedPage, setRequestedPage] = useState(1)
  const [displayedPage, setDisplayedPage] = useState(1)
  const [pageSize, setPageSize] = useState(DEFAULT_PAGE_SIZE)

  const organization = sdk.forConsole.organization(teamId)
  const queryKey = ['projectTransfers', teamId, projectId]

  const listPage = (page: number) => ({
    queryKey: [...queryKey, page, pageSize],
    queryFn: () =>
      organization.listProjectTransfers({
        projectId,
        queries: [
          Query.orderDesc('$createdAt'),
          Query.limit(pageSize),
          Query.offset((page - 1) * pageSize),
        ],
      }),
  })

  // Fetch the requested page, but keep showing the current one until it arrives
  const requested = useQuery(listPage(requestedPage))
  const displayed = useQuery(listPage(displayedPage))

  useEffect(() => {
    if (
      !requested.isFetching &&
      requestedPage !== displayedPage &&
      requested.data
    ) {
      setDisplayedPage(requestedPage)
    }
  }, [displayedPage, requested.data, requested.isFetching, requestedPage])

  // Go back to the shown page, so the next click requests the failed page again
  useEffect(() => {
    if (
      requested.isError &&
      !requested.isFetching &&
      requestedPage !== displayedPage
    ) {
      toast.error(
        getErrorMessage(requested.error, t('Failed to load transfer links')),
      )
      setRequestedPage(displayedPage)
    }
  }, [
    displayedPage,
    requested.error,
    requested.isError,
    requested.isFetching,
    requestedPage,
    t,
  ])

  // Deleting the last link on the last page leaves that page empty
  const total = displayed.data?.total ?? 0
  const lastPage = Math.max(1, Math.ceil(total / pageSize))
  useEffect(() => {
    if (displayed.data && displayedPage > lastPage) {
      setRequestedPage(lastPage)
      setDisplayedPage(lastPage)
    }
  }, [displayed.data, displayedPage, lastPage])

  const createMutation = useMutation({
    mutationFn: async () => {
      const seconds =
        EXPIRATIONS.find((option) => option.value === expiration)?.seconds ??
        EXPIRATIONS[0].seconds
      return organization.createProjectTransfer({
        projectId,
        recipientId: recipientId.trim() || undefined,
        expire: new Date(Date.now() + seconds * 1000).toISOString(),
      })
    },
    onSuccess: async (transfer) => {
      setLink(buildTransferUrl(transfer))
      await queryClient.invalidateQueries({ queryKey })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t('Failed to create transfer link')))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (transferId: string) =>
      organization.deleteProjectTransfer({ transferId }),
    onSuccess: async () => {
      setTransferToDelete(null)
      toast.success(t('Transfer link has been deleted'))
      await queryClient.invalidateQueries({ queryKey })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t('Failed to delete transfer link')))
    },
  })

  const closeDialog = (open: boolean) => {
    setDialogOpen(open)
    if (!open) {
      setLink(null)
      setExpiration('1')
      setRecipientId('')
    }
  }

  const transfers = displayed.data?.transfers ?? []

  return (
    <>
      <div
        data-card-id="transfer-project"
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Transfer project')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Create a link that lets another organization claim this project. Each link can be claimed once.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          {displayed.isError ? (
            <p className="text-[13px] text-muted-foreground">
              {t('Failed to load transfer links')}
            </p>
          ) : transfers.length === 0 ? (
            <p className="text-[13px] text-muted-foreground">
              {displayed.isLoading || total > 0
                ? t('Loading...')
                : t('No active transfer links')}
            </p>
          ) : (
            <>
              <div className="rounded-lg border border-border bg-card">
                <Table>
                  <TableHeader>
                    <TableRow className="hover:bg-transparent border-b border-border">
                      <TableHead className={HEAD_CLASS_NAME}>
                        {t('Recipient')}
                      </TableHead>
                      <TableHead className={HEAD_CLASS_NAME}>
                        {t('Created')}
                      </TableHead>
                      <TableHead className={HEAD_CLASS_NAME}>
                        {t('Expires')}
                      </TableHead>
                      <TableHead className={`${HEAD_CLASS_NAME} w-[64px]`} />
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {transfers.map((transfer) => (
                      <TableRow key={transfer.$id}>
                        <TableCell className="px-4 py-3">
                          {transfer.recipientId ? (
                            <div className="flex items-center gap-2">
                              {transfer.recipientName && (
                                <span className="text-[13px] text-foreground">
                                  {transfer.recipientName}
                                </span>
                              )}
                              <CopyableId id={transfer.recipientId} size="xs" />
                            </div>
                          ) : (
                            <span className="text-[13px] text-muted-foreground">
                              {t('Any organization')}
                            </span>
                          )}
                        </TableCell>
                        <TableCell className="px-4 py-3 text-[13px]">
                          <DateTooltip date={transfer.$createdAt} />
                        </TableCell>
                        <TableCell className="px-4 py-3 text-[13px]">
                          <DateTooltip date={transfer.expire} />
                        </TableCell>
                        <TableCell className="px-4 py-3">
                          <div className="flex justify-end">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <RowActionsMenuTrigger
                                  aria-label={t('Transfer link actions')}
                                />
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end" className="w-48">
                                <DropdownMenuItem
                                  onClick={() =>
                                    copyToClipboard(
                                      t('Transfer link'),
                                      buildTransferUrl(transfer),
                                    )
                                  }
                                >
                                  <MenuItemContent icon={Link2}>
                                    {t('Copy link')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                  onClick={() => setTransferToDelete(transfer)}
                                >
                                  <MenuItemContent icon={Trash2}>
                                    {t('Delete')}
                                  </MenuItemContent>
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
                itemLabel={t('transfer links')}
                scrollToTopOnPageChange={false}
              />
            </>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => setDialogOpen(true)}
          >
            {t('Create transfer link')}
          </Button>
        </div>
      </div>

      <Dialog open={dialogOpen} onOpenChange={closeDialog}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 text-start">
            <DialogTitle>{t('Create transfer link')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {link
                ? t(
                    'Share this link with the recipient organization. You can copy it again from the list.',
                  )
                : t(
                    'Choose how long the link is valid and which organization can claim the project.',
                  )}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          {link ? (
            <div className="px-6 pb-4 pt-0 space-y-3">
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <Label
                    htmlFor="transfer-link"
                    className="text-[13px] font-medium text-foreground"
                  >
                    {t('Transfer link')}
                  </Label>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-7 text-[12px]"
                    onClick={() => copyToClipboard(t('Transfer link'), link)}
                  >
                    <Copy className="h-3 w-3 me-1" />
                    {t('Copy')}
                  </Button>
                </div>
                <Textarea
                  id="transfer-link"
                  readOnly
                  value={link}
                  className="font-mono text-[12px] min-h-[80px] resize-none break-all"
                  onClick={(event) => event.currentTarget.select()}
                />
              </div>
            </div>
          ) : (
            <div className="px-6 pb-4 pt-0 space-y-4">
              <div>
                <Label
                  htmlFor="transfer-expiration"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Expires in')}
                </Label>
                <Select value={expiration} onValueChange={setExpiration}>
                  <SelectTrigger id="transfer-expiration" className="mt-2 h-9">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {EXPIRATIONS.map((option) => (
                      <SelectItem key={option.value} value={option.value}>
                        {t(option.label)}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div>
                <Label
                  htmlFor="transfer-recipient"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Recipient organization ID (optional)')}
                </Label>
                <Input
                  id="transfer-recipient"
                  value={recipientId}
                  onChange={(event) => setRecipientId(event.target.value)}
                  placeholder={t('Any organization')}
                  className="mt-2 h-9"
                />
                <p className="text-[12px] text-muted-foreground mt-1.5">
                  {t('Leave empty to let any organization claim the project.')}
                </p>
              </div>
            </div>
          )}
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            {link ? (
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => closeDialog(false)}
              >
                {t('Done')}
              </Button>
            ) : (
              <>
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => closeDialog(false)}
                >
                  {t('Cancel')}
                </Button>
                <Button
                  size="sm"
                  className="h-9 text-[13px]"
                  disabled={createMutation.isPending}
                  onClick={() => createMutation.mutate()}
                >
                  {t('Create link')}
                </Button>
              </>
            )}
          </div>
        </DialogContent>
      </Dialog>

      <ConfirmActionDialog
        open={!!transferToDelete}
        onOpenChange={(open) => {
          if (!open) setTransferToDelete(null)
        }}
        title={t('Delete transfer link')}
        description={t(
          'The link will stop working and the project can no longer be claimed with it.',
        )}
        confirmLabel={t('Delete')}
        confirmVariant="destructive"
        isConfirming={deleteMutation.isPending}
        onConfirm={() => {
          if (transferToDelete) deleteMutation.mutate(transferToDelete.$id)
        }}
      />
    </>
  )
}
