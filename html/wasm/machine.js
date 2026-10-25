// The Machine view (#324 step 19): the engine's FORTH VM, built as
// WebAssembly (game-engine/machine), run one instruction at a time.
(function () {
    'use strict';

    const $ = (id) => document.getElementById(id);
    const hex = (n) => (n & 0xffff).toString(16).padStart(4, '0');
    const FUEL_PER = 100000;   // the engine burns 1 fuel per 100,000 instructions

    // What each ALU operation does to t (the top of the stack), n (the one
    // under it) and r (the top of the return stack); see game-engine/src/embed.c
    const ALU = ['t', 'n', 'r', '[t]', 'n→[t]', 't+n', 't*n', 't and n', 't or n', 't xor n',
        'invert t', 't-1', 't=0', 'n=t', 'n<t (unsigned)', 'n<t', 'n rshift t', 'n lshift t',
        'depth', 'rdepth', 'set depth', 'set rdepth', 'save', 'emit', 'key', 'um/mod', '/mod',
        'bye', 'callback', 'options'];
    const delta = (b) => (b === 2 ? -2 : b === 3 ? -1 : b);

    let m = null;          // the WebAssembly exports
    let names = new Map(); // code cell -> word name
    let starts = [];       // code cells, sorted, to name return addresses
    let inline = new Map();// one-instruction words: instruction -> name
    let running = false;
    let prepared = false;
    let rbase = 0;         // return stack depth under the word: the interpreter's
    let halt = -1;         // the return address that ends the word

    const mem = () => new Uint8Array(m.memory.buffer);
    function put(text) {
        const bytes = new TextEncoder().encode(text);
        const at = m.machine_input();
        mem().set(bytes.subarray(0, 16000), at);
        mem()[at + Math.min(bytes.length, 16000)] = 0;
        return at;
    }
    function str(at) {
        const b = mem();
        let end = at;
        while (b[end]) end++;
        return new TextDecoder().decode(b.subarray(at, end));
    }

    // Every word's name, from its execution token
    function learnNames() {
        names = new Map();
        inline = new Map();
        m.machine_eval(put('words\n'));
        const list = str(m.machine_output()).split(/\s+/).filter((w) => w && !/^\d+:$/.test(w));
        for (const w of list) {
            if (m.machine_eval(put("' " + w + '\n')) < 0 || m.machine_depth() < 1) continue;
            const cell = (m.machine_stack(0) & 0xffff) >> 1;
            m.machine_eval(put('drop\n'));
            if (!names.has(cell)) names.set(cell, w);
            const ins = m.machine_cell(cell) & 0xffff;
            if ((ins & 0xe000) === 0x6000 && (ins & 0x10) && !inline.has(ins)) inline.set(ins, w);
        }
        starts = [...names.keys()].sort((a, b) => a - b);
    }

    // The word a code cell is in
    function wordAt(cell) {
        let lo = 0, hi = starts.length - 1, best = -1;
        while (lo <= hi) {
            const mid = (lo + hi) >> 1;
            if (starts[mid] <= cell) { best = mid; lo = mid + 1; } else hi = mid - 1;
        }
        if (best < 0) return '?';
        const s = starts[best];
        return s === cell ? names.get(s) : names.get(s) + ' +' + (cell - s);
    }

    // A return address, as the word it goes back into; catch also keeps
    // numbers there, which aren't in any word
    function returnsTo(a) {
        const cell = (a & 0xffff) >> 1, start = startOf(cell);
        if (!(a & 1) && names.has(start) && cell - start < 200) return hex(a) + ' (' + wordAt(cell) + ')';
        return String(a);
    }

    // One instruction, as a player reads it
    function disassemble(ins) {
        ins &= 0xffff;
        if (ins & 0x8000) return { op: 'literal', text: String(ins & 0x7fff) };
        if ((ins & 0xe000) === 0x6000) {
            const parts = [];
            const alu = (ins >> 8) & 0x1f;
            if (inline.has(ins)) parts.push(inline.get(ins));
            else if (inline.has(ins & ~0x10)) parts.push(inline.get(ins & ~0x10));
            let what = 't ← ' + (ALU[alu] || '?');
            if (ins & 0x20) what = alu === 5 || alu === 6 ? 't ← low half of ' + ALU[alu] : 't ← n';
            const dd = delta(ins & 3), rd = delta((ins >> 2) & 3);
            const extra = [];
            if (ins & 0x80) extra.push('t→n');
            if (ins & 0x40) extra.push('t→r');
            if (dd) extra.push('stack ' + (dd > 0 ? '+' : '') + dd);
            if (rd) extra.push('return stack ' + (rd > 0 ? '+' : '') + rd);
            if (ins & 0x10) extra.push('return');
            return { op: 'alu', text: (parts.length ? parts[0] + ': ' : '') + what + (extra.length ? ', ' + extra.join(', ') : '') };
        }
        const target = ins & 0x1fff;
        if (ins & 0x4000) return { op: 'call', text: names.get(target) || wordAt(target), target };
        if (ins & 0x2000) return { op: '0branch', text: 'to ' + hex(target * 2), target };
        return { op: 'branch', text: 'to ' + hex(target * 2), target };
    }

    // The code of the word being run: from its start to its last return
    function listing(start) {
        const rows = [];
        let furthest = start;
        for (let cell = start; cell < start + 200; cell++) {
            const ins = m.machine_cell(cell) & 0xffff;
            const d = disassemble(ins);
            rows.push({ cell, ins, d });
            if (d.target !== undefined && d.op !== 'call' && d.target > furthest) furthest = d.target;
            const returns = (d.op === 'alu' && (ins & 0x10)) || (d.op === 'branch' && d.target < cell);
            if (returns && cell >= furthest) break;
            if (cell > start && names.has(cell + 1) && cell >= furthest) break;
        }
        return rows;
    }

    function stackText(n, get, label) {
        const out = [];
        for (let i = n - 1; i >= 0; i--) out.push(label ? label(get(i)) : String(get(i)));
        return out.length ? out.join('  ') : '(empty)';
    }

    let listed = -1;
    function show(message) {
        const done = m.machine_done();
        const pc = m.machine_pc();
        $('m-count').textContent = m.machine_count().toLocaleString();
        $('m-fuel').textContent = (m.machine_count() / FUEL_PER).toFixed(5);
        $('m-pc').textContent = done ? 'done' : hex(pc * 2) + ' (' + wordAt(pc) + ')';
        $('m-next').textContent = done ? '' : disassemble(m.machine_instruction()).op + ' ' + disassemble(m.machine_instruction()).text;
        $('m-stack').textContent = stackText(m.machine_depth(), m.machine_stack);
        // Only the word's own return addresses, not the interpreter's under it
        $('m-rstack').textContent = stackText(Math.max(0, m.machine_rdepth() - rbase), m.machine_rstack,
            (a) => a === halt ? hex(a) + ' (the end)' : returnsTo(a));
        $('m-output').textContent = str(m.machine_output());
        $('m-message').textContent = message || (done ? doneMessage() : '');

        // The listing of the word pc is in
        if (!done) {
            const start = starts.length ? startOf(pc) : pc;
            if (start !== listed) {
                listed = start;
                const body = $('m-listing');
                body.innerHTML = '';
                for (const row of listing(start)) {
                    const tr = document.createElement('tr');
                    tr.dataset.cell = row.cell;
                    for (const text of [hex(row.cell * 2), hex(row.ins), row.d.op, row.d.text]) {
                        const td = document.createElement('td');
                        td.textContent = text;
                        tr.appendChild(td);
                    }
                    body.appendChild(tr);
                }
                $('m-listing-name').textContent = names.get(start) || hex(start * 2);
            }
            for (const tr of $('m-listing').children)
                tr.style.background = Number(tr.dataset.cell) === pc ? 'rgba(255, 200, 0, 0.35)' : '';
        }
        $('m-step').disabled = $('m-step10').disabled = $('m-run').disabled = done || !prepared;
    }

    function startOf(cell) {
        let lo = 0, hi = starts.length - 1, best = -1;
        while (lo <= hi) {
            const mid = (lo + hi) >> 1;
            if (starts[mid] <= cell) { best = mid; lo = mid + 1; } else hi = mid - 1;
        }
        return best < 0 ? cell : starts[best];
    }

    function doneMessage() {
        const error = m.machine_depth() ? m.machine_stack(0) : 0;
        return error === 0
            ? 'Done: the word returned (the 0 on top is catch saying it ended without an error).'
            : 'Done: the word threw error ' + error + ' (on top, from catch).';
    }

    // Load: a fresh machine, the definitions, the starting stack, the word
    function load() {
        running = false;
        prepared = false;
        listed = -1;
        m.machine_init();
        let r = m.machine_eval(put($('m-defs').value + '\n'));
        if (r < 0) { fail('Your definitions stopped with error ' + r + '. ' + str(m.machine_output())); return; }
        learnNames();
        m.machine_init();
        m.machine_eval(put($('m-defs').value + '\n'));
        r = m.machine_eval(put($('m-start').value + '\n'));
        if (r < 0) { fail('The starting stack stopped with error ' + r + '.'); return; }
        const word = $('m-word').value.trim();
        r = m.machine_prepare(put(word));
        if (r < 0) { fail('There is no word called "' + word + '".'); return; }
        prepared = true;
        rbase = m.machine_rdepth() - 1;
        halt = m.machine_rstack(0);
        show('Ready to run ' + word + '.');
    }

    function fail(text) {
        prepared = false;
        $('m-message').textContent = text;
        $('m-step').disabled = $('m-step10').disabled = $('m-run').disabled = true;
    }

    function run() {
        if (running) { running = false; return; }
        running = true;
        $('m-run').textContent = 'Stop';
        const limit = Number($('m-limit').value) || 1000000;
        const tick = () => {
            if (!running || m.machine_done() || m.machine_count() >= limit) {
                running = false;
                $('m-run').textContent = 'Run';
                show(m.machine_done() ? '' : m.machine_count() >= limit ? 'Stopped at the limit of ' + limit.toLocaleString() + ' instructions.' : 'Stopped.');
                return;
            }
            m.machine_step(Math.min(5000, limit - m.machine_count()));
            show();
            requestAnimationFrame(tick);
        };
        tick();
    }

    async function start() {
        const response = await fetch($('machine').dataset.wasm);
        const wasm = await WebAssembly.instantiate(await response.arrayBuffer(), {});
        m = wasm.instance.exports;
        $('m-load').addEventListener('click', load);
        $('m-step').addEventListener('click', () => { m.machine_step(1); show(); });
        $('m-step10').addEventListener('click', () => { m.machine_step(10); show(); });
        $('m-run').addEventListener('click', run);
        load();
    }

    start().catch((e) => { $('m-message').textContent = 'The machine could not start: ' + e; });
})();
