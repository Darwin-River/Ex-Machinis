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
    let catalog = {};      // query id -> {name, parameters, strings} (#380)
    let protocols = {};    // protocol id -> {name, parameters}
    let asking = false;    // a live query is on its way

    const mem = () => new Uint8Array(m.memory.buffer);
    function put(text) {
        const bytes = new TextEncoder().encode(text);
        const at = m.machine_input();
        mem().set(bytes.subarray(0, 16000), at);
        mem()[at + Math.min(bytes.length, 16000)] = 0;
        return at;
    }
    // Text as the engine passes it to a drone: each line over 79
    // characters re-wrapped at spaces (wrap.c, #380). null when a string
    // can't be split to fit.
    function wrapText(text) {
        const out = [];
        for (const line of text.split('\n')) {
            const at = m.machine_wrap(put(line));
            if (!at) return null;
            out.push(str(at));
        }
        return out.join('\n');
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

    // Live data (#380): the word called query. Take its arguments off the
    // stack as the engine does, run it on the game's data as the chosen
    // drone, write the rows into memory and leave what query leaves.
    async function answerQuery() {
        let id = m.machine_query_pending();
        if (id === -1) id = 0;
        const meta = catalog[id];
        if (!meta) { m.machine_query_answer(-3); logQuery(id, 'no such query: -3, its other arguments stay'); return; }
        if (m.machine_depth() < 2 + meta.parameters) {
            while (m.machine_depth() > 0) m.machine_pop();
            m.machine_query_answer(-1);
            logQuery(id, 'not enough on the stack: -1');
            return;
        }
        const size = m.machine_pop() & 0xffff;
        const addr = m.machine_pop() & 0xffff;
        const values = [];
        for (let i = 0; i < meta.parameters; i++) values.push(m.machine_pop());
        const params = new URLSearchParams({ id, drone: $('m-drone').value, size });
        values.forEach((v) => params.append('v[]', v));
        for (const k of meta.strings) {
            const at = values[k - 1] & 0xffff;
            let text = '';
            for (let i = 0, n = m.machine_read_byte(at); i < n; i++) text += String.fromCharCode(m.machine_read_byte(at + 1 + i));
            params.append('s[' + k + ']', text);
        }
        asking = true;
        show('Asking query ' + id + ' (' + meta.name + ') as drone ' + $('m-drone').value + '...');
        try {
            // Too many queries a minute (the website limits them): wait and ask again
            let response;
            for (let tries = 0; ; tries++) {
                response = await fetch($('machine').dataset.query + '?' + params, { headers: { Accept: 'application/json' } });
                if (response.status !== 429 || tries >= 5) break;
                show('Many queries this minute: waiting a few seconds before asking again...');
                await new Promise((resolve) => setTimeout(resolve, 5000));
            }
            const answer = await response.json();
            if (!response.ok) throw new Error(answer.error || answer.message || response.status);
            const bytes = atob(answer.bytes || '');
            for (let i = 0; i < bytes.length; i++) m.machine_write_byte(addr + i, bytes.charCodeAt(i));
            m.machine_query_answer(answer.code);
            logQuery(id, (answer.code >= 0 ? answer.code + ' rows' : 'failed or found nothing: ' + answer.code), answer.rows, values);
        } catch (e) {
            m.machine_query_answer(-2);
            logQuery(id, 'the website could not run it (' + e.message + '): -2', [], values);
        } finally {
            asking = false;
        }
    }

    // Each live query, newest first, with the rows it stored
    function logQuery(id, result, rows, values, name) {
        const meta = name ? { name } : (catalog[id] || { name: 'unknown' });
        const box = $('m-queries');
        const item = document.createElement('div');
        item.style.marginBottom = '0.75em';
        const head = document.createElement('div');
        head.textContent = (String(id).startsWith('perform') ? '' : 'query ') + id + ' (' + meta.name + ')' + (values && values.length ? ' with ' + values.join(' ') : '') + ': ' + result;
        item.appendChild(head);
        if (rows && rows.length) {
            const table = document.createElement('table');
            table.className = 'table-ticket';
            table.style.fontFamily = 'monospace';
            const cols = Object.keys(rows[0]);
            const tr = document.createElement('tr');
            for (const c of cols) { const th = document.createElement('th'); th.textContent = c; tr.appendChild(th); }
            table.appendChild(tr);
            for (const row of rows.slice(0, 20)) {
                const r = document.createElement('tr');
                for (const c of cols) { const td = document.createElement('td'); td.textContent = row[c] === null ? '' : row[c]; r.appendChild(td); }
                table.appendChild(r);
            }
            item.appendChild(table);
        }
        box.prepend(item);
    }

    // perform, live: take the protocol's arguments as the engine does and
    // leave -11 (nothing acts in the game from here)
    function answerPerform() {
        let id = m.machine_perform_pending();
        if (id === -1) id = 0;
        const meta = protocols[id];
        const n = meta ? meta.parameters : 0;
        const values = [];
        for (let i = 0; i < n && m.machine_depth() > 0; i++) values.push(m.machine_pop());
        m.machine_query_answer(-11);
        logQuery('perform ' + id, 'not done here (-11)', [], values, meta ? meta.name : 'no such protocol');
    }

    async function answerPending() {
        if (m.machine_perform_pending()) answerPerform();
        else if (m.machine_query_pending()) await answerQuery();
    }

    async function step(n) {
        if (asking) return;
        m.machine_step(n);
        await answerPending();
        show();
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
        const wrapped = wrapText($('m-defs').value);
        if (wrapped === null) { fail('A string in your definitions is too long to fit a line (79 characters): split it.'); return; }
        let r = m.machine_eval(put(wrapped + '\n'));
        if (r < 0) { fail('Your definitions stopped with error ' + r + '. ' + str(m.machine_output())); return; }
        learnNames();
        m.machine_init();
        m.machine_set_live($('m-live').checked ? 1 : 0);
        $('m-queries').innerHTML = '';
        m.machine_eval(put(wrapped + '\n'));
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
        const tick = async () => {
            if (!running || m.machine_done() || m.machine_count() >= limit) {
                running = false;
                $('m-run').textContent = 'Run';
                show(m.machine_done() ? '' : m.machine_count() >= limit ? 'Stopped at the limit of ' + limit.toLocaleString() + ' instructions.' : 'Stopped.');
                return;
            }
            m.machine_step(Math.min(5000, limit - m.machine_count()));
            await answerPending();
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
        $('m-step').addEventListener('click', () => step(1));
        $('m-step10').addEventListener('click', () => step(10));
        // The game's queries, for live data (#380)
        try {
            catalog = await (await fetch($('machine').dataset.queries, { headers: { Accept: 'application/json' } })).json();
            protocols = await (await fetch($('machine').dataset.protocols, { headers: { Accept: 'application/json' } })).json();
        } catch (e) {
            $('m-live').checked = false;
            $('m-live').disabled = true;
        }
        $('m-vantage').addEventListener('change', () => { if ($('m-vantage').value) $('m-drone').value = $('m-vantage').value; });
        $('m-live').addEventListener('change', load);
        $('m-run').addEventListener('click', run);
        load();
    }

    start().catch((e) => { $('m-message').textContent = 'The machine could not start: ' + e; });
})();
