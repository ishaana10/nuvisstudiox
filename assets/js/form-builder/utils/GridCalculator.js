/**
 * Grid Calculator Utilities
 */
export function calculateColSpan(span = 6) {
  const col = Math.min(12, Math.max(1, parseInt(span, 10) || 6));
  return `span ${col} / span ${col}`;
}

export function calculateGridStartColumn(align = 'left', totalCols = 12, rowSpanSum = 6) {
  if (align === 'center') {
    return Math.max(1, Math.floor((totalCols - rowSpanSum) / 2) + 1);
  } else if (align === 'right') {
    return Math.max(1, totalCols - rowSpanSum + 1);
  }
  return 1;
}
