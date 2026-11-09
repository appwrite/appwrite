import { useEffect, useState, type ReactNode } from 'react'
import { Link, useNavigate } from '@tanstack/react-router'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  ArrowRight,
  Building2,
  Copy,
  CreditCard,
  Info,
  Link2,
  Shield,
  Trash2,
  Users,
  type LucideIcon,
} from 'lucide-react'
import { toast } from 'sonner'
import { Query, type Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  useOrganizationScopes,
  useOrganizations,
  useTransferDestinations,
  useTransferSourceBillingIssue,
  TRANSFER_DESTINATION_BLOCK_LABELS,
} from '@/lib/react-query/hooks'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canTransferProject } from '@/lib/console-access-checks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { buildConsoleUrl, copyToClipboard } from '@/lib/utils/context-menu'
import { useT } from '@/lib/i18n/translate'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { DateTooltip } from '@/components/global/shared/DateTooltip'
import { Pagination } from '@/components/global/shared/Pagination'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Badge } from '@/components/ui/badge'
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
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip'

const EXPIRATIONS = [
  { value: '1', label: '24 hours', seconds: 24 * 60 * 60 },
  { value: '7', label: '7 days', seconds: 7 * 24 * 60 * 60 },
  { value: '30', label: '30 days', seconds: 30 * 24 * 60 * 60 },
] as const

const DEFAULT_PAGE_SIZE = 10

