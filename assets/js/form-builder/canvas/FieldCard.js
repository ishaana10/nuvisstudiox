/**
 * FieldCard DOM Component
 */
import { calculateColSpan } from '../utils/GridCalculator.js';
import { PreviewRenderer } from './PreviewRenderer.js';

export class FieldCard {
  constructor(fieldData, builder) {
    this.field = fieldData;
    this.builder = builder;
    this.el = null;
  }

  render() {
    const card = document.createElement('div');
    card.className = 'nb-field-card nb-card-visual';
    card.dataset.fieldType = this.field.type || 'text';
    card.dataset.fieldName = this.field.name || '';

    const colSpan = parseInt(this.field.col, 10) || 6;
    card.style.gridColumn = calculateColSpan(colSpan);

    const header = document.createElement('div');
    header.className = 'nb-field-header';
    header.style.cssText = 'display:flex; justify-content:space-between; align-items:center; padding:4px 8px; border-bottom:1px solid #eee; background:#f8fafc; font-weight:600; font-size:12px;';

    const titleGroup = document.createElement('div');
    titleGroup.style.cssText = 'display:flex; align-items:center; gap:6px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;';

    const labelSpan = document.createElement('span');
    labelSpan.className = 'nb-field-label';
    labelSpan.textContent = this.field.label || this.field.name || 'Field';

    const reqSpan = document.createElement('span');
    reqSpan.style.color = '#e11d48';
    reqSpan.style.display = this.field.required ? 'inline' : 'none';
    reqSpan.textContent = '*';

    titleGroup.appendChild(labelSpan);
    titleGroup.appendChild(reqSpan);

    const badge = document.createElement('span');
    badge.className = 'nb-type-badge';
    badge.style.cssText = 'font-size:10px; background:#e0f2fe; color:#0369a1; padding:2px 6px; border-radius:4px; font-weight:700;';
    badge.textContent = (this.field.type || 'text').toUpperCase();

    header.appendChild(titleGroup);
    header.appendChild(badge);

    const body = document.createElement('div');
    body.className = 'nb-field-body';
    body.style.padding = '8px';

    const preview = PreviewRenderer.buildCardCanvasPreview(this.field.type, this.field);
    body.appendChild(preview);

    card.appendChild(header);
    card.appendChild(body);

    card.addEventListener('click', (e) => {
      e.stopPropagation();
      if (this.builder && typeof this.builder.selectField === 'function') {
        this.builder.selectField(this.field, card);
      }
    });

    this.el = card;
    return card;
  }
}
