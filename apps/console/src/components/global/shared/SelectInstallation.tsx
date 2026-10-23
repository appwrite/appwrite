/**
 * Select Installation
 *
 * Opened by ConnectVcsLink. Lists the provider accounts the user already
 * connected in other projects, so a new project can reuse one instead of going
 * through the provider's installation settings again. Without any, it continues
 * straight to the provider.
 */

import { useEffect, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Loader2, Plus } from 'lucide-react'
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group'
import { reusableInstallationsQueryOptions } from '@/lib/react-query/hooks'
import {
  closeInstallationPicker,
  useInstallationPickerRequest,
} from '@/lib/vcs/installation-picker'
import { getVcsProvider, VcsIcon } from '@/lib/vcs/providers'
import { useT } from '@/lib/i18n/translate'
import { cn } from '@/lib/utils'

const NEW_ACCOUNT = 'new'

export function SelectInstallation() {
  const t = useT()
  const request = useInstallationPickerRequest()
  const [selected, setSelected] = useState('')
  const { data: installations, isError } = useQuery({
    ...reusableInstallationsQueryOptions(request?.projectId, request?.provider),
    enabled: !!request,
  })
  const provider = getVcsProvider(request?.provider)

  useEffect(() => {
    setSelected(installations?.[0]?.providerInstallationId ?? '')
  }, [request, installations])

  // Nothing to reuse (or no way to tell): behave exactly like the plain link.
  // Runs after a fetch, so a new tab would be blocked; stay in this one.
  useEffect(() => {
    if (!request) return
    if (isError || installations?.length === 0) {
      window.location.assign(request.href)
    }
  }, [request, installations, isError])

  const handleContinue = () => {
    if (!request) return
    if (selected === NEW_ACCOUNT) {
      if (request.target === '_blank') {
        window.open(request.href, '_blank', 'noreferrer')
        closeInstallationPicker()
      } else {
        window.location.assign(request.href)
      }
      return
    }
    closeInstallationPicker()
    toast.info(t('Using an existing installation is not available yet.'))
  }

  return (
    <Dialog
      open={!!request}
      onOpenChange={(open) => {
        if (!open) closeInstallationPicker()
      }}
    >
      <DialogContent className="sm:max-w-md p-0">
        <DialogHeader className="px-6 pt-6 pb-4 text-left">
          <DialogTitle>{t(`Connect ${provider.label}`)}</DialogTitle>
          <DialogDescription className="text-[13px] mt-2">
            {t(
              'Use an account you already connected in another project, or add a new one.',
            )}
          </DialogDescription>
        </DialogHeader>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          {installations?.length ? (
            <RadioGroup
              value={selected}
              onValueChange={setSelected}
              className="max-h-80 overflow-y-auto gap-2"
            >
              {installations.map((installation) => {
                const id = installation.providerInstallationId
                return (
                  <Label
                    key={id}
                    htmlFor={`installation-${id}`}
                    className={cn(
                      'flex items-center gap-3 rounded-lg border px-3 py-2.5 cursor-pointer transition-all',
                      selected === id
                        ? 'border-foreground bg-card/80'
                        : 'border-border bg-card/50 hover:border-border/80',
                    )}
                  >
                    <RadioGroupItem
                      value={id}
                      id={`installation-${id}`}
                      className="shrink-0"
                    />
                    <VcsIcon
                      type={installation.provider}
                      className="h-4 w-4 shrink-0 text-muted-foreground"
                    />
                    <div className="min-w-0">
                      <p className="text-[13px] font-medium text-foreground truncate">
                        {installation.organization}
                      </p>
                      <p className="text-[12px] font-normal text-muted-foreground truncate">
                        {installation.projects.map((p) => p.name).join(', ')}
                      </p>
                    </div>
                  </Label>
                )
              })}
              <Label
                htmlFor="installation-new"
                className={cn(
                  'flex items-center gap-3 rounded-lg border px-3 py-2.5 cursor-pointer transition-all',
                  selected === NEW_ACCOUNT
                    ? 'border-foreground bg-card/80'
                    : 'border-border bg-card/50 hover:border-border/80',
                )}
              >
                <RadioGroupItem
                  value={NEW_ACCOUNT}
                  id="installation-new"
                  className="shrink-0"
                />
                <Plus className="h-4 w-4 shrink-0 text-muted-foreground" />
                <span className="text-[13px] font-medium text-foreground">
                  {t('Add a new account')}
                </span>
              </Label>
            </RadioGroup>
          ) : (
            <div className="flex items-center gap-2 text-[13px] text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              {t('Checking your existing installations...')}
            </div>
          )}
        </div>
        <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
          <Button variant="outline" onClick={closeInstallationPicker}>
            {t('Cancel')}
          </Button>
          <Button disabled={!installations?.length} onClick={handleContinue}>
            {t('Continue')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  )
}
