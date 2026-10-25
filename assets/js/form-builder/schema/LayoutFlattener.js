/**
 * Layout Flattener
 * Flattens nested row layout structures into flat arrays with row_index and vice-versa.
 */
export function flattenLayoutFields(layout) {
  if (!Array.isArray(layout)) return [];
  const result = [];
  let rowIndex = 0;

  layout.forEach((node) => {
    if (node && node.type === 'row') {
      if (Array.isArray(node.children)) {
        node.children.forEach((child) => {
          if (child && typeof child === 'object') {
            result.push({ ...child, row_index: rowIndex });
          }
        });
      }
      rowIndex++;
    } else if (node && typeof node === 'object') {
      const idx = typeof node.row_index === 'number' ? node.row_index : rowIndex;
      result.push({ ...node, row_index: idx });
      if (typeof node.row_index !== 'number') {
        rowIndex++;
      }
    }
  });

  return result;
}

export function unflattenLayoutFields(flatFields) {
  if (!Array.isArray(flatFields)) return [];
  const rowMap = {};

  flatFields.forEach((field) => {
    if (!field || typeof field !== 'object') return;
    const rIdx = typeof field.row_index === 'number' ? field.row_index : 0;
    if (!rowMap[rIdx]) {
      rowMap[rIdx] = [];
    }
    const cleanField = { ...field };
    delete cleanField.row_index;
    rowMap[rIdx].push(cleanField);
  });

  const rowKeys = Object.keys(rowMap).map(Number).sort((a, b) => a - b);
  return rowKeys.map((k) => ({
    type: 'row',
    children: rowMap[k]
  }));
}
