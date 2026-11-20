{{-- The Lua editor (#852): once a page, the names it colours as the game's, the
     same LuaHighlight colours. A textarea marked data-lua-editor becomes the editor --}}
@once
<script>window.exLuaGameNames = @json(\App\Services\LuaHighlight::gameNames());</script>
@endonce
