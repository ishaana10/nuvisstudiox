/**
 * Row DOM Component
 */
export class Row {
  constructor(builder, options = {}) {
    this.builder = builder;
    this.options = options;
    this.el = null;
    this.bodyEl = null;
  }

  render() {
    const row = document.createElement('div');
    row.className = 'nb-row';
    row.style.cssText = 'border:1px solid #e2e8f0; border-radius:8px; margin-bottom:12px; background:#fff; overflow:hidden;';

    const header = document.createElement('div');
    header.className = 'nb-row-header';
    header.style.cssText = 'display:flex; justify-content:space-between; align-items:center; padding:6px 12px; background:#f1f5f9; border-bottom:1px solid #e2e8f0; font-size:12px; font-weight:600; color:#475569;';

    const title = document.createElement('span');
    title.textContent = '≡ Canvas Row (12 Cols Grid)';

    const actions = document.createElement('div');
    actions.style.cssText = 'display:flex; gap:6px; align-items:center;';

    const btn1 = document.createElement('button');
    btn1.type = 'button';
    btn1.textContent = '1 Col';
    btn1.className = 'nu-btn nu-btn-ghost nu-btn-sm';
    btn1.onclick = () => this.setColumns(1);

    const btn2 = document.createElement('button');
    btn2.type = 'button';
    btn2.textContent = '2 Cols';
    btn2.className = 'nu-btn nu-btn-ghost nu-btn-sm';
    btn2.onclick = () => this.setColumns(2);

    actions.appendChild(btn1);
    actions.appendChild(btn2);

    header.appendChild(title);
    header.appendChild(actions);

    const body = document.createElement('div');
    body.className = 'nb-row-body nb-row-drop';
    body.style.cssText = 'display:grid; grid-template-columns:repeat(12, 1fr); gap:12px; padding:12px; min-height:60px;';

    row.appendChild(header);
    row.appendChild(body);

    row.addEventListener('click', () => {
      if (this.builder && typeof this.builder.selectRow === 'function') {
        this.builder.selectRow(row);
      }
    });

    this.el = row;
    this.bodyEl = body;
    return row;
  }

  setColumns(cols) {
    if (!this.bodyEl) return;
    const cards = Array.from(this.bodyEl.children);
    const spanMap = { 1: 12, 2: 6, 3: 4, 4: 3 };
    const span = spanMap[cols] || 6;
    cards.forEach((card) => {
      card.style.gridColumn = `span ${span} / span ${span}`;
    });
  }
}
