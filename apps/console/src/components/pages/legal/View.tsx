import type { PolicySlug } from '@/lib/legal/policies'
import {
  extractPolicyToc,
  PolicyMarkdown,
} from '@/components/pages/legal/PolicyMarkdown'
import { PolicyLayout } from '@/components/pages/legal/PolicyLayout'

type LegalPolicyViewProps = {
  title: string
  content: string
  currentPolicy?: PolicySlug
}

export function LegalPolicyView({
  title,
  content,
  currentPolicy,
}: LegalPolicyViewProps) {
  const tocItems = extractPolicyToc(content)

  return (
    <PolicyLayout
      title={title}
      tocItems={tocItems}
      currentPolicy={currentPolicy}
    >
      <PolicyMarkdown content={content} />
    </PolicyLayout>
  )
}
