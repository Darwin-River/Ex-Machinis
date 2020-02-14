<div class="container flash ">
    <div class="row ">
        <div class="col ">
            @if ($message = Session::get('success'))
                <div class="alert alert-success alert-block mt-3">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <strong>{{ $message }}</strong>
                </div>
                {{ Session::forget('success') }}
            @endif


            @if ($message = Session::get('error'))
                <div class="alert alert-danger alert-block fade show mt-3">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <strong>{{ $message }}</strong>
                </div>
                {{ Session::forget('error') }}
            @endif


            @if ($message = Session::get('warning'))
                <div class="alert alert-warning alert-block fade show mt-3">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <strong>{{ $message }}</strong>
                </div>
                {{ Session::forget('warning') }}
            @endif


            @if ($message = Session::get('info'))
                <div class="alert alert-info alert-block fade show mt-3">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    <strong>{{ $message }}</strong>
                </div>
                {{ Session::forget('info') }}
            @endif


            @if ($errors->any() && \Illuminate\Support\Facades\Auth::user())
                <div class="alert alert-danger fade show mt-3">
                    <button type="button" class="close" data-dismiss="alert">×</button>
                    Por favor chequee errores en el formulario
                </div>
            @endif
        </div>
    </div>
</div>