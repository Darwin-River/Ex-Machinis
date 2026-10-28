// Loads each starter script (examples/starters) on the machine's
// WebAssembly build, set up as a drone's VM is, line by line as the engine
// reads a released script, and fails on any line that leaves an error: a
// starter that doesn't load leaves its drones doing nothing.
// Usage: node check_starters.mjs path/to/machine.wasm starter.fth...
import { readFileSync } from 'node:fs';

const wasm = await WebAssembly.instantiate(readFileSync(process.argv[2]), {});
const m = wasm.instance.exports;
const memory = () => new Uint8Array(m.memory.buffer);
function put(text) {
    const bytes = new TextEncoder().encode(text);
    const at = m.machine_input();
    memory().set(bytes, at);
    memory()[at + bytes.length] = 0;
    return at;
}
function str(at) {
    const mem = memory();
    let end = at;
    while (mem[end]) end++;
    return new TextDecoder().decode(mem.subarray(at, end));
}

let failed = 0;
for (const file of process.argv.slice(3)) {
    m.machine_init();
    readFileSync(file, 'utf8').split('\n').forEach((line, i) => {
        // Wrapped as the engine wraps a long line (wrap.c, #380)
        const wrapped = m.machine_wrap(put(line));
        if (!wrapped) {
            console.log(`${file}:${i + 1}: a string or word is too long to fit a line (79 characters)`);
            failed++;
            return;
        }
        const r = m.machine_eval(put(str(wrapped) + '\n'));
        const out = str(m.machine_output());
        // The VM reports an error as the number and a question mark
        if (r < 0 || /-\d+\?/.test(out)) {
            console.log(`${file}:${i + 1}: ${out.trim() || r}`);
            failed++;
        }
        // Redefining a word the drone already knows (report, load, hold...)
        // replaces it for everything compiled after: almost always a mistake
        const redefined = out.match(/(\S+) redefined/);
        if (redefined) {
            console.log(`${file}:${i + 1}: ${redefined[1]}: the drone (or your script) already has a word of that name; pick another`);
            failed++;
        }
    });
    // It must define launched
    if (m.machine_eval(put("' launched drop\n")) < 0 || /\?/.test(str(m.machine_output()))) {
        console.log(`${file}: launched is not defined`);
        failed++;
    }
}
if (failed) {
    console.log(`starters: ${failed} problem(s)`);
    process.exit(1);
}
console.log(`starters: all ${process.argv.length - 3} load cleanly on the drone VM`);
