import { useEffect, useState } from 'react'
import { useParams } from '@tanstack/react-router'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { InputTags } from '@/components/ui/input-tags'
import { useOrganizationApp } from '@/lib/react-query/hooks'
import {
  nonEmptyList,
  useOrgAppUpdate,
} from '../_components/useOrgAppUpdate'

export function View() {
  const { orgId, appId } = useParams({ strict: false })
  const { app } = useOrganizationApp(appId)
  if (!app || !orgId) return null

  const { submit, isUpdating } = useOrgAppUpdate(orgId, app)
  const [clientType, setClientType] = useState(app.type || 'confidential')
  const [deviceFlow, setDeviceFlow] = useState(app.deviceFlow ?? false)
  const [redirectUris, setRedirectUris] = useState(app.redirectUris ?? [])
  const [postLogoutRedirectUris, setPostLogoutRedirectUris] = useState(
    app.postLogoutRedirectUris ?? [],
  )

  useEffect(() => {
    setClientType(app.type || 'confidential')
    setDeviceFlow(app.deviceFlow ?? false)
    setRedirectUris(app.redirectUris ?? [])
    setPostLogoutRedirectUris(app.postLogoutRedirectUris ?? [])
  }, [app])

  const handleUpdate = async () => {
    const uris = nonEmptyList(redirectUris)
    if (uris.length === 0) return
    await submit({
      type: clientType,
      deviceFlow,
      redirectUris: uris,
      postLogoutRedirectUris: nonEmptyList(postLogoutRedirectUris),
    })
  }

  return (
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            OAuth client
          </h3>
          <p className="text-[13px] text-muted-foreground mt-2">
            Redirect URIs and client type for OAuth2 and OpenID Connect.
          </p>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4 space-y-4">
          <div className="space-y-2">
            <Label>Client type</Label>
            <Select value={clientType} onValueChange={setClientType}>
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="confidential">Confidential</SelectItem>
                <SelectItem value="public">Public</SelectItem>
              </SelectContent>
            </Select>
            <p className="text-[12px] text-muted-foreground">
              Public clients require PKCE. Confidential clients use a client
              secret.
            </p>
          </div>
          <div className="flex items-center justify-between gap-4">
            <div>
              <Label htmlFor="app-oauth-device-flow">Device flow</Label>
              <p className="text-[12px] text-muted-foreground mt-1">
                Allow OAuth2 Device Authorization Grant (RFC 8628).
              </p>
            </div>
            <Switch
              id="app-oauth-device-flow"
              checked={deviceFlow}
              onCheckedChange={setDeviceFlow}
            />
          </div>
          <div className="space-y-2">
            <Label>Redirect URIs</Label>
            <InputTags
              value={redirectUris}
              onChange={setRedirectUris}
              placeholder="Add redirect URI and press Enter"
            />
          </div>
          <div className="space-y-2">
            <Label>Post-logout redirect URIs</Label>
            <InputTags
              value={postLogoutRedirectUris}
              onChange={setPostLogoutRedirectUris}
              placeholder="Add post-logout URI and press Enter"
            />
          </div>
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30">
          <Button
            size="sm"
            className="h-9 text-[13px]"
            disabled={isUpdating || nonEmptyList(redirectUris).length === 0}
            onClick={handleUpdate}
          >
            Update
          </Button>
        </div>
      </div>
  )
}
