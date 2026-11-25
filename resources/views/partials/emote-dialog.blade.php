{{-- The emote button's pop-up (#1062), outside #app so Vue leaves it and
     its script alone: pick how you feel, say why if you like, and it is
     logged with your name (if logged in) and the game time --}}
<dialog id="emote-dialog" class="emote-dialog" aria-labelledby="emote-title">
    <form id="emote-form" method="dialog">
        <h2 id="emote-title" class="emote-title">How do you feel about the game right now?</h2>
        <fieldset class="emote-choices">
            <legend class="sr-only">Pick the emoji closest to how you feel</legend>
            @foreach(\App\Services\Emotes::CHOICES as $key => [$emoji, $label])
                <label class="emote-choice" title="{{ $label }}">
                    <input type="radio" name="emote" value="{{ $key }}" required>
                    <span class="emote-choice-face" aria-hidden="true">{{ $emoji }}</span>
                    <span class="emote-choice-label">{{ $label }}</span>
                </label>
            @endforeach
        </fieldset>
        <label for="emote-comment" class="emote-comment-label">Why do you feel that way? <span class="emote-optional">(optional)</span></label>
        <textarea id="emote-comment" name="comment" rows="3" maxlength="{{ \App\Services\Emotes::COMMENT_MAX }}"
                  placeholder="What made you feel this way?"></textarea>
        <p class="emote-note">We read these to make the game better. If you're logged in, your player name is saved with it.</p>
        <p class="emote-status" id="emote-status" role="status" aria-live="polite"></p>
        <div class="emote-actions">
            <button type="button" class="button button-secondary button-sm" id="emote-cancel">Cancel</button>
            <button type="submit" class="button button-primary button-sm" id="emote-submit">Submit</button>
        </div>
    </form>
</dialog>
<style>
    .nav-emote { background: transparent; border: 1px solid rgba(255, 255, 255, 0.1); border-radius: 999px; width: 2.2rem; height: 2.2rem;
                 font-size: 1.25rem; line-height: 1; padding: 0; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; }
    .nav-emote:hover, .nav-emote:focus-visible { border-color: #ffb547; outline: none; }
    .emote-dialog { background: #111729; color: #e6e9f2; border: 1px solid #263052; border-radius: 0.5rem; padding: 1.25rem;
                    width: min(30rem, calc(100vw - 2rem)); box-shadow: 0 12px 32px rgba(0, 0, 0, 0.45); }
    .emote-dialog::backdrop { background: rgba(5, 8, 15, 0.7); }
    .emote-title { font-size: 1.15rem; margin: 0 0 0.75rem; }
    .emote-choices { display: grid; grid-template-columns: repeat(5, 1fr); gap: 0.4rem; border: 0; padding: 0; margin: 0 0 1rem; }
    .emote-choice { display: flex; flex-direction: column; align-items: center; gap: 0.15rem; padding: 0.4rem 0.1rem; margin: 0;
                    border: 1px solid #263052; border-radius: 0.5rem; cursor: pointer; text-align: center; }
    .emote-choice input { position: absolute; opacity: 0; pointer-events: none; }
    .emote-choice-face { font-size: 1.6rem; line-height: 1.2; }
    .emote-choice-label { font-size: 0.7rem; color: #9aa3b8; }
    .emote-choice:hover { border-color: #4cc9f0; }
    .emote-choice:has(input:checked) { border-color: #ffb547; background: rgba(255, 181, 71, 0.12); }
    .emote-choice:has(input:focus-visible) { outline: 2px solid #4cc9f0; outline-offset: 2px; }
    .emote-comment-label { display: block; font-weight: 500; margin-bottom: 0.3rem; }
    .emote-optional { color: #9aa3b8; font-weight: 400; }
    .emote-dialog textarea { width: 100%; background: #0b0f1a; color: #e6e9f2; border: 1px solid #263052; border-radius: 0.5rem; padding: 0.5rem; }
    .emote-note { font-size: 0.8rem; color: #9aa3b8; margin: 0.5rem 0 0; }
    .emote-status { min-height: 1.2em; font-size: 0.9rem; margin: 0.4rem 0; }
    .emote-status.is-error { color: #ff6b6b; }
    .emote-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
    @media (max-width: 400px) { .emote-choices { grid-template-columns: repeat(4, 1fr); } }
</style>
<script>
(function () {
    const CHOICES = @json(array_map(fn ($c) => $c[0], \App\Services\Emotes::CHOICES));
    const KEY = 'exm-emote', VISITOR = 'exm-emote-visitor';
    const store = {
        get: (k) => { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set: (k, v) => { try { localStorage.setItem(k, v); } catch (e) {} },
    };
    const $ = (id) => document.getElementById(id);
    const setFace = (key) => {
        const face = $('nav-emote-face'), button = $('nav-emote');
        if (!face || !CHOICES[key]) return;
        face.textContent = CHOICES[key];
        button.dataset.emote = key;
    };
    // A player's last emote comes from the server; otherwise this browser's
    const button = $('nav-emote');
    if (button && !button.dataset.emote && CHOICES[store.get(KEY)]) setFace(store.get(KEY));

    const visitor = () => {
        let v = store.get(VISITOR);
        if (!v || !/^[A-Za-z0-9-]{8,36}$/.test(v)) {
            v = (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
                : Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12);
            store.set(VISITOR, v);
        }
        return v;
    };
    const status = (text, error) => { const s = $('emote-status'); s.textContent = text; s.classList.toggle('is-error', !!error); };

    // Vue re-renders #app once it loads, so the button is found by a click on the page, not bound
    document.addEventListener('click', (e) => {
        if (!e.target.closest('#nav-emote')) return;
        const dialog = $('emote-dialog'), current = $('nav-emote').dataset.emote;
        $('emote-form').reset();
        status('');
        const pick = current && dialog.querySelector('input[value="' + current + '"]');
        if (pick) pick.checked = true;
        if (dialog.showModal) dialog.showModal(); else dialog.setAttribute('open', '');
    });
    const close = () => { const d = $('emote-dialog'); if (d.close) d.close(); else d.removeAttribute('open'); };
    $('emote-cancel').addEventListener('click', close);
    $('emote-dialog').addEventListener('click', (e) => { if (e.target.id === 'emote-dialog') close(); });

    $('emote-form').addEventListener('submit', (e) => {
        e.preventDefault();
        const form = e.target, chosen = form.querySelector('input[name="emote"]:checked');
        if (!chosen) { status('Pick an emoji first.', true); return; }
        const submit = $('emote-submit');
        submit.disabled = true;
        status('Saving…');
        fetch(@json(url('emote')), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                       'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
            credentials: 'same-origin',
            body: JSON.stringify({ emote: chosen.value, comment: form.comment.value, visitor: visitor() }),
        }).then((r) => r.ok ? r.json() : Promise.reject(r.status)).then((res) => {
            store.set(KEY, res.emote);
            setFace(res.emote);
            status('Thanks! Noted.');
            setTimeout(close, 700);
        }).catch((code) => {
            status(code === 429 ? 'That was a lot of emotes: try again in a minute.'
                : code === 419 ? 'This page is old: reload it and try again.'
                : 'That didn\'t save. Try again in a moment.', true);
        }).finally(() => { submit.disabled = false; });
    });
})();
</script>
