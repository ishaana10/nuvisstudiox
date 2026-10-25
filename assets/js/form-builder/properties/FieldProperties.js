/**
 * Field Properties Editor Component
 */
export class FieldProperties {
  constructor(panel) {
    this.panel = panel;
  }

  render(field, containerEl) {
    if (!containerEl || !field) return;
    containerEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex; flex-direction:column; gap:12px; padding:12px;';

    // Field Label
    const labelGroup = document.createElement('div');
    labelGroup.className = 'nu-field';
    labelGroup.innerHTML = `
      <label class="nu-label">Field Label</label>
      <input type="text" class="nu-input" value="${field.label || ''}">
    `;
    const labelInput = labelGroup.querySelector('input');
    labelInput.oninput = (e) => {
      field.label = e.target.value;
      if (this.panel && this.panel.builder) {
        this.panel.builder.notifyChange();
      }
    };

    // Field Name
    const nameGroup = document.createElement('div');
    nameGroup.className = 'nu-field';
    nameGroup.innerHTML = `
      <label class="nu-label">Field Name (Database Column)</label>
      <input type="text" class="nu-input" value="${field.name || ''}">
    `;
    const nameInput = nameGroup.querySelector('input');
    nameInput.oninput = (e) => {
      field.name = e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, '_');
      if (this.panel && this.panel.builder) {
        this.panel.builder.notifyChange();
      }
    };

    // Required checkbox
    const reqGroup = document.createElement('div');
    reqGroup.className = 'nu-field';
    reqGroup.innerHTML = `
      <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
        <input type="checkbox" ${field.required ? 'checked' : ''}>
        <span class="nu-label" style="margin:0;">Required Field</span>
      </label>
    `;
    const reqInput = reqGroup.querySelector('input');
    reqInput.onchange = (e) => {
      field.required = e.target.checked;
      if (this.panel && this.panel.builder) {
        this.panel.builder.notifyChange();
      }
    };

    wrap.appendChild(labelGroup);
    wrap.appendChild(nameGroup);
    wrap.appendChild(reqGroup);

    containerEl.appendChild(wrap);
  }
}
