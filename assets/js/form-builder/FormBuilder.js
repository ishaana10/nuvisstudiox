/**
 * Main FormBuilder Orchestrator Class
 */
import { Canvas } from './canvas/Canvas.js';
import { PropertiesPanel } from './properties/PropertiesPanel.js';
import { DragManager } from './dragdrop/DragManager.js';
import { DropZones } from './dragdrop/DropZones.js';
import { serializeSchema, parseSchema, formatSchemaJson } from './schema/SchemaSerializer.js';
import { emit, on, off } from './events.js';

export class FormBuilder {
  constructor(canvasContainer, propertiesContainer, options = {}) {
    this.schema = options.schema || [];
    this.mode = options.mode || 'edit';
    this.canvas = new Canvas(this, canvasContainer);
    this.properties = new PropertiesPanel(this, propertiesContainer);
    this.dragManager = new DragManager(this);
    this.dropZones = new DropZones(this);
    this._isDirty = false;
  }

  setSchema(schema) {
    this.schema = parseSchema(schema);
    this.render();
  }

  getSchema() {
    return serializeSchema(this.schema);
  }

  getSchemaJson(space = 2) {
    return formatSchemaJson(this.getSchema(), space);
  }

  addRow() {
    this._isDirty = true;
    return this.canvas.addRow();
  }

  clearCanvas() {
    this._isDirty = true;
    this.canvas.clear();
    this.properties.clear();
  }

  selectField(field, fieldCard) {
    this.properties.showFieldProperties(field, fieldCard);
    emit('field:select', { field, card: fieldCard });
  }

  selectRow(rowEl) {
    this.properties.showRowProperties(rowEl);
    emit('row:select', { rowEl });
  }

  notifyChange() {
    this._isDirty = true;
    emit('schema:change', { schema: this.getSchema() });
  }

  render() {
    this.canvas.clear();
    // Render schema elements onto canvas
    emit('render', { builder: this });
  }
}
