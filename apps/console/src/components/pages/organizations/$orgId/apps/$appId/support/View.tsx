import { useEffect, useRef, useState } from 'react'
import type { Models } from '@appwrite.io/console'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { InputTags } from '@/components/ui/input-tags'
import { useOrganizationApp } from '@/lib/react-query/hooks'
import {
  listsEqual,
  nonEmptyList,
  trimOrEmpty,
  urlFieldError,
  useOrgAppUpdate,
} from '../_components/useOrgAppUpdate'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  if (!app || !orgId) return null

  return <SupportSettings orgId={orgId} app={app} />
}

function SupportSettings({ orgId, app }: { orgId: string; app: Models.App }) {
  const t = useT()
  const { submit, isUpdating } = useOrgAppUpdate(orgId, app)
  const [supportUrl, setSupportUrl] = useState(app.supportUrl ?? '')
  const [contacts, setContacts] = useState(app.contacts ?? [])
  // An email typed but not yet added still counts as an edit; leaving the
  // field (for example by clicking Update) adds it.
  const [pendingContact, setPendingContact] = useState('')

  const syncedAppRef = useRef(app)

  useEffect(() => {
    if (syncedAppRef.current.$id === app.$id) return
    syncedAppRef.current = app
    setSupportUrl(app.supportUrl ?? '')
    setContacts(app.contacts ?? [])
  }, [app])

  const supportDirty =
    trimOrEmpty(supportUrl) !== (app.supportUrl ?? '') ||
    !listsEqual(nonEmptyList(contacts), app.contacts ?? []) ||
    pendingContact !== ''

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
            aria-invalid={urlFieldError(supportUrl) !== null}
            onChange={(e) => setSupportUrl(e.target.value)}
            placeholder="https://example.com/support"
          />
          {urlFieldError(supportUrl) ? (
            <p className="text-[12px] text-destructive">
              {t(urlFieldError(supportUrl)!)}
            </p>
          ) : null}
        </div>
        <div className="space-y-2">
          <Label>{t('Contact emails')}</Label>
          <InputTags
            value={contacts}
            onChange={setContacts}
            onPendingChange={setPendingContact}
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
          disabled={
            isUpdating || !supportDirty || urlFieldError(supportUrl) !== null
          }
          onClick={() =>
            submit({
              supportUrl: trimOrEmpty(supportUrl),
              contacts: nonEmptyList(contacts),
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
