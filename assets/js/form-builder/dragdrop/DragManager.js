/**
 * Drag Manager
 */
import { emit } from '../events.js';

export class DragManager {
  constructor(builder) {
    this.builder = builder;
    this.dragSource = null;
    this.dragType = null;
  }

  setDragSource(el, type = 'card') {
    this.dragSource = el;
    this.dragType = type;
    emit('drag:start', { el, type });
  }

  clearDragSource() {
    const prev = this.dragSource;
    this.dragSource = null;
    this.dragType = null;
    emit('drag:end', { el: prev });
  }

  getDragSource() {
    return this.dragSource;
  }
}
