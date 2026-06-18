import { createFileRoute, notFound } from '@tanstack/react-router'
import { NotFoundView } from '@/components/error/NotFound'
import { ApiReferenceServiceView } from '@/components/pages/docs/references/ApiReferenceServiceView'
import {
  isReferencePlatform,
  isReferenceService,
  isReferenceVersion,
} from '@/lib/docs/references/constants'
import { isApiReferenceNotFoundError } from '@/lib/docs/references/errors'
import { getApiReferenceCanonicalSlug } from '@/lib/docs/references/seo'
import { getDocsMetaTags } from '@/lib/docs/route-meta'
import {
  getDocsBreadcrumbSchema,
  getDocsArticleSchema,
} from '@/lib/docs/seo'
import { loadApiReferenceServiceFn } from '@/server/functions/api-reference'

export const Route = createFileRoute('/docs/references/$version/$platform/$service')({
  ssr: true,
  notFoundComponent: NotFoundView,
  loader: async ({ params }) => {
    const { version, platform, service } = params

    if (
      !isReferenceVersion(version) ||
      !isReferencePlatform(platform) ||
      !isReferenceService(service)
    ) {
      throw notFound()
    }

    try {
      const data = await loadApiReferenceServiceFn({
        data: { version, platform, service },
      })
      return { data, version, platform, service }
    } catch (error) {
      if (isApiReferenceNotFoundError(error)) {
        throw notFound()
      }
      throw error
    }
  },
  head: ({ loaderData }) => {
    if (!loaderData) return {}
    const { data, version, platform, service } = loaderData
    const slug = `references/${version}/${platform}/${service}`
    const canonicalSlug = getApiReferenceCanonicalSlug(slug)
    const shortDescription = data.description.split('.')[0]
      ? `${data.description.split('.')[0]}.`
      : `${data.label} API reference.`
    const meta = {
      slug,
      title: `${data.label} API reference`,
      description: shortDescription,
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
  component: ApiReferenceServicePage,
})

function ApiReferenceServicePage() {
  const { data, version, platform, service } = Route.useLoaderData()
  return (
    <ApiReferenceServiceView
      data={data}
      version={version}
      platform={platform}
      service={service}
    />
  )
}
