import { useMemo, useState } from 'react'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { Label } from '@/components/ui/label'
import { CopyableId } from '@/components/global/shared/CopyableId'
import { SearchableSelect } from '@/components/global/shared/SearchableSelect'
import { useProjectUsers } from '@/lib/react-query/hooks'
import {
  methodRequiresApiKey,
  methodRequiresSessionAuthChoice,
  type ApiExplorerMethod,
  type ApiExplorerProjectPlatform,
  type ApiExplorerSessionAuthMode,
} from '@/lib/api-explorer'

type ProjectUserOption = {
  $id: string
  name?: string
  email?: string
  phone?: string
}

function buildUserSelectItem(user: ProjectUserOption) {
  const name = user.name?.trim() || ''
  const email = user.email?.trim() || ''
  const phone = user.phone?.trim() || ''
  const id = user.$id

  const label = name || email || phone || id
  const descriptionParts = [
    name && name !== label ? name : '',
    email && email !== label ? email : '',
    phone && phone !== label ? phone : '',
    id !== label ? id : '',
  ].filter(Boolean)

  return {
    value: id,
    label,
    description: descriptionParts.join(' · ') || undefined,
    searchText: [name, email, phone, id].filter(Boolean).join(' '),
  }
}

type ApiExplorerAuthSectionProps = {
  projectId: string
  platform: ApiExplorerProjectPlatform
  method: ApiExplorerMethod
  authMode: ApiExplorerSessionAuthMode
  authUserId: string
  onAuthModeChange: (mode: ApiExplorerSessionAuthMode) => void
  onAuthUserIdChange: (userId: string) => void
}

export function ApiExplorerAuthSection({
  projectId,
  platform,
  method,
  authMode,
  authUserId,
  onAuthModeChange,
  onAuthUserIdChange,
}: ApiExplorerAuthSectionProps) {
  const requiresSessionAuth = methodRequiresSessionAuthChoice(method, platform)
  const requiresApiKey = methodRequiresApiKey(method, platform)
  const [userSearch, setUserSearch] = useState('')

  const { users, isLoading: usersLoading } = useProjectUsers(
    requiresSessionAuth ? projectId : null,
    0,
    100,
    userSearch,
  )

  const userItems = useMemo(
    () => users.map((user) => buildUserSelectItem(user)),
    [users],
  )

  return (
    <div className="rounded-xl border border-border bg-card/50 overflow-hidden">
      <div className="space-y-4 px-6 py-4">
        <div className="space-y-1">
          <p className="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">
            Authentication
          </p>
          <p className="text-[13px] text-muted-foreground">
            Requests use the current project. Choose how to authenticate when a
            session is required.
          </p>
        </div>

        <div className="space-y-2">
          <Label className="text-[12px] text-muted-foreground">Project ID</Label>
          <div className="flex flex-wrap items-center gap-2">
            <CopyableId id={projectId} size="sm" maxWidth={280} />
            <span className="text-[12px] text-muted-foreground">
              Current project (always used)
            </span>
          </div>
        </div>

        {requiresSessionAuth && (
          <div className="space-y-3">
            <Label className="text-[12px] text-muted-foreground">Act as</Label>
            <ToggleGroup
              type="single"
              variant="outline"
              size="sm"
              value={authMode}
              onValueChange={(value) => {
                if (value === 'guest' || value === 'user') {
                  onAuthModeChange(value)
                  if (value === 'guest') onAuthUserIdChange('')
                }
              }}
              className="grid w-full max-w-md grid-cols-2"
              aria-label="Session authentication"
            >
              <ToggleGroupItem
                value="guest"
                className="h-9 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
              >
                Guest
              </ToggleGroupItem>
              <ToggleGroupItem
                value="user"
                className="h-9 text-[13px] font-medium data-[state=on]:bg-muted data-[state=on]:text-foreground"
              >
                User
              </ToggleGroupItem>
            </ToggleGroup>

            {authMode === 'guest' ? (
              <p className="text-[12px] text-muted-foreground">
                No session or JWT is sent. Explorer requests bypass the Appwrite
                SDK and use isolated fetch with credentials omitted, so a session
                from your app in this browser is not attached.
              </p>
            ) : (
              <div className="space-y-2">
                <SearchableSelect
                  value={authUserId}
                  onValueChange={onAuthUserIdChange}
                  items={userItems}
                  placeholder={
                    usersLoading ? 'Loading users…' : 'Select a project user'
                  }
                  searchPlaceholder="Search by name, email, phone, or ID…"
                  emptyMessage="No users found"
                  disabled={usersLoading}
                  onSearchChange={setUserSearch}
                />
                <p className="text-[12px] text-muted-foreground">
                  A JWT is generated for the selected user when you send the
                  request. Only that JWT is sent on the test request, not your
                  app session cookie. The Appwrite SDK has no option to disable
                  cookies; explorer test requests avoid the SDK for that reason.
                </p>
              </div>
            )}
          </div>
        )}

        {requiresApiKey && (
          <p className="text-[12px] text-muted-foreground">
            This endpoint requires a server API key. Add an API key in the
            explorer configuration to send authenticated server requests.
          </p>
        )}
      </div>
    </div>
  )
}
