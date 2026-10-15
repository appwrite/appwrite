import { useParams } from '@tanstack/react-router'
import { FunctionVariablesCard } from './VariablesCard'

export function View() {
  const { projectId, functionId } = useParams({ strict: false })

  return (
    <div className="flex-1">
      <div className="mx-auto w-full max-w-7xl px-4 pb-4 sm:px-6 sm:pb-6 pt-4 sm:pt-6">
        <div className="space-y-6">
          <FunctionVariablesCard
            projectId={projectId}
            functionId={functionId}
          />
        </div>
      </div>
    </div>
  )
}
