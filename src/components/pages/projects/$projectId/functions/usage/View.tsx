import { useParams } from '@tanstack/react-router'
import {
  getFunctionsServiceTabs,
  ProductComputeUsageView,
} from '../shared/ProductComputeUsageView'

export function View() {
  const { projectId } = useParams({ strict: false })

  if (!projectId) return null

  return (
    <ProductComputeUsageView
      projectId={projectId}
      title="Functions"
      tabs={getFunctionsServiceTabs(projectId)}
      activeTab="usage"
      scope="functions"
    />
  )
}
