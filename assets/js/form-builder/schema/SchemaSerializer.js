/**
 * Schema Serializer
 */
import { flattenLayoutFields, unflattenLayoutFields } from './LayoutFlattener.js';
import { normalizeFieldSchema } from './SchemaNormalizer.js';

export function serializeSchema(layout, options = { flat: true }) {
  if (options.flat) {
    const flat = flattenLayoutFields(layout);
    return flat.map(normalizeFieldSchema);
  }
  return layout;
}

export function parseSchema(jsonString) {
  try {
    const parsed = typeof jsonString === 'string' ? JSON.parse(jsonString) : jsonString;
    return Array.isArray(parsed) ? parsed : [];
  } catch (e) {
    console.error('Failed to parse schema JSON:', e);
    return [];
  }
}

export function formatSchemaJson(schema, space = 2) {
  return JSON.stringify(schema, null, space);
}
