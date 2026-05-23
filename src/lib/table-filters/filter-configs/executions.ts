/**
 * Predefined filter columns for function executions / site logs list.
 *
 * Filterable attributes match `listExecutions` / `listLogs` SDK docs:
 * trigger, status, responseStatusCode, duration, requestMethod, requestPath,
 * deploymentId (plus $id and $createdAt).
 */

import type { FilterColumn } from '../types'

const EXECUTION_STATUS_ELEMENTS = [
  { value: 'completed', label: 'Completed' },
  { value: 'processing', label: 'Processing' },
  { value: 'failed', label: 'Failed' },
  { value: 'waiting', label: 'Waiting' },
  { value: 'scheduled', label: 'Scheduled' },
]

const EXECUTION_TRIGGER_ELEMENTS = [
  { value: 'http', label: 'HTTP' },
  { value: 'schedule', label: 'Schedule' },
  { value: 'event', label: 'Event' },
]

const EXECUTION_METHOD_ELEMENTS = [
  { value: 'GET', label: 'GET' },
  { value: 'POST', label: 'POST' },
  { value: 'PUT', label: 'PUT' },
  { value: 'PATCH', label: 'PATCH' },
  { value: 'DELETE', label: 'DELETE' },
  { value: 'OPTIONS', label: 'OPTIONS' },
  { value: 'HEAD', label: 'HEAD' },
]

export const executionsFilterColumns: FilterColumn[] = [
  { id: '$id', title: '$id', type: 'string' },
  { id: 'deploymentId', title: 'Deployment ID', type: 'string' },
  {
    id: 'status',
    title: 'Status',
    type: 'enum',
    format: 'enum',
    elements: EXECUTION_STATUS_ELEMENTS,
    optional: false,
  },
  {
    id: 'trigger',
    title: 'Trigger',
    type: 'enum',
    format: 'enum',
    elements: EXECUTION_TRIGGER_ELEMENTS,
    optional: false,
  },
  {
    id: 'requestMethod',
    title: 'Method',
    type: 'enum',
    format: 'enum',
    elements: EXECUTION_METHOD_ELEMENTS,
    optional: false,
  },
  { id: 'responseStatusCode', title: 'Status code', type: 'integer' },
  { id: 'requestPath', title: 'Path', type: 'string' },
  { id: 'duration', title: 'Duration', type: 'double' },
  { id: '$createdAt', title: '$createdAt', type: 'datetime' },
]
