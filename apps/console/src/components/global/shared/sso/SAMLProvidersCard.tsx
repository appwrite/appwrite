import { Check, KeyRound } from 'lucide-react'
import { toast } from 'sonner'
import type { Models } from '@appwrite.io/console'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { EmptyState } from '@/components/global/shared/EmptyState'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { analyticsAttrs } from '@/lib/analytics-actions'
import { useT } from '@/lib/i18n/translate'
import { useToggleFeatureNotification } from '@/lib/react-query/hooks'
import { parseFeatureNotificationIds } from '@/lib/user-prefs-keys'
import { getErrorMessage } from '@/lib/utils/error-formatting'

/**
 * Feature ID stored in the account's `featureNotifications` pref when an owner
 * asks to hear about SAML. Must stay free of commas, since that pref is a
 * comma-separated list.
 */
export const SSO_SAML_INTEREST_FEATURE_ID = 'sso-saml'

/**
 * SAML identity providers are not built yet. The card sits next to the OIDC one
 * so owners see SAML is planned, and collects interest the way coming-soon SMTP
 * providers do: one button that reads back the registered state, and a second
 * click removes it.
 */
export function SAMLProvidersCard() {
  const t = useT()
  const { account } = useAuth()
  const toggleInterest = useToggleFeatureNotification()
  const interested = parseFeatureNotificationIds(
    (account as Models.User<Models.Preferences> | null | undefined)?.prefs,
  ).includes(SSO_SAML_INTEREST_FEATURE_ID)

  const handleToggleInterest = async () => {
    try {
      await toggleInterest.mutateAsync(SSO_SAML_INTEREST_FEATURE_ID)
      toast.success(
        interested
          ? t("You'll no longer be notified about SAML.")
          : t("You'll be notified when SAML is available."),
      )
    } catch (error) {
      toast.error(
        getErrorMessage(error, t('Failed to update notification preferences')),
      )
    }
  }

  return (
    <div className="overflow-hidden rounded-xl border border-border bg-card/50">
      <div className="px-6 py-4">
        <h3 className="flex items-center gap-2 text-[15px] font-semibold text-foreground">
          {t('Identity providers')}
          <Badge variant="outline">SAML</Badge>
          <Badge variant="info" className="shrink-0 text-[10px]">
            {t('Coming soon')}
          </Badge>
        </h3>
        <p className="mt-2 text-[13px] text-muted-foreground">
          {t(
            'Identity providers that sign people in with SAML 2.0, such as Okta or Microsoft Entra ID.',
          )}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4">
        <EmptyState
          icon={KeyRound}
          title={t('SAML is coming soon')}
          description={t(
            'Get notified when SAML identity providers can be connected.',
          )}
          isEmpty
          variant="default"
          className="py-2"
          action={
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 text-[12px]"
              onClick={() => void handleToggleInterest()}
              disabled={toggleInterest.isPending}
              aria-pressed={interested}
              {...analyticsAttrs('notify-sso-saml')}
            >
              {interested ? (
                <>
                  <Check className="h-3.5 w-3.5" />
                  {t('You will be notified')}
                </>
              ) : (
                t('Notify me')
              )}
            </Button>
          }
        />
      </div>
    </div>
  )
}
