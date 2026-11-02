import { useState } from 'react'
import { useNavigate } from '@tanstack/react-router'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ArrowRight,
  Building2,
  CreditCard,
  Info,
  Link2,
  Users,
} from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { sdk } from '@/lib/appwrite/sdk'
import {
  createDomainTransfer,
  deleteDomainTransfer,
  listDomainTransfers,
  updateDomainTransferStatus,
} from '@/lib/appwrite/domain-transfers'
import {
  useDomainTransferDestinations,
  useOrganizationScopes,
  useOrganizations,
  TRANSFER_DESTINATION_BLOCK_LABELS,
} from '@/lib/react-query/hooks'
import { Dependencies } from '@/lib/react-query/hooks/dependencies'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { canTransferDomain } from '@/lib/console-access-checks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { useT } from '@/lib/i18n/translate'
import { ConfirmActionDialog } from '@/components/global/shared/ConfirmActionDialog'
import { CreateTransferLink } from '@/components/global/shared/transfers/CreateTransferLink'
import {
  buildTransferUrl,
  TransferLinks,
  useTransferLinks,
} from '@/components/global/shared/transfers/TransferLinks'
import {
  MoveConsideration,
  TransferActionButton,
  TransferOption,
} from '@/components/global/shared/transfers/TransferOption'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'

interface TransferCardProps {
  domain: Models.Domain
}

/**
 * Hands the domain and its DNS records to another organization: a direct move
 * into one the user owns, or a link for someone in any other organization.
 */
