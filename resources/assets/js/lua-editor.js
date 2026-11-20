// The Lua editor (#852): every box where a player types Lua (a <textarea
// data-lua-editor>) becomes a CodeMirror editor with line numbers, bracket
// matching and the site's Lua colours, the same as the code it shows
// (App\Services\LuaHighlight, pre.lua in _components.scss).
//
// The textarea stays the form's field: hidden, and given the editor's text on
// every change (with an input event, for scripts that listen). A script that
// sets the textarea's value and fires an input event on it updates the editor.
// If this file never loads, the plain textarea is still there and still works.
import { EditorView, keymap, lineNumbers, highlightActiveLineGutter, highlightSpecialChars, drawSelection,
    placeholder } from '@codemirror/view';
import { EditorState } from '@codemirror/state';
import { StreamLanguage, HighlightStyle, syntaxHighlighting, bracketMatching, indentOnInput, indentUnit } from '@codemirror/language';
import { defaultKeymap, history, historyKeymap, indentWithTab } from '@codemirror/commands';
import { lua } from '@codemirror/legacy-modes/mode/lua';
import { tags } from '@lezer/highlight';

// As in LuaHighlight
const KEYWORDS = new Set(['and', 'break', 'do', 'else', 'elseif', 'end', 'for', 'function', 'goto', 'if', 'in',
    'local', 'not', 'or', 'repeat', 'return', 'then', 'until', 'while']);
const LITERALS = new Set(['true', 'false', 'nil']);
const BUILTINS = new Set(['assert', 'error', 'getmetatable', 'ipairs', 'next', 'pairs', 'pcall', 'rawequal',
    'rawget', 'rawlen', 'rawset', 'select', 'setmetatable', 'tonumber', 'tostring', 'type', 'unpack', 'xpcall',
    'string', 'table', 'math', 'utf8', 'coroutine', 'os', '_G', '_ENV', 'self']);
const OPERATORS = ['...', '..', '==', '~=', '<=', '>=', '//', '::', '<<', '>>'];
const OPENS = new Set(['function', 'if', 'repeat', 'do', '(', '{']);
const CLOSES = new Set(['end', 'until', ')', '}']);

/**
 * Lua as LuaHighlight reads it. The legacy Lua mode reads strings and comments
 * (and indents); names, numbers and operators are read here, so that x.fuel,
 * the game's names and a function's own name colour as they do on the site
 */
