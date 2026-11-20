import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { useParams } from '@tanstack/react-router'
import { toast } from 'sonner'
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
        <div className="flex items-start justify-between gap-6 px-6 py-4">
          <div>
            <Label
              htmlFor="sso-required"
              className="text-[15px] font-semibold text-foreground"
            >
              {t('Require SSO')}
            </Label>
            <p className="mt-2 text-[13px] text-muted-foreground">
              {t(
                'Members with an email on your verified domains can open this organization only after signing in through your identity provider. Owners and members on other domains keep their usual sign-in.',
              )}
            </p>
            {!required && hint ? (
              <p className="mt-2 text-[13px] text-muted-foreground">{hint}</p>
            ) : null}
          </div>
          <Switch
            id="sso-required"
            checked={required}
            onCheckedChange={(value) =>
              value ? setConfirming(true) : save(false)
            }
            disabled={update.isPending || (!required && hint !== null)}
          />
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
