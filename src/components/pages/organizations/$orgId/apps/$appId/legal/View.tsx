import { useEffect, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { useOrganizationApp } from '@/lib/react-query/hooks'
import { trimOrEmpty, useOrgAppUpdate } from '../_components/useOrgAppUpdate'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  if (!app || !orgId) return null

  const { submit, isUpdating } = useOrgAppUpdate(orgId, app)
  const [privacyPolicyUrl, setPrivacyPolicyUrl] = useState(
    app.privacyPolicyUrl ?? '',
  )
  const [termsUrl, setTermsUrl] = useState(app.termsUrl ?? '')
  const [dataDeletionUrl, setDataDeletionUrl] = useState(
    app.dataDeletionUrl ?? '',
  )

  useEffect(() => {
    setPrivacyPolicyUrl(app.privacyPolicyUrl ?? '')
    setTermsUrl(app.termsUrl ?? '')
    setDataDeletionUrl(app.dataDeletionUrl ?? '')
  }, [app])

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
            onChange={(e) => setPrivacyPolicyUrl(e.target.value)}
            placeholder="https://example.com/privacy"
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="app-legal-terms">{t('Terms of service')}</Label>
          <Input
            id="app-legal-terms"
            value={termsUrl}
            onChange={(e) => setTermsUrl(e.target.value)}
            placeholder="https://example.com/terms"
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="app-legal-deletion">{t('Data deletion')}</Label>
          <Input
            id="app-legal-deletion"
            value={dataDeletionUrl}
            onChange={(e) => setDataDeletionUrl(e.target.value)}
            placeholder="https://example.com/delete-data"
          />
        </div>
      </div>
      <div className="px-6 py-4 border-t border-border bg-muted/30">
        <Button
          size="sm"
          className="h-9 text-[13px]"
          disabled={isUpdating}
          onClick={() =>
            submit({
              privacyPolicyUrl: trimOrEmpty(privacyPolicyUrl),
              termsUrl: trimOrEmpty(termsUrl),
              dataDeletionUrl: trimOrEmpty(dataDeletionUrl),
            })
          }
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
