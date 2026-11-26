import { useEffect, useRef, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useOrganizationApp } from '@/lib/react-query/hooks'
import {
  trimOrEmpty,
  urlFieldError,
  useOrgAppUpdate,
} from '../_components/useOrgAppUpdate'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  if (!app || !orgId) return null

  return <LegalSettings orgId={orgId} app={app} />
}

function LegalSettings({ orgId, app }: { orgId: string; app: Models.App }) {
  const t = useT()
  const { submit, isUpdating } = useOrgAppUpdate(orgId, app)
  const [privacyPolicyUrl, setPrivacyPolicyUrl] = useState(
    app.privacyPolicyUrl ?? '',
  )
  const [termsUrl, setTermsUrl] = useState(app.termsUrl ?? '')
  const [dataDeletionUrl, setDataDeletionUrl] = useState(
    app.dataDeletionUrl ?? '',
  )

  const syncedAppRef = useRef(app)

  useEffect(() => {
    if (syncedAppRef.current.$id === app.$id) return
    syncedAppRef.current = app
    setPrivacyPolicyUrl(app.privacyPolicyUrl ?? '')
    setTermsUrl(app.termsUrl ?? '')
    setDataDeletionUrl(app.dataDeletionUrl ?? '')
  }, [app])

  const privacyPolicyUrlError = urlFieldError(privacyPolicyUrl)
  const termsUrlError = urlFieldError(termsUrl)
  const dataDeletionUrlError = urlFieldError(dataDeletionUrl)

  const legalDirty =
    trimOrEmpty(privacyPolicyUrl) !== (app.privacyPolicyUrl ?? '') ||
    trimOrEmpty(termsUrl) !== (app.termsUrl ?? '') ||
    trimOrEmpty(dataDeletionUrl) !== (app.dataDeletionUrl ?? '')

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">{t('Legal')}</h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('Policy links shown on the OAuth2 consent screen.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="space-y-2">
          <Label htmlFor="app-legal-privacy">{t('Privacy policy')}</Label>
          <Input
            id="app-legal-privacy"
            value={privacyPolicyUrl}
            aria-invalid={privacyPolicyUrlError !== null}
            onChange={(e) => setPrivacyPolicyUrl(e.target.value)}
            placeholder="https://example.com/privacy"
          />
          {privacyPolicyUrlError ? (
            <p className="text-[12px] text-destructive">
              {privacyPolicyUrlError}
            </p>
          ) : null}
        </div>
        <div className="space-y-2">
          <Label htmlFor="app-legal-terms">{t('Terms of service')}</Label>
          <Input
            id="app-legal-terms"
            value={termsUrl}
            aria-invalid={termsUrlError !== null}
            onChange={(e) => setTermsUrl(e.target.value)}
            placeholder="https://example.com/terms"
          />
          {termsUrlError ? (
            <p className="text-[12px] text-destructive">
              {termsUrlError}
            </p>
          ) : null}
        </div>
        <div className="space-y-2">
          <Label htmlFor="app-legal-deletion">{t('Data deletion')}</Label>
          <Input
            id="app-legal-deletion"
            value={dataDeletionUrl}
            aria-invalid={dataDeletionUrlError !== null}
            onChange={(e) => setDataDeletionUrl(e.target.value)}
            placeholder="https://example.com/delete-data"
          />
          {dataDeletionUrlError ? (
            <p className="text-[12px] text-destructive">
              {dataDeletionUrlError}
            </p>
          ) : null}
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={
            isUpdating ||
            !legalDirty ||
            [privacyPolicyUrlError, termsUrlError, dataDeletionUrlError].some(
              (error) => error !== null,
            )
          }
          onClick={() =>
            submit({
              privacyPolicyUrl: trimOrEmpty(privacyPolicyUrl),
              termsUrl: trimOrEmpty(termsUrl),
              dataDeletionUrl: trimOrEmpty(dataDeletionUrl),
            }).catch(() => {
              // toast handled in submit
            })
          }
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
