/**
 * Drop Zones Manager
 */
import { emit } from '../events.js';

export class DropZones {
  constructor(builder) {
    this.builder = builder;
  }

  attachDropHandlers(dropZoneEl, zoneType = 'row') {
    if (!dropZoneEl) return;

    dropZoneEl.addEventListener('dragover', (e) => {
      e.preventDefault();
      if (e.dataTransfer) {
        e.dataTransfer.dropEffect = 'move';
      }
      dropZoneEl.classList.add('drag-over');
    });

    dropZoneEl.addEventListener('dragleave', () => {
      dropZoneEl.classList.remove('drag-over');
    });

    dropZoneEl.addEventListener('drop', (e) => {
      e.preventDefault();
      dropZoneEl.classList.remove('drag-over');
      const droppedType = e.dataTransfer ? e.dataTransfer.getData('text/plain') : null;
      emit('drop', { zoneEl: dropZoneEl, zoneType, droppedType, event: e });
    });
  }
}
