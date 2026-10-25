// Runs the machine's WebAssembly build (#324 step 19) on the cases in
// cases.txt and prints the same trace as the native trace_machine, so CI
// can compare them line by line. Usage: node compare.mjs path/to/machine.wasm
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
const hex = (n) => n.toString(16).padStart(4, '0');

function traceCase(setup, word) {
    m.machine_init();
    m.machine_eval(put(setup));
    const r = m.machine_prepare(put(word));
    console.log(`case ${word}: prepare ${r}`);
    if (r < 0) return;
    for (let i = 0; i < 100000 && !m.machine_done(); i++) {
        let line = `${m.machine_count()} pc=${hex(m.machine_pc())} ins=${hex(m.machine_instruction())} d=${m.machine_depth()}`;
        for (let s = 0; s < m.machine_depth() && s < 4; s++) line += ` ${m.machine_stack(s)}`;
        console.log(`${line} r=${m.machine_rdepth()}`);
        m.machine_step(1);
    }
    const depth = m.machine_depth();
    console.log(`done ${m.machine_done()} count ${m.machine_count()} depth ${depth} top ${depth ? m.machine_stack(0) : 0} output [${str(m.machine_output())}]`);

    // The same word in one run, as the page's Run button does it
    m.machine_init();
    m.machine_eval(put(setup));
    m.machine_prepare(put(word));
    const ran = m.machine_step(1000000);
    console.log(`run ${ran} count ${m.machine_count()} done ${m.machine_done()}`);
}

const lines = readFileSync('cases.txt', 'utf8').split('\n');
for (let i = 0; i < lines.length; i++) {
    const word = lines[i];
    if (!word) continue;
    let setup = '';
    for (i++; i < lines.length && lines[i] !== '--'; i++) setup += lines[i] + '\n';
    traceCase(setup, word);
}
