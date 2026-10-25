/**
 * Row Properties Editor Component
 */
export class RowProperties {
  constructor(panel) {
    this.panel = panel;
  }

  render(rowEl, containerEl) {
    if (!containerEl || !rowEl) return;
    containerEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.style.cssText = 'display:flex; flex-direction:column; gap:12px; padding:12px;';

    const title = document.createElement('h4');
    title.style.cssText = 'font-size:14px; font-weight:700; margin:0;';
    title.textContent = 'Row Properties';

    const alignGroup = document.createElement('div');
    alignGroup.className = 'nu-field';
    alignGroup.innerHTML = `
      <label class="nu-label">Element Alignment</label>
      <select class="nu-input">
        <option value="left">Left Alignment</option>
        <option value="center">Center Alignment</option>
        <option value="right">Right Alignment</option>
      </select>
    `;

    wrap.appendChild(title);
    wrap.appendChild(alignGroup);
    containerEl.appendChild(wrap);
  }
}
