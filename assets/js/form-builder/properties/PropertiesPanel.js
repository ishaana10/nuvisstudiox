/**
 * Properties Panel Orchestrator
 */
import { FieldProperties } from './FieldProperties.js';
import { RowProperties } from './RowProperties.js';

export class PropertiesPanel {
  constructor(builder, containerEl) {
    this.builder = builder;
    this.containerEl = containerEl;
    this.fieldProperties = new FieldProperties(this);
    this.rowProperties = new RowProperties(this);
    this.activeTarget = null;
  }

  showFieldProperties(field, fieldCard) {
    this.activeTarget = { type: 'field', field, card: fieldCard };
    if (this.containerEl) {
      this.fieldProperties.render(field, this.containerEl);
    }
  }

  showRowProperties(rowEl) {
    this.activeTarget = { type: 'row', rowEl };
    if (this.containerEl) {
      this.rowProperties.render(rowEl, this.containerEl);
    }
  }

  clear() {
    this.activeTarget = null;
    if (this.containerEl) {
      this.containerEl.innerHTML = '<div style="padding:16px; color:#94a3b8; font-style:italic;">Select an element to edit properties.</div>';
    }
  }
}
