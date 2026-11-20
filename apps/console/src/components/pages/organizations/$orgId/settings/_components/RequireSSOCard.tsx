import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from '@tanstack/react-router'
import { toast } from 'sonner'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { useSyncStateFromServer } from '@/hooks/use-sync-state-from-server'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import {
  organizationQueryOptions,
  useOrganizationEmailDomains,
  useOrganizationSSOProviders,
  useRequireOrganizationSSO,
} from '@/lib/react-query/hooks'
import { getErrorMessage } from '@/lib/utils/error-formatting'
import { closeDialogBeforeOverlayUnmount } from '@/lib/utils/overlay-lock'

/**
 * Require SSO for the whole organization: members on its verified domains reach
 * it only after signing in through one of its identity providers. Owners are
 * exempt, and so are members on other domains.
 */
export function RequireSSOCard() {
  const t = useT()
  const { orgId = '' } = useParams({ strict: false })
  const { data: organization } = useQuery(organizationQueryOptions(orgId))
  const { domains } = useOrganizationEmailDomains(orgId)
  const { providers } = useOrganizationSSOProviders(orgId)
  const update = useRequireOrganizationSSO(orgId)
  const [confirming, setConfirming] = useState(false)

  const required =
    (organization as { ssoRequired?: boolean } | null | undefined)
      ?.ssoRequired === true
  const hasVerifiedDomain = domains.some(
    (domain) => domain.status === 'verified',
  )
  const hasEnabledProvider = providers.some((provider) => provider.enabled)
  // Turning it off always works; turning it on needs someone it applies to and a
  // way for them to get in.
  const hint = !hasVerifiedDomain
    ? t('Verify a domain to require single sign-on.')
    : !hasEnabledProvider
      ? t('Enable an identity provider to require single sign-on.')
      : null

  // The switch is a draft until Update, like the other settings toggles.
  const [draft, setDraft] = useSyncStateFromServer(required)
  const hasChanges = draft !== required
  const blocked = !required && hint !== null
  // The server refuses nobody without a verified domain and an enabled
  // provider, so say so rather than show a plain "on".
  const paused = required && draft && hint !== null

  const save = (value: boolean) => {
    update.mutate(value, {
      onSuccess: () =>
        toast.success(
          value
            ? t('Single sign-on is now required')
            : t('Single sign-on is no longer required'),
        ),
      onError: (failure: Error) =>
        toast.error(
          getErrorMessage(failure) || t('Failed to update single sign-on'),
        ),
    })
  }

  const confirm = () => {
    closeDialogBeforeOverlayUnmount(() => setConfirming(false))
    save(true)
  }

  return (
    <>
      <div className="overflow-hidden rounded-xl border border-border bg-card/50">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('Require SSO')}
          </h3>
          <p className="mt-2 text-[13px] text-muted-foreground">
            {t(
              'Members with an email on your verified domains can open this organization only after signing in through your identity provider. Owners and members on other domains keep their usual sign-in.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <div className="flex items-center justify-between gap-4">
            <div className="min-w-0">
              <div className="flex items-center gap-2">
                <Label htmlFor="sso-required" className="text-[13px]">
                  {t('Require single sign-on')}
                </Label>
                {paused ? <Badge variant="warning">{t('Paused')}</Badge> : null}
              </div>
              <p className="mt-1 text-[12px] text-muted-foreground">
                {blocked
                  ? hint
                  : paused
                    ? hasVerifiedDomain
                      ? t(
                          'Paused - with no enabled identity provider, members sign in any way they like. Enable one to require single sign-on again.',
                        )
                      : t(
                          'Paused - with no verified domain, members sign in any way they like. Verify one to require single sign-on again.',
                        )
                    : draft
                      ? t(
                          'Enabled - members on your verified domains must sign in through your identity provider.',
                        )
                      : t('Disabled - members sign in any way they like.')}
              </p>
            </div>
            <Switch
              id="sso-required"
              checked={draft}
              onCheckedChange={setDraft}
              disabled={update.isPending || blocked}
              className="shrink-0"
            />
          </div>
        </div>
        <div className="border-t border-border bg-muted/30 px-6 py-4">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={!hasChanges || update.isPending}
            onClick={() => (draft ? setConfirming(true) : save(false))}
          >
            {t('Update')}
          </Button>
        </div>
      </div>

      <Dialog
        open={confirming}
        onOpenChange={(open) => !open && setConfirming(false)}
      >
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Require single sign-on')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Members on your verified domains who signed in another way lose access to this organization until they sign in through your identity provider. Their other organizations are not affected. Owners keep their usual sign-in, so a broken provider never locks you out.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              variant="outline"
              onClick={() => setConfirming(false)}
              disabled={update.isPending}
            >
              {t('Cancel')}
            </Button>
            <Button
              onClick={confirm}
              disabled={update.isPending}
              {...analyticsAttrs('require-sso')}
            >
              {t('Require SSO')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </>
  )
}
