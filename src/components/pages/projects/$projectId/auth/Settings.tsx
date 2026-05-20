import { useState, useEffect, useMemo, useRef } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
import { ProjectAuthMethodId } from '@appwrite.io/console'
import {
  projectQueryOptions,
  useUpdateAuthMethod,
} from '@/lib/react-query/hooks'
import { authMethodsRecordFromProject } from '@/lib/project-settings'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Loader2, Mail, Key, Smartphone, UserPlus, Lock } from 'lucide-react'
import { cn } from '@/lib/utils'
import { OAuth2ProvidersSection } from './_components/OAuth2ProvidersSection'
import type { AuthOAuth2SettingsInitialData } from '@/lib/react-query/hooks/oauth2-providers'

interface AuthSettingsProps {
  projectId: string
  initialData?: AuthOAuth2SettingsInitialData
}

const AUTH_METHODS = [
  {
    key: ProjectAuthMethodId.Emailpassword,
    label: 'Email/Password',
    icon: Mail,
  },
  {
    key: ProjectAuthMethodId.Phone,
    label: 'Phone',
    icon: Smartphone,
  },
  {
    key: ProjectAuthMethodId.Magicurl,
    label: 'Magic URL',
    icon: Key,
  },
  {
    key: ProjectAuthMethodId.Emailotp,
    label: 'Email OTP',
    icon: Mail,
  },
  {
    key: ProjectAuthMethodId.Anonymous,
    label: 'Anonymous',
    icon: UserPlus,
  },
  {
    key: ProjectAuthMethodId.Invites,
    label: 'Team Invites',
    icon: UserPlus,
  },
  {
    key: ProjectAuthMethodId.Jwt,
    label: 'JWT',
    icon: Lock,
  },
] as const

export function AuthSettings({ projectId, initialData }: AuthSettingsProps) {
  const queryClient = useQueryClient()
  const { data: projectData } = useQuery(projectQueryOptions(projectId))

  const [optimisticAuthMethods, setOptimisticAuthMethods] = useState<
    Record<string, boolean>
  >({})
  const [updatingAuthMethods, setUpdatingAuthMethods] = useState<Set<string>>(
    new Set(),
  )
  const lastSubmittedAuthMethods = useRef<Record<string, boolean>>({})

  const baseAuthMethods = useMemo(
    () => authMethodsRecordFromProject(projectData),
    [projectData],
  )

  useEffect(() => {
    Object.keys(lastSubmittedAuthMethods.current).forEach((method) => {
      const expectedValue = lastSubmittedAuthMethods.current[method]
      const serverValue =
        baseAuthMethods[method as keyof typeof baseAuthMethods]

      if (serverValue === expectedValue) {
        setOptimisticAuthMethods((prev) => {
          const next = { ...prev }
          delete next[method]
          return next
        })
        setUpdatingAuthMethods((prev) => {
          const next = new Set(prev)
          next.delete(method)
          return next
        })
        delete lastSubmittedAuthMethods.current[method]
      }
    })
  }, [baseAuthMethods])

  const authMethods = useMemo(() => {
    return { ...baseAuthMethods, ...optimisticAuthMethods }
  }, [baseAuthMethods, optimisticAuthMethods])

  const updateAuthMethodMutation = useUpdateAuthMethod(projectId)

  const handleAuthMethodToggle = (method: string, checked: boolean) => {
    setOptimisticAuthMethods((prev) => ({ ...prev, [method]: checked }))
    setUpdatingAuthMethods((prev) => new Set(prev).add(method))
    lastSubmittedAuthMethods.current[method] = checked

    updateAuthMethodMutation.mutate(
      { method, status: checked },
      {
        onSuccess: () => {
          const methodLabel =
            AUTH_METHODS.find((m) => m.key === method)?.label || method
          toast.success(`${methodLabel} authentication has been updated`)
          queryClient.invalidateQueries({ queryKey: ['project', projectId] })
        },
        onError: (error: Error) => {
          toast.error(error.message || 'Failed to update authentication method')
          setOptimisticAuthMethods((prev) => {
            const next = { ...prev }
            delete next[method]
            return next
          })
          setUpdatingAuthMethods((prev) => {
            const next = new Set(prev)
            next.delete(method)
            return next
          })
          delete lastSubmittedAuthMethods.current[method]
        },
      },
    )
  }

  return (
    <div className="space-y-6">
      <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
        <div className="px-6 py-4">
          <h3 className="text-[15px] font-semibold text-foreground">
            Auth methods
          </h3>
        </div>
        <div className="border-t border-border" />
        <div className="px-6 py-4">
          <p className="text-[13px] text-muted-foreground mb-4">
            Enable the authentication methods you wish to use.
          </p>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {AUTH_METHODS.map((method) => {
              const Icon = method.icon
              const isUpdating = updatingAuthMethods.has(method.key)
              const enabled = authMethods[method.key] ?? false

              return (
                <div
                  key={method.key}
                  className={cn(
                    'rounded-lg border border-border bg-card/50 p-4 transition-colors',
                    isUpdating && 'opacity-75',
                    !isUpdating && 'hover:bg-card',
                  )}
                >
                  <div className="flex items-center justify-between">
                    <div className="flex items-center gap-2">
                      <Icon className="h-4 w-4 text-muted-foreground" />
                      <Label
                        htmlFor={method.key}
                        className="text-[13px] font-medium text-foreground cursor-pointer"
                      >
                        {method.label}
                      </Label>
                    </div>
                    <div className="flex items-center gap-2">
                      {isUpdating && (
                        <Loader2 className="h-3.5 w-3.5 animate-spin text-muted-foreground" />
                      )}
                      <Switch
                        id={method.key}
                        checked={enabled}
                        onCheckedChange={(checked) =>
                          handleAuthMethodToggle(method.key, checked)
                        }
                        disabled={isUpdating}
                      />
                    </div>
                  </div>
                </div>
              )
            })}
          </div>
        </div>
      </div>

      <OAuth2ProvidersSection projectId={projectId} initialData={initialData} />
    </div>
  )
}
