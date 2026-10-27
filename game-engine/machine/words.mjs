// Prints every word a drone's VM knows, one a line, sorted: the image's
// FORTH words, the callback words and the word library. The website warns
// when a script redefines one (resources/drone-words.txt, kept in step by
// make test). Usage: node words.mjs path/to/machine.wasm
import { readFileSync } from 'node:fs';

const wasm = await WebAssembly.instantiate(readFileSync(process.argv[2]), {});
const m = wasm.instance.exports;
const memory = () => new Uint8Array(m.memory.buffer);
const bytes = new TextEncoder().encode('words\n');
const at = m.machine_input();
m.machine_init();
memory().set(bytes, at);
memory()[at + bytes.length] = 0;
m.machine_eval(at);
const mem = memory();
let end = m.machine_output();
const start = end;
while (mem[end]) end++;
const words = new TextDecoder().decode(mem.subarray(start, end)).split(/\s+/)
    // words prints each wordlist's address first ("90:"); the machine's own
    // halt cell and the engine's internal dummy aren't for players
    .filter(w => w && !/^\d+:$/.test(w) && w !== 'machine-halt' && w !== 'dummy');
console.log([...new Set(words)].sort().join('\n'));
