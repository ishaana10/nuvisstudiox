/**
 * Schema Normalizer
 */
import { DEFAULT_FIELD } from '../utils/Constants.js';

export function normalizeFieldSchema(field = {}) {
  if (typeof field === 'string') {
    return { ...DEFAULT_FIELD, name: field, label: field };
  }

  return {
    type: field.type || DEFAULT_FIELD.type,
    label: field.label || field.name || DEFAULT_FIELD.label,
    name: field.name || '',
    col: parseInt(field.col, 10) || DEFAULT_FIELD.col,
    required: !!field.required,
    ...field
  };
}
