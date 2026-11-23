import * as React from 'react'
import { GripVerticalIcon } from 'lucide-react'
import * as ResizablePrimitive from 'react-resizable-panels'

import { RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X, setBodyResizeDragActive } from '@/lib/layout/horizontal-resize'
import { usePageDirection } from '@/lib/layout/page-direction'
import { cn } from '@/lib/utils'

function ResizablePanelGroup({
  className,
  dir,
  direction,
  style,
  ...props
}: React.ComponentProps<typeof ResizablePrimitive.PanelGroup>) {
  const pageDirection = usePageDirection()
  const groupDirection = dir ?? pageDirection

  return (
    <ResizablePrimitive.PanelGroup
      key={groupDirection}
      data-slot="resizable-panel-group"
      dir={groupDirection}
      direction={direction}
      style={{
        ...style,
        direction: groupDirection,
      }}
      className={cn(
        'flex h-full w-full data-[panel-group-direction=vertical]:flex-col',
        className,
      )}
      {...props}
    />
  )
}

const ResizablePanel = React.forwardRef<
  React.ElementRef<typeof ResizablePrimitive.Panel>,
  React.ComponentPropsWithoutRef<typeof ResizablePrimitive.Panel>
>(({ className, ...props }, ref) => (
  <ResizablePrimitive.Panel
    ref={ref}
    data-slot="resizable-panel"
    className={className}
    {...props}
  />
))
ResizablePanel.displayName = 'ResizablePanel'

function ResizableHandle({
  withHandle,
  className,
  onDragging,
  ...props
}: React.ComponentProps<typeof ResizablePrimitive.PanelResizeHandle> & {
  withHandle?: boolean
}) {
  const handleDragging = React.useCallback(
    (isDragging: boolean) => {
      setBodyResizeDragActive(isDragging)
      onDragging?.(isDragging)
    },
    [onDragging],
  )

  return (
    <ResizablePrimitive.PanelResizeHandle
      data-slot="resizable-handle"
      onDragging={handleDragging}
      className={cn(
        'bg-border focus-visible:ring-ring relative flex w-px items-center justify-center after:absolute after:inset-y-0 after:w-1',
        RESIZE_HANDLE_PSEUDO_AFTER_LOGICAL_X,
        'focus-visible:ring-1 focus-visible:ring-offset-1 focus-visible:outline-hidden data-[panel-group-direction=vertical]:h-px data-[panel-group-direction=vertical]:w-full data-[panel-group-direction=vertical]:after:start-0 data-[panel-group-direction=vertical]:after:h-1 data-[panel-group-direction=vertical]:after:w-full data-[panel-group-direction=vertical]:after:translate-x-0 data-[panel-group-direction=vertical]:after:-translate-y-1/2 [&[data-panel-group-direction=vertical]>div]:rotate-90',
        className,
      )}
      {...props}
    >
      {withHandle && (
        <div className="bg-border z-10 flex h-4 w-3 items-center justify-center rounded-xs border">
          <GripVerticalIcon className="size-2.5" />
        </div>
      )}
    </ResizablePrimitive.PanelResizeHandle>
  )
}

export { ResizablePanelGroup, ResizablePanel, ResizableHandle }
