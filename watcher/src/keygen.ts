#!/usr/bin/env node
/**
 * CLI: npm run keygen [-- --words 24] [-- --mnemonic "..."]
 * Печатает мнемонику, EVM_XPUB / TRON_XPUB (account-level) и первые 3 адреса на сеть.
 * Не требует ни BACKEND_URL, ни уже настроенных xpub — работает офлайн (SPEC §3).
 */
import { Deriver, generateKeys, xpubsFromMnemonic } from './derivation.js';
import type { NetworkCode } from './types.js';

function arg(name: string): string | undefined {
  const argv = process.argv.slice(2);
  const eq = argv.find((a) => a.startsWith(`--${name}=`));
  if (eq) return eq.slice(name.length + 3);
  const idx = argv.indexOf(`--${name}`);
  if (idx >= 0 && idx + 1 < argv.length) return argv[idx + 1];
  return undefined;
}

function main(): void {
  const provided = arg('mnemonic');
  const words = arg('words') === '24' ? 24 : 12;

  const keys = provided
    ? { mnemonic: provided.trim(), ...xpubsFromMnemonic(provided.trim()) }
    : generateKeys(words);

  const deriver = new Deriver({ evm: keys.evmXpub, tron: keys.tronXpub });

  const lines: string[] = [];
  lines.push('');
  lines.push('=== CryptoPay watcher keygen ===');
  lines.push('');
  lines.push('MNEMONIC (сохраните офлайн, в сервисы НЕ попадает):');
  lines.push(`  ${keys.mnemonic}`);
  lines.push('');
  lines.push('Вставьте в .env:');
  lines.push('');
  lines.push(`EVM_XPUB=${keys.evmXpub}`);
  lines.push(`TRON_XPUB=${keys.tronXpub}`);
  lines.push('');
  lines.push('Проверочные адреса:');
  for (const network of ['ethereum', 'bsc', 'tron'] as NetworkCode[]) {
    lines.push(`  ${network}:`);
    for (const d of deriver.deriveBatch(network, 0, 3)) {
      lines.push(`    ${d.path}  ${d.address}`);
    }
  }
  lines.push('');
  lines.push('ethereum и bsc используют один xpub — совпадение адресов ожидаемо (SPEC §3).');
  lines.push('');

  process.stdout.write(`${lines.join('\n')}\n`);
}

try {
  main();
} catch (err) {
  process.stderr.write(`keygen failed: ${(err as Error).message}\n`);
  process.exit(1);
}
