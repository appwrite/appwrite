import { useEffect, useMemo, useRef, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import type { Models } from '@appwrite.io/console'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { InputTags } from '@/components/ui/input-tags'
import {
  OAUTH2_DEVICE_FLOW_DESCRIPTION,
  OAuth2ClientTypePicker,
} from '@/components/global/shared/OAuth2ClientTypePicker'
import { OAuth2ScopePicker } from '@/components/global/shared/OAuth2ScopePicker'
import {
  useConsoleInstallationScopes,
  useOrganizationApp,
} from '@/lib/react-query/hooks'
import { ClientIdField } from '../_components/ClientIdField'
import {
  listsEqual,
  nonEmptyList,
  setsEqual,
  useOrgAppUpdate,
} from '../_components/useOrgAppUpdate'
import { useT } from '@/lib/i18n/translate'

export function View() {
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  if (!app || !orgId) return null

  return <OAuthClientSettings orgId={orgId} app={app} />
}

type OAuthClientCard = 'oauth' | 'installations'

function OAuthClientSettings({
  orgId,
  app,
}: {
  orgId: string
  app: Models.App
}) {
  const t = useT()
  const { submit, isUpdating } = useOrgAppUpdate(orgId, app)
  const { scopes: installationScopeCatalog, isLoading: scopesLoading } =
    useConsoleInstallationScopes()

  const [clientType, setClientType] = useState(app.type || 'confidential')
  const [deviceFlow, setDeviceFlow] = useState(app.deviceFlow ?? false)
  const [redirectUris, setRedirectUris] = useState(app.redirectUris ?? [])
  const [postLogoutRedirectUris, setPostLogoutRedirectUris] = useState(
    app.postLogoutRedirectUris ?? [],
  )
  const [installationScopes, setInstallationScopes] = useState(
    app.installationScopes ?? [],
  )
  const [installationRedirectUrl, setInstallationRedirectUrl] = useState(
    app.installationRedirectUrl ?? '',
  )

  // After a card is saved only its fields are re-read from the server, so the
  // other card keeps its unsaved edits across the refetch.
  const savedCardRef = useRef<OAuthClientCard | null>(null)
  const syncedAppRef = useRef(app)

  useEffect(() => {
    if (syncedAppRef.current === app) return
    syncedAppRef.current = app
    const savedCard = savedCardRef.current
    savedCardRef.current = null
    if (savedCard !== 'installations') {
      setClientType(app.type || 'confidential')
      setDeviceFlow(app.deviceFlow ?? false)
      setRedirectUris(app.redirectUris ?? [])
      setPostLogoutRedirectUris(app.postLogoutRedirectUris ?? [])
    }
    if (savedCard !== 'oauth') {
      setInstallationScopes(app.installationScopes ?? [])
      setInstallationRedirectUrl(app.installationRedirectUrl ?? '')
    }
  }, [app])

  const installationScopeOptions = useMemo(
    () =>
      installationScopeCatalog.map((scope) => ({
        value: scope.value,
        description: scope.description,
        category: scope.category,
        deprecated: scope.deprecated,
      })),
    [installationScopeCatalog],
  )

  const oauthDirty =
    clientType !== (app.type || 'confidential') ||
    deviceFlow !== (app.deviceFlow ?? false) ||
    !listsEqual(nonEmptyList(redirectUris), app.redirectUris ?? []) ||
    !listsEqual(
      nonEmptyList(postLogoutRedirectUris),
      app.postLogoutRedirectUris ?? [],
    )

  const installationsDirty =
    !setsEqual(installationScopes, app.installationScopes ?? []) ||
    installationRedirectUrl.trim() !== (app.installationRedirectUrl ?? '')

  const handleUpdate = async () => {
    savedCardRef.current = 'oauth'
    try {
      await submit({
        type: clientType,
        deviceFlow,
        redirectUris: nonEmptyList(redirectUris),
        postLogoutRedirectUris: nonEmptyList(postLogoutRedirectUris),
      })
    } catch {
      // submit() already reported the error; nothing was saved.
      savedCardRef.current = null
    }
  }

  const handleUpdateInstallations = async () => {
    savedCardRef.current = 'installations'
    try {
      await submit(
        {
          installationScopes,
          installationRedirectUrl: installationRedirectUrl.trim(),
        },
        { successMessage: t('Installation settings updated') },
      )
    } catch {
      savedCardRef.current = null
    }
  }

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('OAuth client')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t('Redirect URIs and client type for OAuth2 and OpenID Connect.')}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <ClientIdField clientId={app.$id} />
          <OAuth2ClientTypePicker
            value={clientType}
            onChange={setClientType}
            disabled={isUpdating}
          />
          <div className="flex items-center justify-between gap-4">
            <div>
              <Label htmlFor="app-oauth-device-flow">{t('Device flow')}</Label>
              <p className="text-[12px] text-muted-foreground mt-1">
                {t(OAUTH2_DEVICE_FLOW_DESCRIPTION)}
              </p>
            </div>
            <Switch
              id="app-oauth-device-flow"
              checked={deviceFlow}
              disabled={isUpdating}
              onCheckedChange={setDeviceFlow}
            />
          </div>
          <div className="space-y-2">
            <Label>{t('Redirect URIs')}</Label>
            <InputTags
              value={redirectUris}
              onChange={setRedirectUris}
              placeholder={t('Add redirect URI and press Enter')}
            />
          </div>
          <div className="space-y-2">
            <Label>{t('Post-logout redirect URIs')}</Label>
            <InputTags
              value={postLogoutRedirectUris}
              onChange={setPostLogoutRedirectUris}
              placeholder={t('Add post-logout URI and press Enter')}
            />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={isUpdating || !oauthDirty}
            onClick={handleUpdate}
          >
            {t('Update')}
          </Button>
        </div>
      </div>

      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            {t('App installations')}
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            {t(
              'Organizations that install this app grant it the scopes below. Users return to the redirect URL after installing or updating the installation.',
            )}
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <div className="space-y-2">
            <Label>{t('Installation scopes')}</Label>
            <OAuth2ScopePicker
              idPrefix="app-installation-scope"
              options={installationScopeOptions}
              value={installationScopes}
              onChange={setInstallationScopes}
              disabled={isUpdating}
              emptyMessage={
                scopesLoading
                  ? t('Loading scopes...')
                  : t('No installation scopes are available.')
              }
            />
          </div>
          <div className="space-y-2">
            <Label htmlFor="app-installation-redirect-url">
              {t('Installation redirect URL')}
            </Label>
            <Input
              id="app-installation-redirect-url"
              value={installationRedirectUrl}
              onChange={(event) =>
                setInstallationRedirectUrl(event.target.value)
              }
              placeholder="https://example.com/installed"
              className="h-9 text-[13px]"
              disabled={isUpdating}
            />
            <p className="text-[12px] text-muted-foreground">
              {t(
                'Optional. Users land here after creating or updating an installation.',
              )}
            </p>
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={isUpdating || !installationsDirty}
            onClick={handleUpdateInstallations}
          >
            {t('Update')}
          </Button>
        </div>
      </div>
    </div>
  )
}
