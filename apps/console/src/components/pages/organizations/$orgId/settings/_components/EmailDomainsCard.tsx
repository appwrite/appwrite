import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from '@tanstack/react-router'
import { Globe, MailCheck, Trash2 } from 'lucide-react'
import { toast } from 'sonner'
import { MenuItemContent } from '@/components/global/shared/ContextMenuIcon'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { RowActionsMenuTrigger } from '@/components/global/shared/RowActionsMenuTrigger'
import { Badge } from '@/components/ui/badge'
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
import { analyticsAttrs } from '@/lib/analytics-actions'
import type { SSODomain } from '@/lib/appwrite/team-sso'
import { useT } from '@/lib/i18n/translate'
import {
  organizationQueryOptions,
  useDeleteOrganizationEmailDomain,
  useOrganizationEmailDomains,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import { CreateEmailDomain } from './CreateEmailDomain'
import {
  focusOnOpen,
  SSOListLoadError,
  useDialogFocusReturn,
  useSingleSubmit,
  SSOLockedTooltip,
  useSSOAddonGate,
} from './SSOCardHelpers'
import { VerifyEmailDomain } from './VerifyEmailDomain'

const HEAD =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

export function EmailDomainsCard() {
  const t = useT()
  const { orgId = '' } = useParams({ strict: false })
  const {
    domains,
    isError: loadFailed,
    isFetching,
    refetch,
  } = useOrganizationEmailDomains(orgId)
  const { data: organization } = useQuery(organizationQueryOptions(orgId))
  const remove = useDeleteOrganizationEmailDomain(orgId)
  // Setup needs the HIPAA BAA add-on; deleting works without it.
  const gate = useSSOAddonGate(orgId)

  const required =
    (organization as { ssoRequired?: boolean } | null | undefined)
      ?.ssoRequired === true
  /** Whether `rule` is the only verified domain while SSO is required. */
  const isLastVerifiedWhileRequired = (rule: SSODomain) =>
    required &&
    rule.status === 'verified' &&
    domains.filter((domain) => domain.status === 'verified').length === 1

  const [createOpen, setCreateOpen] = useState(false)
  const [verifying, setVerifying] = useState<SSODomain | null>(null)
  const [deleting, setDeleting] = useState<SSODomain | null>(null)

  // Focus goes back to what opened a dialog: the dialogs open from state, so
  // Radix has no trigger to return it to.
  const addButton = useRef<HTMLButtonElement>(null)
  const rowMenus = useRef(new Map<string, HTMLButtonElement>())
  const rowVerifyButtons = useRef(new Map<string, HTMLButtonElement>())
  const deleteCancel = useRef<HTMLButtonElement>(null)
  const createFocus = useDialogFocusReturn()
  const verifyFocus = useDialogFocusReturn()
  const deleteFocus = useDialogFocusReturn()
  const rowRef =
    (map: Map<string, HTMLButtonElement>, id: string) =>
    (element: HTMLButtonElement | null) => {
      if (element) map.set(id, element)
      else map.delete(id)
    }
  const openVerify = (rule: SSODomain) => {
    verifyFocus.returnTo(
      () => rowVerifyButtons.current.get(rule.$id),
      () => rowMenus.current.get(rule.$id),
      () => addButton.current,
    )
    setVerifying(rule)
  }
  const openDelete = (rule: SSODomain) => {
    deleteFocus.returnTo(
      () => rowMenus.current.get(rule.$id),
      () => addButton.current,
    )
    setDeleting(rule)
  }

  const submitOnce = useSingleSubmit()
  const confirmDelete = () => {
    if (!deleting) return
    const rule = deleting
    submitOnce((settle) => {
      // The row goes away: focus its neighbour, or Add domain.
      const index = domains.findIndex((domain) => domain.$id === rule.$id)
      const neighbour = domains[index + 1] ?? domains[index - 1]
      deleteFocus.returnTo(
        () => (neighbour ? rowMenus.current.get(neighbour.$id) : null),
        () => addButton.current,
      )
      closeDialogBeforeOverlayUnmount(() => setDeleting(null))
      remove.mutate(rule.$id, {
        onSuccess: () => toast.success(t('Domain deleted')),
        onError: (failure: Error) =>
          toast.error(getErrorMessage(failure) || t('Failed to delete domain')),
        onSettled: settle,
      })
    })
  }

  return (
    <>
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Domains')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'Prove the domains your organization owns. Your identity providers sign in only people with an email on a verified domain.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        {loadFailed ? (
          <SSOListLoadError
            title={t('Failed to load domains')}
            onRetry={() => void refetch()}
            retrying={isFetching}
          />
        ) : domains.length === 0 ? (
          <div className="px-6 py-4">
            <EmptyState
              icon={MailCheck}
              title={t('No domains yet')}
              description={t(
                'Add your company domain, then connect an identity provider so teammates sign in with single sign-on.',
              )}
              isEmpty
              variant="default"
              className="py-2"
            />
          </div>
        ) : (
          <Table>
            <TableHeader>
              <TableRow className="hover:bg-transparent border-b border-border">
                <TableHead className={HEAD}>{t('Domain')}</TableHead>
                <TableHead className={HEAD}>{t('Status')}</TableHead>
                <TableHead className={`${HEAD} sm:w-[140px] text-right`} />
              </TableRow>
            </TableHeader>
            <TableBody>
              {domains.map((rule) => {
                const verified = rule.status === 'verified'
                return (
                  <TableRow key={rule.$id}>
                    <TableCell className="max-w-[9rem] px-4 py-3 sm:max-w-none">
                      <span
                        dir="ltr"
                        className="block w-fit max-w-full truncate font-mono text-[13px]"
                        title={rule.domain}
                      >
                        {rule.domain}
                      </span>
                    </TableCell>
                    <TableCell className="px-4 py-3">
                      <Badge variant={verified ? 'success' : 'warning'}>
                        {verified ? t('Verified') : t('Unverified')}
                      </Badge>
                    </TableCell>
                    <TableCell className="px-4 py-3 text-right">
                      <div className="flex items-center justify-end gap-2">
                        {!verified ? (
                          <SSOLockedTooltip locked={gate.locked}>
                            <Button
                              ref={rowRef(rowVerifyButtons.current, rule.$id)}
                              variant="outline"
                              size="sm"
                              className="h-8 text-[13px]"
                              onClick={() => openVerify(rule)}
                              disabled={gate.locked}
                              aria-label={t('Verify {domain}').replace(
                                '{domain}',
                                rule.domain,
                              )}
                            >
                              {t('Verify')}
                            </Button>
                          </SSOLockedTooltip>
                        ) : null}
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger
                              ref={rowRef(rowMenus.current, rule.$id)}
                              aria-label={`${t('Actions for')} ${rule.domain}`}
                            />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-56">
                            {!verified ? (
                              <>
                                <DropdownMenuItem
                                  onClick={() => openVerify(rule)}
                                >
                                  <MenuItemContent icon={Globe}>
                                    {t('DNS records')}
                                  </MenuItemContent>
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                              </>
                            ) : null}
                            <DropdownMenuItem onClick={() => openDelete(rule)}>
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
        )}
        <div className="border-t border-border bg-muted/30 px-6 py-4">
          <SSOLockedTooltip locked={gate.locked}>
            <Button
              ref={addButton}
              size="sm"
              className="h-9 text-[13px]"
              disabled={gate.locked}
              onClick={() => {
                createFocus.returnTo(() => addButton.current)
                setCreateOpen(true)
              }}
              {...analyticsAttrs('create-email-domain')}
            >
              {t('Add domain')}
            </Button>
          </SSOLockedTooltip>
        </div>
      </div>

      <CreateEmailDomain
        open={createOpen}
        onOpenChange={setCreateOpen}
        organizationId={orgId}
        onCreated={(rule) => {
          if (rule.status !== 'verified') openVerify(rule)
        }}
        onCloseAutoFocus={createFocus.onCloseAutoFocus}
      />
      <VerifyEmailDomain
        rule={verifying}
        organizationId={orgId}
        onOpenChange={(open) => !open && setVerifying(null)}
        onVerified={(rule) =>
          // Its Verify button goes away with the refetch.
          verifyFocus.returnTo(
            () => rowMenus.current.get(rule.$id),
            () => addButton.current,
          )
        }
        onCloseAutoFocus={verifyFocus.onCloseAutoFocus}
      />

      <Dialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
      >
        <DialogContent
          className="sm:max-w-md p-0"
          onOpenAutoFocus={focusOnOpen(() => deleteCancel.current)}
          onCloseAutoFocus={deleteFocus.onCloseAutoFocus}
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete domain')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete "{name}"?').replace(
                '{name}',
                deleting?.domain ?? '',
              )}{' '}
              {t(
                'People with an email on it can no longer sign in through your identity providers. People who already joined stay members.',
              )}
              {deleting && isLastVerifiedWhileRequired(deleting)
                ? ` ${t('Require SSO is on and this is your only verified domain: deleting it pauses Require SSO, and members can sign in any way they like.')}`
                : null}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              ref={deleteCancel}
              variant="outline"
              onClick={() => setDeleting(null)}
              disabled={remove.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={confirmDelete}
              disabled={remove.isPending}
            >
              {t('Delete')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
