/**
 * Analytics annotation routes, called directly: they are Cloud-only and the
 * pinned Console SDK has no `analytics.*Annotation` methods yet. Delete this for
 * the generated methods and `Models.AnalyticsAnnotation` once the pin carries them.
 */

import type { Client } from '@appwrite.io/console'

export interface AnalyticsAnnotation {
  $id: string
  $createdAt: string
  $updatedAt: string
  propertyId: string
  /** The instant the note marks on the chart */
  date: string
  content: string
  /** The console user who wrote it; empty when an API key did */
  userId: string
}

export interface AnalyticsAnnotationList {
  total: number
  annotations: AnalyticsAnnotation[]
}

function apiHeaders(client: Client): Record<string, string> {
  return {
    'X-Appwrite-Project': client.config.project,
    'content-type': 'application/json',
    accept: 'application/json',
  }
}

function annotationsUri(
  client: Client,
  propertyId: string,
  annotationId?: string,
): URL {
  return new URL(
    `${client.config.endpoint}/analytics/properties/${encodeURIComponent(propertyId)}/annotations` +
      (annotationId ? `/${encodeURIComponent(annotationId)}` : ''),
  )
}

export async function listAnalyticsAnnotations(
  client: Client,
  params: { propertyId: string; queries?: string[] },
): Promise<AnalyticsAnnotationList> {
  const uri = annotationsUri(client, params.propertyId)
  for (const query of params.queries ?? []) {
    uri.searchParams.append('queries[]', query)
  }
  return (await client.call(
    'get',
    uri,
    apiHeaders(client),
  )) as AnalyticsAnnotationList
}

export async function createAnalyticsAnnotation(
  client: Client,
  params: {
    propertyId: string
    annotationId: string
    date: string
    content: string
  },
): Promise<AnalyticsAnnotation> {
  const { propertyId, ...payload } = params
  return (await client.call(
    'post',
    annotationsUri(client, propertyId),
    apiHeaders(client),
    payload,
  )) as AnalyticsAnnotation
}

export async function updateAnalyticsAnnotation(
  client: Client,
  params: {
    propertyId: string
    annotationId: string
    date?: string
    content?: string
  },
): Promise<AnalyticsAnnotation> {
  const { propertyId, annotationId, ...payload } = params
  return (await client.call(
    'patch',
    annotationsUri(client, propertyId, annotationId),
    apiHeaders(client),
    payload,
  )) as AnalyticsAnnotation
}

export async function deleteAnalyticsAnnotation(
  client: Client,
  params: { propertyId: string; annotationId: string },
): Promise<void> {
  await client.call(
    'delete',
    annotationsUri(client, params.propertyId, params.annotationId),
    apiHeaders(client),
  )
}
