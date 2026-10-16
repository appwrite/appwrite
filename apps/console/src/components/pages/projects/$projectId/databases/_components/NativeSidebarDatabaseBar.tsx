import { Link, useNavigate } from '@tanstack/react-router'
import { useQueryClient } from '@tanstack/react-query'
import { ChevronLeft } from 'lucide-react'
import { navigateToDatabaseFromSwitcher } from '@/lib/databases/navigate-to-database-switcher'
import { DatabaseSelector } from './DatabaseSelector'
import { useT } from '@/lib/i18n/translate'

type NativeSidebarDatabaseBarProps = {
  projectId: string
  databaseId: string
  databaseName: string
  /** Kept for call-site compatibility; switcher shows all database types. */
  nativeEngine?: string
}

export function NativeSidebarDatabaseBar({
  projectId,
  databaseId,
  databaseName,
}: NativeSidebarDatabaseBarProps) {
  const t = useT()
  const navigate = useNavigate()
  const queryClient = useQueryClient()

  return (
    <div className="flex shrink-0 flex-col border-b border-border bg-background">
      <div className="flex items-center gap-2 border-b border-border px-3 py-2.5">
        <Link
          to="/projects/$projectId/databases"
          params={{ projectId }}
          className="flex h-6 w-6 cursor-pointer items-center justify-center rounded text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
          aria-label={t('Back to databases')}
        >
          <ChevronLeft className="h-4 w-4" />
        </Link>
        <span className="text-[13px] font-medium text-foreground">
          {t('Databases')}
        </span>
      </div>
      <div className="flex min-w-0 flex-col gap-2 px-2 py-2">
        <DatabaseSelector
          projectId={projectId}
          value={databaseId}
          selectedName={databaseName}
          selectedIsNative
          onSelect={(newDatabaseId, meta) => {
            if (newDatabaseId === databaseId) return
            void navigateToDatabaseFromSwitcher({
              projectId,
              selection: {
                id: newDatabaseId,
                apiType: meta?.apiType,
                engine: meta?.engine,
                product: meta?.product,
              },
              navigate: (link) => {
                navigate({
                  to: link.to,
                  params: link.params,
                })
              },
              queryClient,
            })
          }}
        />
      </div>
    </div>
  )
}