function luaLanguage(gameNames) {
    const normal = lua.startState().cur;
    return StreamLanguage.define({
        name: 'lua',
        startState: () => Object.assign(lua.startState(), { afterFunction: false, afterDot: false }),
        token(stream, state) {
            if (state.cur !== normal) return lua.token(stream, state);   // inside a string or comment
            if (stream.eatSpace()) return null;
            const rest = stream.string.slice(stream.pos);
            if (/^(--|["']|\[=*\[)/.test(rest)) {
                const style = lua.token(stream, state);
                if (style !== 'comment') state.afterFunction = state.afterDot = false;
                return style;
            }
            const name = stream.match(/^[A-Za-z_][A-Za-z0-9_]*/);
            if (name) {
                const word = name[0];
                let style = null;
                if (KEYWORDS.has(word)) {
                    style = 'keyword';
                    if (OPENS.has(word)) state.indentDepth++;
                    else if (CLOSES.has(word)) state.indentDepth--;
                } else if (LITERALS.has(word)) style = 'atom';
                else if (state.afterFunction) style = 'variableName.definition';
                else if (state.afterDot) style = null;
                else if (BUILTINS.has(word)) style = 'variableName.standard';
                else if (gameNames.has(word)) style = 'variableName.special';
                state.afterFunction = word === 'function' || (state.afterFunction && style !== 'keyword' && style !== 'atom');
                state.afterDot = false;
                return style;
            }
            if (stream.match(/^(?:0[xX][0-9a-fA-F.]*(?:[pP][+-]?\d+)?|\.?\d[0-9.]*(?:[eE][+-]?\d+)?)/)) {
                state.afterFunction = state.afterDot = false;
                return 'number';
            }
            if (!OPERATORS.some(op => stream.match(op))) stream.next();
            const op = stream.current();
            if (OPENS.has(op)) state.indentDepth++;
            else if (CLOSES.has(op)) state.indentDepth--;
            state.afterFunction = state.afterFunction && (op === '.' || op === ':');
            state.afterDot = op === '.' || op === ':';
            return 'operator';
        },
        indent: lua.indent,
        languageData: lua.languageData,
    });
}

// The classes of pre.lua, so the colours are the site's
const colours = HighlightStyle.define([
    { tag: tags.keyword, class: 'k' },
    { tag: tags.atom, class: 'l' },
    { tag: tags.number, class: 'n' },
    { tag: tags.string, class: 's' },
    { tag: tags.comment, class: 'c' },
    { tag: tags.operator, class: 'o' },
    { tag: tags.standard(tags.variableName), class: 'b' },
    { tag: tags.special(tags.variableName), class: 'g' },
    { tag: tags.definition(tags.variableName), class: 'f' },
]);

const theme = EditorView.theme({
    '&': { backgroundColor: '#0f1424', border: '1px solid #263052', borderRadius: '8px', fontSize: '14px' },
    '&.cm-focused': { outline: 'none', borderColor: '#4cc9f0', boxShadow: '0 0 0 .2rem rgba(76, 201, 240, .25)' },
    '.cm-scroller': { fontFamily: 'inherit', lineHeight: '1.55', overflow: 'auto' },
    '.cm-content': { caretColor: '#ffb547', padding: '6px 0' },
    '.cm-cursor, .cm-dropCursor': { borderLeftColor: '#ffb547', borderLeftWidth: '2px' },
    '&.cm-focused > .cm-scroller > .cm-selectionLayer .cm-selectionBackground, .cm-selectionBackground, .cm-content ::selection':
        { backgroundColor: 'rgba(76, 201, 240, .3)' },
    '.cm-gutters': { backgroundColor: '#111729', color: '#6b7590', border: 'none', borderRight: '1px solid #263052',
        borderRadius: '8px 0 0 8px' },
    '.cm-activeLineGutter': { backgroundColor: '#19213a', color: '#e6e9f2' },
    '&.cm-focused .cm-matchingBracket': { backgroundColor: 'rgba(255, 181, 71, .25)', outline: '1px solid #ffb547' },
    '&.cm-focused .cm-nonmatchingBracket': { backgroundColor: 'rgba(255, 107, 107, .3)' },
    '.cm-placeholder': { color: '#6b7590' },
    // Phones: 16px stops iOS zooming in on the box
    '@media (max-width: 760px)': { '&': { fontSize: '16px' } },
}, { dark: true });

function upgrade(box, language) {
    const rows = parseInt(box.getAttribute('rows'), 10) || 10;
    const max = parseInt(box.getAttribute('maxlength'), 10) || 0;
    const label = box.getAttribute('aria-label') || (box.labels && box.labels[0] ? box.labels[0].textContent.trim() : 'Lua script');
    let syncing = false;

    const view = new EditorView({
        state: EditorState.create({
            doc: box.value,
            extensions: [
                lineNumbers(),
                highlightActiveLineGutter(),
                highlightSpecialChars(),
                history(),
                drawSelection(),
                indentOnInput(),
                bracketMatching(),
                indentUnit.of('    '),
                language,
                syntaxHighlighting(colours),
                keymap.of([...defaultKeymap, ...historyKeymap, indentWithTab]),
                theme,
                // Narrow screens wrap long lines rather than scroll sideways
                window.matchMedia('(max-width: 760px)').matches ? EditorView.lineWrapping : [],
                box.placeholder ? placeholder(box.placeholder) : [],
                // No longer than the box allows (but never stuck if it starts longer)
                max ? EditorState.changeFilter.of(tr => tr.newDoc.length <= max || tr.newDoc.length <= tr.startState.doc.length) : [],
                EditorView.editorAttributes.of({ class: 'lua lua-editor' }),
                EditorView.contentAttributes.of({ 'aria-label': label, 'aria-multiline': 'true', spellcheck: 'false',
                    autocapitalize: 'off', autocorrect: 'off' }),
                EditorView.updateListener.of(update => {
                    if (!update.docChanged || syncing) return;
                    box.value = update.state.doc.toString();
                    syncing = true;
                    box.dispatchEvent(new Event('input', { bubbles: true }));
                    syncing = false;
                }),
            ],
        }),
    });
    box.parentNode.insertBefore(view.dom, box);
    view.dom.style.minHeight = (rows * 1.55 + 1) + 'em';
    view.scrollDOM.style.minHeight = 'inherit';
    view.dom.style.maxHeight = '75vh';

    // The textarea: hidden but still the form's field. Kept in the page (not
    // display: none) so a required, empty box still stops the form
    box.classList.add('lua-editor-source');
    box.setAttribute('aria-hidden', 'true');
    box.tabIndex = -1;
    // Its label, or the form's check of a required box, sends the player to the editor
    const toEditor = () => setTimeout(() => view.focus(), 0);
    box.addEventListener('focus', toEditor);
    box.addEventListener('invalid', toEditor);
    box.addEventListener('input', () => {
        if (syncing) return;
        const text = box.value;
        if (text === view.state.doc.toString()) return;
        syncing = true;
        // A new script (a starter card) is read from its top
        view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: text }, selection: { anchor: 0 } });
        view.scrollDOM.scrollTop = 0;
        syncing = false;
    });
    box.form && box.form.addEventListener('reset', () => setTimeout(() => box.dispatchEvent(new Event('input')), 0));
    return view;
}

export function upgradeAll(boxes) {
    const language = luaLanguage(new Set(window.exLuaGameNames || []));
    boxes.forEach(box => {
        if (box.dataset.luaEditorReady) return;
        box.dataset.luaEditorReady = '1';
        try {
            upgrade(box, language);
        } catch (e) {
            // Leave the plain textarea as it was
            box.classList.remove('lua-editor-source');
            box.removeAttribute('aria-hidden');
            box.removeAttribute('tabindex');
            if (window.console) console.error('Lua editor', e);
        }
    });
}
