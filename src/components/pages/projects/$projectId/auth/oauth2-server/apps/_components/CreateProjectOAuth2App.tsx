import { useEffect, useState } from 'react'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { InputTags } from '@/components/ui/input-tags'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import type { CreateProjectOAuth2AppInput } from '@/lib/react-query/hooks/project-oauth2-apps'

interface CreateProjectOAuth2AppProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  onCreate: (input: CreateProjectOAuth2AppInput) => void | Promise<void>
  isSubmitting?: boolean
}

export function CreateProjectOAuth2App({
  open,
  onOpenChange,
  onCreate,
  isSubmitting = false,
}: CreateProjectOAuth2AppProps) {
  const [name, setName] = useState('')
  const [appId, setAppId] = useState('')
  const [redirectUris, setRedirectUris] = useState<string[]>([])
  const [clientType, setClientType] = useState('confidential')
  const [deviceFlow, setDeviceFlow] = useState(false)

  useEffect(() => {
    if (!open) {
      setName('')
      setAppId('')
      setRedirectUris([])
      setClientType('confidential')
      setDeviceFlow(false)
    }
  }, [open])

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault()
    if (!name.trim() || redirectUris.length === 0 || isSubmitting) return
    await onCreate({
      name: name.trim(),
      appId: appId.trim() || undefined,
      redirectUris,
      type: clientType,
      deviceFlow,
    })
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md p-0">
        <form onSubmit={handleSubmit}>
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>Create OAuth2 app</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Register a client that can authenticate users through this
              project&apos;s OAuth2 server.
            </DialogDescription>
          </DialogHeader>
          <div className="border-t border-border" />
          <div className="px-6 py-4 space-y-4">
            <div className="space-y-2">
              <Label htmlFor="oauth2-app-name" className="text-[13px]">
                Name
              </Label>
              <Input
                id="oauth2-app-name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="My application"
                className="h-9 text-[13px]"
                disabled={isSubmitting}
              />
            </div>
            <div className="space-y-2">
              <Label htmlFor="oauth2-app-id" className="text-[13px]">
                App ID
              </Label>
              <Input
                id="oauth2-app-id"
                value={appId}
                onChange={(event) => setAppId(event.target.value)}
                placeholder="Optional custom ID"
                className="h-9 font-mono text-[13px]"
                disabled={isSubmitting}
              />
            </div>
            <div className="space-y-2">
              <Label className="text-[13px]">Redirect URIs</Label>
              <InputTags
                value={redirectUris}
                onChange={setRedirectUris}
                splitOnComma
                placeholder="https://example.com/callback"
                disabled={isSubmitting}
              />
            </div>
            <div className="space-y-2">
              <Label className="text-[13px]">Client type</Label>
              <Select value={clientType} onValueChange={setClientType}>
                <SelectTrigger className="h-9 text-[13px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="confidential">Confidential</SelectItem>
                  <SelectItem value="public">Public</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="flex items-center justify-between gap-4">
              <div>
                <Label htmlFor="oauth2-app-device-flow" className="text-[13px]">
                  Device flow
                </Label>
                <p className="mt-0.5 text-[12px] text-muted-foreground">
                  Allow RFC 8628 device authorization for TVs and CLIs.
                </p>
              </div>
              <Switch
                id="oauth2-app-device-flow"
                checked={deviceFlow}
                disabled={isSubmitting}
                onCheckedChange={setDeviceFlow}
              />
            </div>
          </div>
          <div className="border-t border-border px-6 py-4 bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => onOpenChange(false)}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={
                isSubmitting || !name.trim() || redirectUris.length === 0
              }
            >
              Create
            </Button>
          </div>
        </form>
      </DialogContent>
    </Dialog>
  )
}
