// Vecteurs partagés JsonLogic appliqués à l'implémentation JavaScript du back-office (affichage conditionnel).
// Lancement : node backend/tests/js/jsonlogic.test.mjs
// Limite connue : JavaScript ne distingue pas 1 et 1.0 ; ces cas sont comparés sur la valeur.

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { apply, JsonLogicError } from '../../public/app/js/jsonlogic.js';

const here = dirname(fileURLToPath(import.meta.url));
const cases = JSON.parse(readFileSync(join(here, '../../../shared/rules-fixtures/jsonlogic.json'), 'utf8'));

let failed = 0;
for (const c of cases) {
  try {
    if (c.error) {
      let thrown = false;
      try { apply(c.logic, c.data); } catch (e) { thrown = e instanceof JsonLogicError; }
      if (!thrown) throw new Error('exception attendue');
      continue;
    }
    const got = apply(c.logic, c.data);
    if (JSON.stringify(got) !== JSON.stringify(c.expected)) throw new Error(`obtenu ${JSON.stringify(got)}, attendu ${JSON.stringify(c.expected)}`);
  } catch (e) {
    failed++;
    console.error(`ÉCHEC « ${c.name} » : ${e.message}`);
  }
}
console.log(`${cases.length - failed}/${cases.length} vecteurs JsonLogic réussis (JavaScript).`);
process.exit(failed ? 1 : 0);
