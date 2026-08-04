import { Cookie } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import { getMarketingPageUrl } from '@/lib/marketing/urls'
import { useConsoleProfile } from '@/hooks/use-console-profile'
import { useT } from '@/lib/i18n/translate'

type CookieConsentBannerProps = {
  customizeOpen: boolean
  draftAnalytics: boolean
  reopening: boolean
  onAcceptAll: () => void
  onRejectNonEssential: () => void
  onSaveCustomPreferences: () => void
  onClose: () => void
  onCustomizeOpenChange: (open: boolean) => void
  onDraftAnalyticsChange: (enabled: boolean) => void
}

export function CookieConsentBanner({
  customizeOpen,
  draftAnalytics,
  reopening,
  onAcceptAll,
  onRejectNonEssential,
  onSaveCustomPreferences,
  onClose,
  onCustomizeOpenChange,
  onDraftAnalyticsChange,
}: CookieConsentBannerProps) {
  const t = useT()
  const { features } = useConsoleProfile()
  const cookiesPolicyHref = getMarketingPageUrl('/cookies', features.marketing)
  const showPreferences = customizeOpen || reopening

  return (
    <div
      className="fixed inset-x-0 bottom-0 z-[60] border-t border-border bg-background shadow-lg"
      role="dialog"
      aria-labelledby="cookie-consent-title"
      aria-describedby="cookie-consent-description"
    >
      <div className="mx-auto w-full max-w-3xl px-4 py-4 sm:px-6">
        <div className="flex items-start gap-3">
          <Cookie
            className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground"
            aria-hidden
          />
          <div className="min-w-0 flex-1">
            <h2
              id="cookie-consent-title"
              className="text-[15px] font-semibold text-foreground"
            >
              {showPreferences ? t('Cookie preferences') : t('We value your privacy')}
            </h2>
            <p
              id="cookie-consent-description"
              className="mt-2 text-[13px] leading-relaxed text-muted-foreground"
            >
              {showPreferences ? (
                <>
                  {t('Choose which optional cookies you allow. Read our')}{' '}
                  <a
                    href={cookiesPolicyHref}
                    className="link-unstyled font-medium text-foreground underline-offset-4 hover:underline"
                  >
                    {t('Cookies Policy')}
                  </a>
                  .
                </>
              ) : (
                <>
                  {t(
                    'We use essential cookies to keep you signed in, manage site access, and remember your preferences. With your permission, we also use analytics to understand how Appwrite is used and improve it. Read our', // pragma: allowlist secret
                  )}{' '}
                  <a
                    href={cookiesPolicyHref}
                    className="link-unstyled font-medium text-foreground underline-offset-4 hover:underline"
                  >
                    {t('Cookies Policy')}
                  </a>
                  .
                </>
              )}
            </p>
          </div>
        </div>

        {showPreferences ? (
          <div className="mt-4 space-y-2">
            <div className="flex items-start justify-between gap-4 rounded-lg border border-border px-4 py-3">
              <div className="min-w-0">
                <div className="flex flex-wrap items-center gap-2">
                  <Label className="text-[13px] font-medium text-foreground">
                    {t('Essential')}
                  </Label>
                  <Badge variant="info" className="text-[10px] shrink-0">
                    {t('Always active')}
                  </Badge>
                </div>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Required for sign-in, site access, security, and remembering your preferences.',
                  )}
                </p>
              </div>
            </div>

            <div className="flex items-start justify-between gap-4 rounded-lg border border-border px-4 py-3">
              <div className="min-w-0">
                <Label
                  htmlFor="cookie-consent-analytics"
                  className="text-[13px] font-medium text-foreground"
                >
                  {t('Analytics')}
                </Label>
                <p className="mt-1 text-[12px] leading-relaxed text-muted-foreground">
                  {t(
                    'Privacy-friendly usage analytics and error reporting to help us improve Appwrite.', // pragma: allowlist secret
                  )}
                </p>
              </div>
              <Switch
                id="cookie-consent-analytics"
                checked={draftAnalytics}
                onCheckedChange={onDraftAnalyticsChange}
                className="mt-0.5 shrink-0"
              />
            </div>
          </div>
        ) : null}

        <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          {showPreferences ? (
            <>
              {reopening ? (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={onClose}
                >
                  {t('Cancel')}
                </Button>
              ) : (
                <Button
                  variant="outline"
                  size="sm"
                  className="h-9 text-[13px]"
                  onClick={() => onCustomizeOpenChange(false)}
                >
                  {t('Back')}
                </Button>
              )}
              <Button
                size="sm"
                className="h-9 text-[13px]"
                onClick={onSaveCustomPreferences}
              >
                {t('Save preferences')}
              </Button>
            </>
          ) : (
            <>
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={onRejectNonEssential}
              >
                {t('Reject non-essential')}
              </Button>
              <Button
                variant="outline"
                size="sm"
                className="h-9 text-[13px]"
                onClick={() => onCustomizeOpenChange(true)}
              >
                {t('Customize')}
              </Button>
              <Button size="sm" className="h-9 text-[13px]" onClick={onAcceptAll}>
                {t('Accept all')}
              </Button>
            </>
          )}
        </div>
      </div>
    </div>
  )
}
