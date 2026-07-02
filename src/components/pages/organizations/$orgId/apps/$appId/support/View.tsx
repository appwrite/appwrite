import { useEffect, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { InputTags } from '@/components/ui/input-tags'
import { useOrganizationApp } from '@/lib/react-query/hooks'
import {
  nonEmptyList,
  trimOrEmpty,
  useOrgAppUpdate,
} from '../_components/useOrgAppUpdate'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const t = useT()
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  if (!app || !orgId) return null

  const { submit, isUpdating } = useOrgAppUpdate(orgId, app)
  const [supportUrl, setSupportUrl] = useState(app.supportUrl ?? '')
  const [contacts, setContacts] = useState(app.contacts ?? [])

  useEffect(() => {
    setSupportUrl(app.supportUrl ?? '')
    setContacts(app.contacts ?? [])
  }, [app])

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="px-6 py-4">
        <h3 className="text-[15px] font-semibold text-foreground">{t('Support')}</h3>
        <p className="text-[13px] text-muted-foreground mt-2">
          {t('How users can get help with your app during OAuth2 consent.')}
        </p>
      </div>
      <div className="border-t border-border" />
      <div className="px-6 py-4 space-y-4">
        <div className="space-y-2">
          <Label htmlFor="app-support-url">{t('Support page')}</Label>
          <Input
            id="app-support-url"
            value={supportUrl}
            onChange={(e) => setSupportUrl(e.target.value)}
            placeholder="https://example.com/support"
          />
        </div>
        <div className="space-y-2">
          <Label>{t('Contact emails')}</Label>
          <InputTags
            value={contacts}
            onChange={setContacts}
            validateEmail
            splitOnComma
            placeholder={t('Add email and press Enter')}
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
              supportUrl: trimOrEmpty(supportUrl),
              contacts: nonEmptyList(contacts),
            })
          }
        >
          {t('Update')}
        </Button>
      </div>
    </div>
  )
}
