/**
 * Form Builder Event Bus
 */
export const bus = new EventTarget();

export function emit(name, detail = {}) {
  bus.dispatchEvent(new CustomEvent(name, { detail }));
}

export function on(name, callback) {
  bus.addEventListener(name, (e) => callback(e.detail, e));
}

export function off(name, callback) {
  bus.removeEventListener(name, callback);
}
