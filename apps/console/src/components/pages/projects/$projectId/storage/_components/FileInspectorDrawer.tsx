import { BaseDrawer } from '@/components/global/shared/BaseDrawer'
import {
  STORAGE_FILES_INSPECTOR_DRAWER_HEIGHT_CLASS,
  STORAGE_FILES_SPLIT_PANE_BG_CLASS,
} from './files-documents-layout'
import { FileInspectorPanel, type FileInspectorPanelProps } from './FileInspectorPanel'
import { cn } from '@/lib/utils'
import { useT } from '@/lib/i18n/translate'

export type FileInspectorDrawerProps = {
  open: boolean
  onOpenChange: (open: boolean) => void
  projectId: string
  bucketId: string
  fileId: string | undefined
  panelTab?: FileInspectorPanelProps['panelTab']
}

/**
 * Narrow viewport: file inspector as a bottom sheet (replaces inline stacked preview).
 */
export function FileInspectorDrawer({
  open,
  onOpenChange,
  projectId,
  bucketId,
  fileId,
  panelTab,
}: FileInspectorDrawerProps) {
  const t = useT()
  return (
    <BaseDrawer
      open={open}
      onOpenChange={onOpenChange}
      side="bottom"
      title={t('File preview')}
      description={t('View and manage the selected storage file')}
      maxWidth="w-full"
      contentClassName={cn(
        'w-full max-w-none sm:max-w-none rounded-t-xl border-t p-0',
        STORAGE_FILES_INSPECTOR_DRAWER_HEIGHT_CLASS,
      )}
      disableAutoFocus
    >
      <div
        className={cn(
          'flex h-full min-h-0 w-full flex-col overflow-hidden',
          STORAGE_FILES_SPLIT_PANE_BG_CLASS,
        )}
      >
        <FileInspectorPanel
          projectId={projectId}
          bucketId={bucketId}
          fileId={fileId}
          panelTab={panelTab}
          presentation="drawer"
        />
      </div>
    </BaseDrawer>
  )
}
