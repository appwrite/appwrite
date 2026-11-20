import { useState } from 'react'
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
  useDeleteOrganizationEmailDomain,
  useOrganizationEmailDomains,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'
import { CreateEmailDomain } from './CreateEmailDomain'
import { VerifyEmailDomain } from './VerifyEmailDomain'

const HEAD =
  'px-4 py-3 text-[12px] font-semibold text-muted-foreground uppercase tracking-wider'

export function EmailDomainsCard() {
  const t = useT()
  const { orgId = '' } = useParams({ strict: false })
  const { domains, isLoading } = useOrganizationEmailDomains(orgId)
  const remove = useDeleteOrganizationEmailDomain(orgId)

  const [createOpen, setCreateOpen] = useState(false)
  const [verifying, setVerifying] = useState<SSODomain | null>(null)
  const [deleting, setDeleting] = useState<SSODomain | null>(null)

  const confirmDelete = () => {
    if (!deleting) return
    const rule = deleting
    closeDialogBeforeOverlayUnmount(() => setDeleting(null))
    remove.mutate(rule.$id, {
      onSuccess: () => toast.success(t('Email domain deleted')),
      onError: (failure: Error) =>
        toast.error(
          getErrorMessage(failure) || t('Failed to delete email domain'),
        ),
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
              'Prove the email domains your organization owns. Your identity providers sign in only people with an email on a verified domain.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        {isLoading && domains.length === 0 ? (
          <div className="space-y-3 px-6 py-4">
            {Array.from({ length: 2 }).map((_, i) => (
              <div
                key={i}
                className="h-4 w-48 animate-pulse rounded bg-muted"
              />
            ))}
          </div>
        ) : domains.length === 0 ? (
          <div className="px-6 py-4">
            <EmptyState
              icon={MailCheck}
              title={t('No email domains yet')}
              description={t(
                'Add your company domain, then connect an identity provider so teammates sign in with your company login.',
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
                <TableHead className={`${HEAD} w-[140px] text-right`} />
              </TableRow>
            </TableHeader>
            <TableBody>
              {domains.map((rule) => {
                const verified = rule.status === 'verified'
                return (
                  <TableRow key={rule.$id}>
                    <TableCell className="px-4 py-3">
                      <span className="font-mono text-[13px]">
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
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-8 text-[13px]"
                            onClick={() => setVerifying(rule)}
                          >
                            {t('Verify')}
                          </Button>
                        ) : null}
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild>
                            <RowActionsMenuTrigger
                              aria-label={`${t('Actions for')} ${rule.domain}`}
                            />
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end" className="w-56">
                            <DropdownMenuItem
                              onClick={() => setVerifying(rule)}
                            >
                              <MenuItemContent icon={Globe}>
                                {t('DNS records')}
                              </MenuItemContent>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem onClick={() => setDeleting(rule)}>
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
          <Button
            size="sm"
            className="h-9 text-[13px]"
            onClick={() => setCreateOpen(true)}
            {...analyticsAttrs('create-email-domain')}
          >
            {t('Add domain')}
          </Button>
        </div>
      </div>

      <CreateEmailDomain
        open={createOpen}
        onOpenChange={setCreateOpen}
        organizationId={orgId}
        onCreated={(rule) => {
          if (rule.status !== 'verified') setVerifying(rule)
        }}
      />
      <VerifyEmailDomain
        rule={verifying}
        organizationId={orgId}
        onOpenChange={(open) => !open && setVerifying(null)}
      />

      <Dialog
        open={deleting !== null}
        onOpenChange={(open) => !open && setDeleting(null)}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Delete email domain')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t('Are you sure you want to delete')} "{deleting?.domain}"?{' '}
              {t(
                'Your identity providers stop signing in emails on it. People who already joined stay members.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
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
