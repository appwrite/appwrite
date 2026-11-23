/**
 * Fills the Supabase credentials from a connected Supabase account, so the user
 * does not have to copy keys and a database password out of Supabase.
 */

import { useState } from 'react'
import { AppwriteException, type Models } from '@appwrite.io/console'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useT } from '@/lib/i18n/translate'
import { getApiEndpoint } from '@/lib/appwrite/sdk'
import {
  useCreateSupabaseProjectCredentials,
  useDeleteSupabaseOAuth2,
  useSupabaseProjects,
} from '@/lib/react-query/hooks'

/** Query parameter the connect flow returns with, so the wizard reopens on Supabase. */
export const SUPABASE_CONNECT_PARAM = 'supabase'

function returnUrl(outcome: 'connected' | 'failed'): string {
  const url = new URL(window.location.href)
  url.searchParams.set(SUPABASE_CONNECT_PARAM, outcome)
  return url.toString()
}

export function SupabaseConnect({
  projectId,
  region,
  onCredentials,
}: {
  projectId: string
  region?: string
  onCredentials: (credentials: Models.SupabaseCredentials) => void
}) {
  const t = useT()
  const [projectRef, setProjectRef] = useState('')
  const projects = useSupabaseProjects(projectId, region)
  const createCredentials = useCreateSupabaseProjectCredentials(
    projectId,
    region,
  )
  const disconnect = useDeleteSupabaseOAuth2(projectId, region)

  const errorType =
    projects.error instanceof AppwriteException ? projects.error.type : ''

  // The server has no Supabase OAuth app configured, so only manual credentials work
  if (errorType === 'general_not_implemented') {
    return null
  }

  const connect = () => {
    const url = new URL(`${getApiEndpoint()}/migrations/supabase/oauth2`)
    url.searchParams.set('success', returnUrl('connected'))
    url.searchParams.set('failure', returnUrl('failed'))
    window.location.assign(url.toString())
  }

  const fill = async () => {
    try {
      onCredentials(await createCredentials.mutateAsync(projectRef))
      toast.success(t('Credentials filled from your Supabase project'))
    } catch (error) {
      toast.error(
        error instanceof Error
          ? error.message
          : t('Unable to read the Supabase project'),
      )
    }
  }

  const connected = projects.data !== undefined

  return (
    <div className="px-6 py-4 space-y-3">
      {!connected && (
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-[12px] text-muted-foreground">
            {t(
              'Connect your Supabase account to fill the credentials automatically.',
            )}
          </p>
          <Button
            size="sm"
            onClick={connect}
            disabled={projects.isLoading}
          >
            {t('Connect Supabase')}
          </Button>
        </div>
      )}
      {connected && (
        <div className="space-y-3">
          <div className="flex flex-wrap items-center gap-3">
            <Select value={projectRef} onValueChange={setProjectRef}>
              <SelectTrigger className="h-9 min-w-56 text-[13px]">
                <SelectValue placeholder={t('Select a Supabase project')} />
              </SelectTrigger>
              <SelectContent>
                {projects.data.projects.map((project) => (
                  <SelectItem key={project.projectRef} value={project.projectRef}>
                    {project.name}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            <Button
              size="sm"
              onClick={fill}
              disabled={!projectRef || createCredentials.isPending}
            >
              {t('Use project')}
            </Button>
            <Button
              size="sm"
              variant="ghost"
              onClick={() => disconnect.mutate()}
              disabled={disconnect.isPending}
            >
              {t('Disconnect')}
            </Button>
          </div>
          <p className="text-[11px] text-muted-foreground">
            {t(
              'Appwrite creates a read only database role in the project that expires after 24 hours.',
            )}
          </p>
        </div>
      )}
    </div>
  )
}
