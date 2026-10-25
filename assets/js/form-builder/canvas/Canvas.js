/**
 * Canvas Manager
 */
import { Row } from './Row.js';

export class Canvas {
  constructor(builder, containerEl) {
    this.builder = builder;
    this.containerEl = containerEl;
    this.rows = [];
    this.showGridlines = false;
  }

  addRow() {
    const row = new Row(this.builder);
    const rowEl = row.render();
    if (this.containerEl) {
      this.containerEl.appendChild(rowEl);
    }
    this.rows.push(row);
    return row;
  }

  clear() {
    if (this.containerEl) {
      this.containerEl.innerHTML = '';
    }
    this.rows = [];
  }

  toggleGridLines(active) {
    this.showGridlines = active !== undefined ? active : !this.showGridlines;
    if (this.containerEl) {
      this.containerEl.classList.toggle('nb-show-gridlines', this.showGridlines);
    }
    return this.showGridlines;
  }
}
