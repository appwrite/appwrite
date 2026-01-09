import { useParams } from '@tanstack/react-router'
import { FunctionVariablesCard } from './VariablesCard'

export function FunctionVariables() {
  const { projectId, functionId } = useParams({ strict: false })

  return (
    <div className="space-y-6">
      <FunctionVariablesCard projectId={projectId} functionId={functionId} />
    </div>
  )
}


