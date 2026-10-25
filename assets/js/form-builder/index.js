/**
 * Entry point / Public API surface facade for Form Builder
 */
import { FormBuilder } from './FormBuilder.js';
import { bus, emit, on, off } from './events.js';
import { FIELD_TYPES, GRID_COLUMNS } from './utils/Constants.js';
import { calculateColSpan, calculateGridStartColumn } from './utils/GridCalculator.js';
import { flattenLayoutFields, unflattenLayoutFields } from './schema/LayoutFlattener.js';
import { normalizeFieldSchema } from './schema/SchemaNormalizer.js';
import { serializeSchema, parseSchema, formatSchemaJson } from './schema/SchemaSerializer.js';

export {
  FormBuilder,
  bus,
  emit,
  on,
  off,
  FIELD_TYPES,
  GRID_COLUMNS,
  calculateColSpan,
  calculateGridStartColumn,
  flattenLayoutFields,
  unflattenLayoutFields,
  normalizeFieldSchema,
  serializeSchema,
  parseSchema,
  formatSchemaJson
};

// Backwards compatibility binding on window.nbFormBuilder
if (typeof window !== 'undefined') {
  window.FormBuilderModule = {
    FormBuilder,
    bus,
    emit,
    on,
    off,
    FIELD_TYPES,
    GRID_COLUMNS,
    calculateColSpan,
    calculateGridStartColumn,
    flattenLayoutFields,
    unflattenLayoutFields,
    normalizeFieldSchema,
    serializeSchema,
    parseSchema,
    formatSchemaJson
  };
}