const RECIPIENT_ID_MAX_LENGTH = 36

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
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const { organizations, isLoading: organizationsLoading } = useOrganizations()
  const [destinationId, setDestinationId] = useState('')
  const [moveDialogOpen, setMoveDialogOpen] = useState(false)
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

  const currentOrganization = organizations.find(
    (option) => option.$id === teamId,
  )
  const destinations = organizations.filter((option) => option.$id !== teamId)
  const hasNoDestinations = !organizationsLoading && destinations.length === 0

  // Transfer routes ignore project roles, so resolve the organization without the project
  const { access } = useOrganizationScopes(teamId, undefined, {
    projectId: null,
  })
  const canTransfer = canTransferProject(access, features)
  const hasBillingIssue = useTransferSourceBillingIssue(currentOrganization)
  const sourceBlockReason = !canTransfer
    ? t("You don't have permission to transfer projects in this organization.")
    : hasBillingIssue
      ? t("Resolve this organization's billing issues before transferring.")
      : null

  const [destinationsShown, setDestinationsShown] = useState(false)
  const destinationChecks = useTransferDestinations(
    destinations,
    destinationsShown,
  )
  const destination = destinationChecks.find(
    (check) =>
      check.organization.$id === destinationId &&
      check.block === null &&
      !check.isChecking,
  )?.organization

  const trimmedRecipientId = recipientId.trim()
  const recipientError =
    trimmedRecipientId === teamId
      ? t('The project already belongs to this organization.')
      : null

  // A move is a transfer reserved for the destination, claimed right away by the destination
  const moveMutation = useMutation({
    mutationFn: async (recipientId: string) => {
      const transfer = await organization.createProjectTransfer({
        projectId,
        recipientId,
      })
      try {
        return await sdk.forConsole
          .organization(recipientId)
          .updateProjectTransferStatus({
            transferId: transfer.$id,
            secret: transfer.secret,
          })
      } catch (error) {
        // Don't leave a pending link reserved for the destination behind
        await organization
          .deleteProjectTransfer({ transferId: transfer.$id })
          .catch(() => undefined)
        throw error
      }
    },
    onSuccess: async (project, recipientId) => {
      const recipient = organizations.find(
        (option) => option.$id === recipientId,
      )
      setMoveDialogOpen(false)
      setDestinationId('')
      toast.success(
        `${project.name} ${t('has been transferred to')} ${recipient?.name ?? t('Organization')}`,
      )
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['project', projectId],
          exact: true,
          type: 'all',
        }),
        queryClient.refetchQueries({
          predicate: (query) => query.queryKey[0] === 'projects',
          type: 'all',
        }),
        queryClient.refetchQueries({
          queryKey: ['organizations', 'console'],
          type: 'all',
        }),
        queryClient.invalidateQueries({ queryKey }),
      ])
      navigate({
        to: '/organizations/$orgId',
        params: { orgId: recipientId },
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t('Failed to move project')))
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
              "Change which organization owns this project. Its resources and data move with it, and the new organization's plan and members apply from then on.",
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4 @container">
          {!canTransfer ? (
            <Alert>
              <Info className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                {t(
                  'Only organization members whose role can manage projects can transfer this project. Ask an organization owner to transfer it or to update your role.',
                )}
              </AlertDescription>
            </Alert>
          ) : hasBillingIssue ? (
            <Alert>
              <Info className="h-4 w-4" />
              <AlertDescription className="flex flex-col gap-3 text-[13px] sm:flex-row sm:items-center sm:justify-between">
                <span>
                  {t(
                    'This organization has a failed or unauthorized payment. Resolve its billing issues before transferring this project.',
                  )}
                </span>
                <Button
                  asChild
                  variant="outline"
                  size="sm"
                  className="h-8 shrink-0 text-[12px]"
                >
                  <Link
                    to="/organizations/$orgId/settings/billing"
                    params={{ orgId: teamId }}
                  >
                    {t('Go to billing')}
                  </Link>
                </Button>
              </AlertDescription>
            </Alert>
          ) : null}
          <div className="grid gap-4 @[640px]:grid-cols-2">
            <TransferOption
              icon={Building2}
              title={t('Move to another organization')}
              description={t(
                'Move this project to another organization you are a member of. The move happens right away.',
              )}
            >
              <div className="flex gap-2">
                <Select
                  value={destination?.$id ?? ''}
                  onValueChange={setDestinationId}
                  onOpenChange={(open) => {
                    if (open) setDestinationsShown(true)
                  }}
                  disabled={
                    sourceBlockReason !== null ||
                    organizationsLoading ||
                    hasNoDestinations
                  }
                >
                  <SelectTrigger
                    aria-label={t('Destination organization')}
                    className="h-9 flex-1 min-w-0"
                  >
                    <SelectValue
                      placeholder={
                        organizationsLoading
                          ? t('Loading organizations...')
                          : hasNoDestinations
                            ? t('No other organizations available')
                            : t('Select organization')
                      }
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {destinationChecks.map(
                      ({ organization: option, block, isChecking }) => (
                        <SelectItem
                          key={option.$id}
                          value={option.$id}
                          disabled={isChecking || block !== null}
                        >
                          <span className="truncate">{option.name}</span>
                          {(isChecking || block !== null) && (
                            <span className="ms-2 text-[12px] text-muted-foreground">
                              {isChecking
                                ? t('Checking...')
                                : t(TRANSFER_DESTINATION_BLOCK_LABELS[block!])}
                            </span>
                          )}
                        </SelectItem>
                      ),
                    )}
                  </SelectContent>
                </Select>
                <TransferActionButton
                  disabledReason={
                    sourceBlockReason ??
                    (hasNoDestinations
                      ? t('You are not a member of any other organization.')
                      : !destination
                        ? t('Select an organization to move this project to.')
                        : null)
                  }
                  onClick={() => setMoveDialogOpen(true)}
                >
                  {t('Move project')}
                </TransferActionButton>
              </div>
            </TransferOption>
            <TransferOption
              icon={Link2}
              title={t('Hand off with a transfer link')}
              description={t(
                "Share a single-use link with someone in another organization. The project moves when they claim it, and you don't need to be a member of their organization.",
              )}
            >
              <TransferActionButton
                disabledReason={sourceBlockReason}
                onClick={() => setDialogOpen(true)}
              >
                {t('Create transfer link')}
              </TransferActionButton>
            </TransferOption>
          </div>
        </div>
        {(displayed.isError || transfers.length > 0) && (
          <>
            <div className="border-t border-border" />
            <div className="px-6 py-4 space-y-4">
              <div>
                <h4 className="text-[13px] font-medium text-foreground">
                  {t('Pending transfer links')}
                </h4>
                <p className="text-[12px] text-muted-foreground mt-1">
                  {t(
                    'Links that have not been claimed yet. Expired links can no longer be claimed. Delete a link to stop it from working sooner.',
                  )}
                </p>
              </div>
              {displayed.isError ? (
                <p className="text-[13px] text-muted-foreground">
                  {getErrorMessage(
                    displayed.error,
                    t('Failed to load transfer links'),
                  )}
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
                          <TableHead
                            className={`${HEAD_CLASS_NAME} w-[64px]`}
                          />
                        </TableRow>
                      </TableHeader>
                      <TableBody>
                        {transfers.map((transfer) => {
                          const isExpired =
                            new Date(transfer.expire).getTime() <= Date.now()
                          return (
                            <TableRow key={transfer.$id}>
                              <TableCell className="px-4 py-3">
                                {transfer.recipientId ? (
                                  <div className="flex items-center gap-2">
                                    {transfer.recipientName && (
                                      <span className="text-[13px] text-foreground">
                                        {transfer.recipientName}
                                      </span>
                                    )}
                                    <CopyableId
                                      id={transfer.recipientId}
                                      size="xs"
                                    />
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
                                <div className="flex items-center gap-2">
                                  <DateTooltip date={transfer.expire} />
                                  {isExpired && (
                                    <Badge
                                      variant="error"
                                      className="text-[10px] shrink-0"
                                    >
                                      {t('Expired')}
                                    </Badge>
                                  )}
                                </div>
                              </TableCell>
                              <TableCell className="px-4 py-3">
                                <div className="flex justify-end">
                                  <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                      <RowActionsMenuTrigger
                                        aria-label={t('Transfer link actions')}
                                      />
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent
                                      align="end"
                                      className="w-48"
                                    >
                                      <DropdownMenuItem
                                        disabled={isExpired}
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
                                        disabled={!canTransfer}
                                        onClick={() =>
                                          setTransferToDelete(transfer)
                                        }
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
                          )
                        })}
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
          </>
        )}
      </div>

      <Dialog
        open={moveDialogOpen}
        onOpenChange={(open) => {
          if (!moveMutation.isPending) setMoveDialogOpen(open)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Move project')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Consider the following before transferring your project:')}
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="flex items-center gap-3 rounded-lg border border-border bg-muted/30 px-3 py-2.5 text-[13px]">
              <span
                className="min-w-0 flex-1 truncate text-muted-foreground"
                title={currentOrganization?.name}
              >
                {currentOrganization?.name ?? teamId}
              </span>
              <ArrowRight className="h-4 w-4 shrink-0 text-muted-foreground rtl:rotate-180" />
              <span
                className="min-w-0 flex-1 truncate text-end font-medium text-foreground"
                title={destination?.name}
              >
                {destination?.name}
              </span>
            </div>
            <div className="space-y-3">
              <MoveConsideration
                icon={Shield}
                title={t('Permissions')}
                description={t(
                  'Depending on your role in the target organization, your level of access may change after transfer.',
                )}
              />
              <MoveConsideration
                icon={Users}
                title={t('Access')}
                description={t(
                  'Members who are not part of the destination organization will lose access and must be invited to the new organization to regain access.',
                )}
              />
              <MoveConsideration
                icon={CreditCard}
                title={t('Features and usage')}
                description={t(
                  'The target organization’s pricing plan may limit features or usage (e.g. executions, storage, or team size) for this project.',
                )}
              />
              {total > 0 && (
                <MoveConsideration
                  icon={Link2}
                  title={t('Transfer links')}
                  description={t(
                    'Active transfer links for this project stop working after the move.',
                  )}
                />
              )}
            </div>
          </div>
          <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              size="sm"
              className="h-9 text-[13px]"
              disabled={moveMutation.isPending}
              onClick={() => setMoveDialogOpen(false)}
            >
              {t('Cancel')}
            </Button>
            <Button
              size="sm"
              className="h-9 text-[13px]"
              disabled={
                sourceBlockReason !== null ||
                !destination ||
                moveMutation.isPending
              }
              onClick={() => {
                if (destination) moveMutation.mutate(destination.$id)
              }}
            >
              {t('Move project')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

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
                  maxLength={RECIPIENT_ID_MAX_LENGTH}
                  aria-invalid={recipientError !== null}
                  className="mt-2 h-9"
                />
                {recipientError ? (
                  <p className="text-[12px] text-destructive mt-1.5">
                    {recipientError}
                  </p>
                ) : (
                  <p className="text-[12px] text-muted-foreground mt-1.5">
                    {t(
                      'Recommended. Only this organization can claim the project, so the link is useless to anyone else who gets it. Leave empty to let any organization with the link claim it.',
                    )}
                  </p>
                )}
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
                  disabled={
                    createMutation.isPending ||
                    recipientError !== null ||
                    sourceBlockReason !== null
                  }
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

interface TransferOptionProps {
  icon: LucideIcon
  title: string
  description: string
  children: ReactNode
}

function TransferOption({
  icon: Icon,
  title,
  description,
  children,
}: TransferOptionProps) {
  return (
    <section className="flex flex-col gap-4 rounded-lg border border-border bg-card p-4">
      <div className="flex gap-3">
        <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
          <Icon className="h-4 w-4 text-muted-foreground" />
        </div>
        <div className="min-w-0">
          <h4 className="text-[13px] font-medium text-foreground">{title}</h4>
          <p className="text-[12px] text-muted-foreground mt-0.5">
            {description}
          </p>
        </div>
      </div>
      <div className="mt-auto">{children}</div>
    </section>
  )
}

interface TransferActionButtonProps {
  disabledReason: string | null
  onClick: () => void
  children: ReactNode
}

function TransferActionButton({
  disabledReason,
  onClick,
  children,
}: TransferActionButtonProps) {
  if (disabledReason === null) {
    return (
      <Button size="sm" className="h-9 text-[13px] shrink-0" onClick={onClick}>
        {children}
      </Button>
    )
  }

  return (
    <TooltipProvider>
      <Tooltip>
        <TooltipTrigger asChild>
          <span className="inline-block shrink-0">
            <Button size="sm" className="h-9 text-[13px]" disabled>
              {children}
            </Button>
          </span>
        </TooltipTrigger>
        <TooltipContent>{disabledReason}</TooltipContent>
      </Tooltip>
    </TooltipProvider>
  )
}

interface MoveConsiderationProps {
  icon: LucideIcon
  title: string
  description: string
}

function MoveConsideration({
  icon: Icon,
  title,
  description,
}: MoveConsiderationProps) {
  return (
    <div className="flex gap-3">
      <div className="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-muted">
        <Icon className="h-4 w-4 text-muted-foreground" />
      </div>
      <div>
        <p className="text-[13px] font-medium text-foreground">{title}</p>
        <p className="text-[12px] text-muted-foreground mt-0.5">
          {description}
        </p>
      </div>
    </div>
  )
}
