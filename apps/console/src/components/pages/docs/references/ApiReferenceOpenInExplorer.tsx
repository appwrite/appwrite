'use client'

import { useEffect, useMemo, useState } from 'react'
import { Link, useLocation, useNavigate } from '@tanstack/react-router'
import { ExternalLink } from 'lucide-react'
import { useAuth } from '@/components/global/auth/RequireAuth'
import { OrganizationSelector } from '@/components/global/shared/OrganizationSelector'
import { ProjectSelector } from '@/components/global/shared/ProjectSelector'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { getExplorerMethodRoute } from '@/lib/api-explorer/get-explorer-method-route'
import { useTeams } from '@/lib/react-query/hooks'
import { cn } from '@/lib/utils'

type ApiReferenceOpenInExplorerProps = {
  serviceId: string
  operationId: string
  className?: string
}

export function ApiReferenceOpenInExplorer({
  serviceId,
  operationId,
  className,
}: ApiReferenceOpenInExplorerProps) {
  const [open, setOpen] = useState(false)
  const [selectedOrgId, setSelectedOrgId] = useState<string | null>(null)
  const location = useLocation()
  const navigate = useNavigate()
  const { isAuthenticated, isLoading: authLoading } = useAuth()
  const { organizations, isLoading: orgsLoading } = useTeams()

  const redirectPath = useMemo(
    () => `${location.pathname}${location.hash}`,
    [location.hash, location.pathname],
  )

  useEffect(() => {
    if (!open) {
      setSelectedOrgId(null)
      return
    }
    if (selectedOrgId || organizations.length !== 1) return
    setSelectedOrgId(organizations[0]?.$id ?? null)
  }, [open, organizations, selectedOrgId])

  const handleSelectProject = (projectId: string) => {
    navigate(
      getExplorerMethodRoute({
        projectId,
        serviceId,
        operationId,
      }),
    )
    setOpen(false)
  }

  return (
    <>
      <Button
        type="button"
        variant="outline"
        size="sm"
        className={cn('h-7 shrink-0 px-2.5 text-[12px]', className)}
        onClick={() => setOpen(true)}
      >
        Open in explorer
        <ExternalLink className="ms-1.5 h-3.5 w-3.5" />
      </Button>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="sm:max-w-md p-0">
          <DialogHeader className="px-6 pt-6 pb-4 text-start">
            <DialogTitle>Open in explorer</DialogTitle>
            <DialogDescription className="text-[13px] mt-2">
              Try this endpoint in your project with the live API explorer.
            </DialogDescription>
          </DialogHeader>

          <div className="border-t border-border" />

          <div className="px-6 pb-4 pt-4">
            {authLoading ? (
              <p className="text-[13px] text-muted-foreground">Loading…</p>
            ) : !isAuthenticated ? (
              <p className="text-[13px] text-muted-foreground">
                Sign in to choose a project and open this method in the
                explorer.
              </p>
            ) : orgsLoading ? (
              <p className="text-[13px] text-muted-foreground">Loading…</p>
            ) : organizations.length === 0 ? (
              <p className="text-[13px] text-muted-foreground">
                Create an organization and project to use the API explorer.
              </p>
            ) : (
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label className="text-[12px] text-muted-foreground">
                    Organization
                  </Label>
                  <OrganizationSelector
                    value={selectedOrgId}
                    onValueChange={setSelectedOrgId}
                    organizations={organizations}
                    isLoading={orgsLoading}
                    triggerClassName="w-full"
                  />
                </div>

                <div className="space-y-2">
                  <Label className="text-[12px] text-muted-foreground">
                    Project
                  </Label>
                  <ProjectSelector
                    orgTeamId={selectedOrgId}
                    placeholder={
                      selectedOrgId ? 'Select project' : 'Select organization first'
                    }
                    onSelectProject={handleSelectProject}
                    triggerClassName="w-full"
                  />
                </div>
              </div>
            )}
          </div>

          {!authLoading && !isAuthenticated ? (
            <div className="px-6 py-4 border-t border-border bg-muted/30 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                <Button variant="outline" asChild>
                  <Link to="/sign-in" search={{ redirect: redirectPath }}>
                    Sign in
                  </Link>
                </Button>
                <Button asChild>
                  <Link to="/sign-up" search={{ redirect: redirectPath }}>
                    Sign up
                  </Link>
                </Button>
              </div>
          ) : null}
        </DialogContent>
      </Dialog>
    </>
  )
}
