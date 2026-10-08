// Resolve extensionless TS imports under plain node --test
// (bundlers resolve these natively; node needs explicit extensions).
import { existsSync } from 'node:fs';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

export async function resolve(specifier, context, next) {
  try {
    return await next(specifier, context);
  } catch (err) {
    if (!specifier.startsWith('./') && !specifier.startsWith('../')) throw err;
    const base = path.resolve(path.dirname(fileURLToPath(context.parentURL)), specifier);
    for (const cand of [`${base}.ts`, `${base}.tsx`, `${base}/index.ts`]) {
      if (existsSync(cand)) return { url: pathToFileURL(cand).href, shortCircuit: true };
    }
    throw err;
  }
}
