{{-- The emote button (#1062): how the player feels about the game. A smile
     until they say, then their last emote: a player's from the database, a
     visitor's from this browser (set by partials.emote-dialog's script) --}}
@php($emotePlayer = auth()->check() && auth()->user()->login !== null ? auth()->user() : null)
@php($emoteLast = $emotePlayer ? \App\Services\Emotes::lastOf((int) $emotePlayer->user_id) : null)
<button type="button" class="nav-emote" id="nav-emote" data-emote="{{ $emoteLast ?? '' }}"
        aria-haspopup="dialog" aria-controls="emote-dialog"
        title="How do you feel about the game?" aria-label="How do you feel about the game?">
    <span id="nav-emote-face" aria-hidden="true">{{ \App\Services\Emotes::emoji($emoteLast) }}</span>
</button>
