import {
  Type,
  Hash,
  Binary,
  ToggleLeft,
  CalendarClock,
  CalendarDays,
  Clock,
  Mail,
  Link2,
  MapPin,
  Columns3,
  FileJson,
  Route,
  Layers,
  Cpu,
  type LucideIcon,
} from 'lucide-react'

/**
 * Get the appropriate icon component for a column type
 *
 * @param type - The column type (e.g., 'string', 'integer', 'int4', 'point', etc.)
 * @returns The Lucide icon component for that column type
 */
export function getColumnIcon(type: string): LucideIcon {
  const normalized = type.toLowerCase().trim()

  if (normalized === 'enum' || normalized.startsWith('enum(')) {
    return Columns3
  }

  switch (normalized) {
    case 'string':
    case 'varchar':
    case 'text':
    case 'mediumtext':
    case 'longtext':
    case 'bpchar':
    case 'char':
    case 'character varying':
      return Type
    case 'id':
    case 'system-id':
    case '$id':
    case 'uuid':
      return Hash
    case 'integer':
    case 'int':
    case 'int2':
    case 'int4':
    case 'int8':
    case 'bigint':
    case 'smallint':
    case 'serial':
    case 'bigserial':
    case 'smallserial':
      return Binary
    case 'float':
    case 'float4':
    case 'float8':
    case 'double':
    case 'double precision':
    case 'real':
    case 'numeric':
    case 'decimal':
      return Hash
    case 'boolean':
    case 'bool':
      return ToggleLeft
    case 'datetime':
    case 'timestamp':
    case 'timestamptz':
    case 'timestamp with time zone':
    case 'timestamp without time zone':
    case '$createdat':
    case '$updatedat':
      return CalendarClock
    case 'date':
      return CalendarDays
    case 'time':
      return Clock
    case 'email':
      return Mail
    case 'url':
      return Link2
    case 'ip':
      return MapPin
    case 'json':
    case 'jsonb':
    case 'object':
      return FileJson
    case 'vector':
    case 'embedding':
    case 'embeddings':
      return Cpu
    case 'point':
      return MapPin
    case 'linestring':
      return Route
    case 'polygon':
      return Layers
    default:
      return FileJson
  }
}