export function TransferCard({ domain }: TransferCardProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const { features } = useConsoleProfile()
  const { organizations, isLoading: organizationsLoading } = useOrganizations()
  const [destinationId, setDestinationId] = useState('')
  const [moveDialogOpen, setMoveDialogOpen] = useState(false)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [link, setLink] = useState<string | null>(null)
  const [transferToDelete, setTransferToDelete] =
    useState<Models.Transfer | null>(null)

  const client = sdk.forConsole.client
  const domainId = domain.$id
  const teamId = domain.teamId
  const queryKey = ['domainTransfers', domainId]
  const links = useTransferLinks(queryKey, (queries) =>
    listDomainTransfers(client, { domainId, queries }),
  )

  const createMutation = useMutation({
    mutationFn: (params: { expire: string; recipientId?: string }) =>
      createDomainTransfer(client, { domainId, ...params }),
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

  const { access } = useOrganizationScopes(teamId)
  const canTransfer = canTransferDomain(access, features)
  const sourceBlockReason = canTransfer
    ? null
    : t("You don't have permission to transfer domains in this organization.")

  const [destinationsShown, setDestinationsShown] = useState(false)
  const destinationChecks = useDomainTransferDestinations(
    destinations,
    destinationsShown,
  )
  const destination = destinationChecks.find(
    (check) =>
      check.organization.$id === destinationId &&
      check.block === null &&
      !check.isChecking,
  )?.organization

  // A move is a transfer reserved for the destination, claimed right away into it
  const moveMutation = useMutation({
    mutationFn: async (recipientId: string) => {
      const transfer = await createDomainTransfer(client, {
        domainId,
        recipientId,
      })
      try {
        return await updateDomainTransferStatus(client, {
          transferId: transfer.$id,
          secret: transfer.secret,
          teamId: recipientId,
        })
      } catch (error) {
        // Don't leave a pending link reserved for the destination behind
        await deleteDomainTransfer(client, { transferId: transfer.$id }).catch(
          () => undefined,
        )
        throw error
      }
    },
    onSuccess: async (moved, recipientId) => {
      const recipient = organizations.find(
        (option) => option.$id === recipientId,
      )
      setMoveDialogOpen(false)
      setDestinationId('')
      toast.success(
        `${moved.domain} ${t('has been transferred to')} ${recipient?.name ?? t('Organization')}`,
      )
      await Promise.all([
        queryClient.refetchQueries({
          queryKey: ['domains', 'organization', teamId],
        }),
        queryClient.refetchQueries({
          queryKey: ['domains', 'organization', recipientId],
        }),
      ])
      queryClient.invalidateQueries({ queryKey: ['domain', domainId] })
      queryClient.invalidateQueries({ queryKey: Dependencies.DOMAINS })
      queryClient.removeQueries({ queryKey })
      navigate({
        to: '/organizations/$orgId/domains/$domainId',
        params: { orgId: recipientId, domainId },
      })
    },
    onError: (error) => {
      toast.error(getErrorMessage(error, t('Failed to move domain')))
    },
  })

  const deleteMutation = useMutation({
    mutationFn: (transferId: string) =>
      deleteDomainTransfer(client, { transferId }),
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
    if (!open) setLink(null)
  }

  return (
    <>
      <div
        data-card-id="transfer-domain"
        className="rounded-xl border border-border bg-card/50 overflow-hidden"
      >
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Transfer domain')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              "Change which organization owns this domain. Its DNS records move with it, and the new organization's plan and members apply from then on.",
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4 @container">
          {!canTransfer && (
            <Alert>
              <Info className="h-4 w-4" />
              <AlertDescription className="text-[13px]">
                {t(
                  'Only organization owners and developers can transfer this domain. Ask an organization owner to transfer it or to update your role.',
                )}
              </AlertDescription>
            </Alert>
          )}
          <div className="grid gap-4 @[640px]:grid-cols-2">
            <TransferOption
              icon={Building2}
              title={t('Move to another organization')}
              description={t(
                'Move this domain to another organization you own. The move happens right away.',
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
                        ? t('Select an organization to move this domain to.')
                        : null)
                  }
                  onClick={() => setMoveDialogOpen(true)}
                >
                  {t('Move domain')}
                </TransferActionButton>
              </div>
            </TransferOption>
            <TransferOption
              icon={Link2}
              title={t('Hand off with a transfer link')}
              description={t(
                "Share a single-use link with someone in another organization. The domain moves when an owner of their organization claims it, and you don't need to be a member of it.",
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
        <TransferLinks
          links={links}
          canDelete={canTransfer}
          onDelete={setTransferToDelete}
        />
      </div>

      <Dialog
        open={moveDialogOpen}
        onOpenChange={(open) => {
          if (!moveMutation.isPending) setMoveDialogOpen(open)
        }}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Move domain')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Consider the following before transferring your domain:')}
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
                icon={Users}
                title={t('Access')}
                description={t(
                  'Members who are not part of the destination organization lose access to this domain and its DNS records.',
                )}
              />
              {domain.registrar?.toLowerCase() === 'appwrite' && (
                <MoveConsideration
                  icon={CreditCard}
                  title={t('Renewals')}
                  description={t(
                    'The destination organization pays for renewals from then on, with its own payment method.',
                  )}
                />
              )}
              {links.total > 0 && (
                <MoveConsideration
                  icon={Link2}
                  title={t('Transfer links')}
                  description={t(
                    'Active transfer links for this domain stop working after the move.',
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
              {t('Move domain')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <CreateTransferLink
        open={dialogOpen}
        onOpenChange={closeDialog}
        link={link}
        isPending={createMutation.isPending}
        blocked={sourceBlockReason !== null}
        ownerId={teamId}
        copy={{
          description: t(
            'Choose how long the link is valid and which organization can claim the domain.',
          ),
          recipientHint: t(
            'Recommended. Only this organization can claim the domain, so the link is useless to anyone else who gets it. Leave empty to let any organization with the link claim it.',
          ),
          alreadyOwned: t('The domain already belongs to this organization.'),
        }}
        onCreate={(params) => createMutation.mutate(params)}
      />

      <ConfirmActionDialog
        open={!!transferToDelete}
        onOpenChange={(open) => {
          if (!open) setTransferToDelete(null)
        }}
        title={t('Delete transfer link')}
        description={t(
          'The link will stop working and the domain can no longer be claimed with it.',
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
