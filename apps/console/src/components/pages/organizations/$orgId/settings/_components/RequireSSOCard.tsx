import { useRef, useState } from 'react'
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
import {
  focusOnOpen,
  useDialogFocusReturn,
  useSingleSubmit,
  useSSOAddonErrorHandler,
  useSSOAddonGate,
} from './SSOCardHelpers'

/**
 * Require SSO for the whole organization: members on its verified domains reach
 * it only after signing in through one of its identity providers. Owners are
 * exempt, and so are members on other domains.
 */
export function RequireSSOCard() {
  const t = useT()
  const { orgId = '' } = useParams({ strict: false })
  const { data: organization } = useQuery(organizationQueryOptions(orgId))
  const { domains, isSuccess: domainsLoaded } =
    useOrganizationEmailDomains(orgId)
  const { providers, isSuccess: providersLoaded } =
    useOrganizationSSOProviders(orgId)
  const update = useRequireOrganizationSSO(orgId)
  // Turning it on needs the Single sign-on add-on; turning it off works without it.
  const gate = useSSOAddonGate(orgId)
  const onAddonError = useSSOAddonErrorHandler(orgId)
  const [confirming, setConfirming] = useState(false)

  const required =
    (organization as { ssoRequired?: boolean } | null | undefined)
      ?.ssoRequired === true
  const hasVerifiedDomain = domains.some(
    (domain) => domain.status === 'verified',
  )
  const hasEnabledProvider = providers.some((provider) => provider.enabled)
  // Turning it off always works; turning it on needs someone it applies to and a
  // way for them to get in. Until both lists are in (or when one failed to
  // load), nothing is claimed either way: the server still checks.
  const hint = gate.locked
    ? t('Enable the Single sign-on add-on to require single sign-on.')
    : !(domainsLoaded && providersLoaded)
      ? null
      : !hasVerifiedDomain
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

  const switchRef = useRef<HTMLButtonElement>(null)
  const updateButton = useRef<HTMLButtonElement>(null)
  const confirmCancel = useRef<HTMLButtonElement>(null)
  const confirmFocus = useDialogFocusReturn()

  const submitOnce = useSingleSubmit()
  const save = (value: boolean) =>
    submitOnce((settle) =>
      update.mutate(value, {
        onSettled: settle,
        onSuccess: () =>
          toast.success(
            value
              ? t('Single sign-on is now required')
              : t('Single sign-on is no longer required'),
          ),
        onError: (failure: Error) => {
          if (onAddonError(failure)) return
          toast.error(
            getErrorMessage(failure) || t('Failed to update single sign-on'),
          )
        },
      }),
    )

  const openConfirm = () => {
    // Update has nothing left to save once it is confirmed: then the switch.
    confirmFocus.returnTo(
      () => (update.isPending ? null : updateButton.current),
      () => switchRef.current,
    )
    setConfirming(true)
  }

  const confirm = () => {
    confirmFocus.returnTo(() => switchRef.current)
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
          <p
            id="sso-required-description"
            className="mt-2 text-[13px] text-muted-foreground"
          >
            {t(
              'Members with an email on your verified domains can open this organization only after signing in through your identity provider. Owners can always sign in with any method, so a broken identity provider never locks your organization out. Members on other domains keep their usual sign-in too.',
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
              <p
                id="sso-required-state"
                className="mt-1 text-[12px] text-muted-foreground"
              >
                {blocked
                  ? hint
                  : paused
                    ? gate.locked
                      ? t(
                          'Paused - enable the Single sign-on add-on to require single sign-on again.',
                        )
                      : hasVerifiedDomain
                        ? t(
                            'Paused - enable an identity provider to require single sign-on again.',
                          )
                        : t(
                            'Paused - verify a domain to require single sign-on again.',
                          )
                    : draft
                      ? t(
                          'Enabled - members on your verified domains must use single sign-on.',
                        )
                      : t('Disabled - members sign in any way they like.')}
              </p>
            </div>
            <Switch
              ref={switchRef}
              id="sso-required"
              checked={draft}
              // Stays focusable while saving: a focused control that turns
              // disabled drops focus to the page.
              onCheckedChange={(value) => !update.isPending && setDraft(value)}
              aria-disabled={update.isPending || undefined}
              aria-describedby="sso-required-state sso-required-description"
              disabled={blocked}
              className="shrink-0 aria-disabled:cursor-wait aria-disabled:opacity-50"
            />
          </div>
        </div>
        <div className="border-t border-border bg-muted/30 px-6 py-4">
          <Button
            ref={updateButton}
            size="sm"
            className="h-9 text-[13px]"
            disabled={!hasChanges || update.isPending}
            onClick={() => (draft ? openConfirm() : save(false))}
          >
            {t('Update')}
          </Button>
        </div>
      </div>

      <Dialog
        open={confirming}
        onOpenChange={(open) => !open && setConfirming(false)}
      >
        <DialogContent
          className="sm:max-w-md p-0"
          onOpenAutoFocus={focusOnOpen(() => confirmCancel.current)}
          onCloseAutoFocus={confirmFocus.onCloseAutoFocus}
        >
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>{t('Require single sign-on')}</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              {t(
                'Members on your verified domains who signed in another way lose access to this organization until they sign in through your identity provider. Their other organizations are not affected. Owners keep their usual sign-in, so a broken identity provider never locks you out.',
              )}
            </DialogDescription>
          </DialogHeader>
          <div className="flex flex-col-reverse gap-2 border-t border-border bg-muted/30 px-6 py-4 sm:flex-row sm:justify-end">
            <Button
              ref={confirmCancel}
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
