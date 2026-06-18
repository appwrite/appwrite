import { createFileRoute, notFound } from '@tanstack/react-router'
import { NotFoundView } from '@/components/error/NotFound'
import { ApiReferenceModelView } from '@/components/pages/docs/references/ApiReferenceModelView'
import { isReferenceVersion } from '@/lib/docs/references/constants'
import { isApiReferenceNotFoundError } from '@/lib/docs/references/errors'
import { getApiReferenceCanonicalSlug } from '@/lib/docs/references/seo'
import { getDocsMetaTags } from '@/lib/docs/route-meta'
import {
  getDocsBreadcrumbSchema,
  getDocsArticleSchema,
} from '@/lib/docs/seo'
import { loadApiReferenceModelFn } from '@/server/functions/api-reference'

export const Route = createFileRoute('/docs/references/$version/models/$model')({
  ssr: true,
  notFoundComponent: NotFoundView,
  loader: async ({ params }) => {
    const { version, model } = params

    if (!isReferenceVersion(version)) {
      throw notFound()
    }

    try {
      const data = await loadApiReferenceModelFn({ data: { version, model } })
      return { data, version }
    } catch (error) {
      if (isApiReferenceNotFoundError(error)) {
        throw notFound()
      }
      throw error
    }
  },
  head: ({ loaderData }) => {
    if (!loaderData) return {}
    const { data, version } = loaderData
    const slug = `references/${version}/models/${data.id}`
    const canonicalSlug = getApiReferenceCanonicalSlug(slug)
    const meta = {
      slug,
      title: data.title,
      description: `${data.title} model reference.`,
      layout: 'article' as const,
    }
    const seoOptions = { canonicalSlug }

    return {
      meta: getDocsMetaTags(meta, seoOptions),
      scripts: [
        {
          type: 'application/ld+json',
          children: JSON.stringify(getDocsBreadcrumbSchema(meta, slug, seoOptions)),
        },
        {
          type: 'application/ld+json',
          children: JSON.stringify(getDocsArticleSchema(meta, slug, seoOptions)),
        },
      ],
    }
  },
  component: ApiReferenceModelPage,
})

function ApiReferenceModelPage() {
  const { data, version } = Route.useLoaderData()
  return <ApiReferenceModelView data={data} version={version} />
}
