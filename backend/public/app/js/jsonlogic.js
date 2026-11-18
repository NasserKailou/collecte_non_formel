// Évaluateur JsonLogic (sous-ensemble strict) — portage de backend/src/Rules/JsonLogic.php pour l'affichage des
// questions conditionnelles dans la saisie web. La validation reste faite par le serveur ; cette implémentation est
// néanmoins vérifiée par les mêmes vecteurs partagés (shared/rules-fixtures/jsonlogic.json, voir tests/js).

const ARRAY_OPS = new Set(['map', 'filter', 'reduce', 'all', 'some', 'none']);
const INT_KEY = /^(0|-?[1-9][0-9]*)$/;

export class JsonLogicError extends Error {}

const isObj = (v) => v !== null && typeof v === 'object' && !Array.isArray(v);
const isEmptyObj = (v) => isObj(v) && Object.keys(v).length === 0;
const isNum = (v) => typeof v === 'number';

export function truthy(v) {
  if (Array.isArray(v)) return v.length > 0;
  if (isObj(v)) return Object.keys(v).length > 0;
  return !(v === null || v === undefined || v === false || v === '' || v === 0);
}

const phpString = (v) => (v === null || v === undefined ? '' : v === true ? '1' : v === false ? '' : String(v));

export function varPath(path, data, def = null) {
  if (path === null || path === undefined || path === '') return data;
  let cur = data;
  for (const seg of phpString(path).split('.')) {
    if (Array.isArray(cur)) {
      if (!INT_KEY.test(seg) || Number(seg) < 0 || Number(seg) >= cur.length) return def;
      cur = cur[Number(seg)];
    } else if (isObj(cur) && Object.prototype.hasOwnProperty.call(cur, seg)) {
      cur = cur[seg];
    } else {
      return def;
    }
  }
  return cur ?? def;
}

function deepEquals(a, b) {
  const ea = (Array.isArray(a) && a.length === 0) || isEmptyObj(a);
  const eb = (Array.isArray(b) && b.length === 0) || isEmptyObj(b);
  if (ea || eb) return ea && eb;
  if (Array.isArray(a) && Array.isArray(b)) return a.length === b.length && a.every((x, i) => deepEquals(x, b[i]));
  if (isObj(a) && isObj(b)) {
    const ka = Object.keys(a);
    return ka.length === Object.keys(b).length && ka.every((k) => Object.prototype.hasOwnProperty.call(b, k) && deepEquals(a[k], b[k]));
  }
  return a === b;
}

const equals = (a, b) => (isNum(a) && isNum(b) ? a === b : deepEquals(a, b));

function compare(a, b, test) {
  if (isNum(a) && isNum(b)) return test(Math.sign(a - b));
  if (typeof a === 'string' && typeof b === 'string') return test(a < b ? -1 : a > b ? 1 : 0);
  return false;
}

function compareChain(v, test) {
  if (v.length < 2) return false;
  for (let i = 0; i < v.length - 1; i++) if (!compare(v[i], v[i + 1], test)) return false;
  return true;
}

export function apply(logic, data = null) {
  if (Array.isArray(logic)) return logic.map((l) => apply(l, data));
  if (isEmptyObj(logic)) return [];
  if (!isObj(logic) || Object.keys(logic).length !== 1 || INT_KEY.test(Object.keys(logic)[0])) return logic;

  const op = Object.keys(logic)[0];
  const raw = logic[op];
  const args = Array.isArray(raw) ? raw : isEmptyObj(raw) ? [] : [raw];

  switch (op) {
    case 'var':
      return varPath(apply(args.length ? args[0] : '', data), data, args.length > 1 ? apply(args[1], data) : null);
    case 'if': {
      const n = args.length;
      for (let i = 0; i + 1 < n; i += 2) if (truthy(apply(args[i], data))) return apply(args[i + 1], data);
      return n % 2 === 1 ? apply(args[n - 1], data) : null;
    }
    case 'and': {
      let last = true;
      for (const a of args) { last = apply(a, data); if (!truthy(last)) return last; }
      return last;
    }
    case 'or': {
      let last = false;
      for (const a of args) { last = apply(a, data); if (truthy(last)) return last; }
      return last;
    }
    default:
  }
  if (ARRAY_OPS.has(op)) {
    const r = apply(args.length ? args[0] : null, data);
    const items = Array.isArray(r) ? r : isObj(r) ? Object.values(r) : [];
    const fn = args.length > 1 ? args[1] : null;
    switch (op) {
      case 'map': return items.map((it) => apply(fn, it));
      case 'filter': return items.filter((it) => truthy(apply(fn, it)));
      case 'reduce': {
        let acc = apply(args.length > 2 ? args[2] : null, data);
        for (const it of items) acc = apply(fn, { current: it, accumulator: acc });
        return acc;
      }
      case 'all': return items.length > 0 && items.every((it) => truthy(apply(fn, it)));
      case 'some': return items.some((it) => truthy(apply(fn, it)));
      default: return !items.some((it) => truthy(apply(fn, it)));
    }
  }
  const v = args.map((a) => apply(a, data));
  const at = (i) => (i < v.length ? v[i] : null);
  switch (op) {
    case '==': return equals(at(0), at(1));
    case '!=': return !equals(at(0), at(1));
    case '<': return compareChain(v, (c) => c < 0);
    case '<=': return compareChain(v, (c) => c <= 0);
    case '>': return compare(at(0), at(1), (c) => c > 0);
    case '>=': return compare(at(0), at(1), (c) => c >= 0);
    case '!': return !truthy(at(0));
    case '!!': return truthy(at(0));
    case 'in': {
      const hay = at(1);
      if (Array.isArray(hay)) return hay.some((x) => equals(at(0), x));
      if (isObj(hay)) return Object.values(hay).some((x) => equals(at(0), x));
      return typeof hay === 'string' && typeof at(0) === 'string' && hay.includes(at(0));
    }
    case '+': {
      let s = 0;
      for (const x of v) { if (x === null || x === undefined) continue; if (!isNum(x)) return null; s += x; }
      return s;
    }
    case '-': {
      if (v.some((x) => x !== null && x !== undefined && !isNum(x))) return null;
      return v.length === 1 ? -(at(0) ?? 0) : (at(0) ?? 0) - (at(1) ?? 0);
    }
    case '*': {
      if (!v.length || v.some((x) => !isNum(x))) return null;
      return v.reduce((p, x) => p * x, 1);
    }
    case '/': return isNum(at(0)) && isNum(at(1)) && at(1) !== 0 ? at(0) / at(1) : null;
    case 'max': return !v.length || v.some((x) => !isNum(x)) ? null : Math.max(...v);
    case 'min': return !v.length || v.some((x) => !isNum(x)) ? null : Math.min(...v);
    default: throw new JsonLogicError(`Opérateur non autorisé : « ${op} »`);
  }
}
