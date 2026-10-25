/**
 * Canvas Preview Renderer
 */
export class PreviewRenderer {
  static buildCardCanvasPreview(type, extra = {}) {
    const wrap = document.createElement('div');
    wrap.className = 'nb-card-preview-wrap';
    wrap.style.cssText = 'pointer-events:none; width:100%; font-size:12px; font-family:sans-serif; margin-top:4px; opacity:.95;';

    const cleanType = (type || 'text').toLowerCase();

    if (cleanType === 'text') {
      wrap.innerHTML = `<input type="text" class="nu-input" style="width:100%;height:32px;" placeholder="${extra.placeholder || 'Text input...'}" readonly>`;
    } else if (cleanType === 'textarea') {
      wrap.innerHTML = `<textarea class="nu-input" style="width:100%;height:54px;resize:none;" placeholder="${extra.placeholder || 'Textarea...'}" readonly></textarea>`;
    } else if (cleanType === 'number') {
      wrap.innerHTML = `<input type="number" class="nu-input" style="width:100%;height:32px;" placeholder="0" readonly>`;
    } else if (cleanType === 'select') {
      wrap.innerHTML = `<select class="nu-input" style="width:100%;height:32px;" disabled><option>-- Select option --</option></select>`;
    } else if (cleanType === 'checkbox') {
      wrap.innerHTML = `<label style="display:inline-flex;align-items:center;gap:6px;font-size:12px;"><input type="checkbox" disabled> ${extra.label || 'Checkbox'}</label>`;
    } else if (cleanType === 'button') {
      wrap.innerHTML = `<button type="button" class="nu-btn nu-btn-primary nu-btn-sm" style="height:32px;">${extra.label || 'Submit Button'}</button>`;
    } else {
      wrap.innerHTML = `<input type="text" class="nu-input" style="width:100%;height:32px;" placeholder="${cleanType} field..." readonly>`;
    }

    return wrap;
  }
}
