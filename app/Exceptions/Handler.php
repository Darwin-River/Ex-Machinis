<?php

namespace App\Exceptions;

use Throwable;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * This is a great spot to send exceptions to Sentry, Bugsnag, etc.
     *
     * @param  \Throwable  $exception
     * @return void
     */
    public function report(Throwable $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $exception
     * @return \Illuminate\Http\Response
     */
    public function render($request, Throwable $exception)
    {
        // The JSON API answers errors in its own shape, {"error": "..."},
        // whatever the request's Accept header (#380 round 35: a server
        // error came back as an HTML page, or as {"message": ...})
        if ($request->is('api/*')) {
            $response = parent::render($request, $exception);
            $status = $response->getStatusCode();
            if ($status >= 400 && !($exception instanceof \Illuminate\Validation\ValidationException)) {
                $message = $status >= 500
                    ? 'Something went wrong on the server. Try again in a minute; if it keeps happening, tell us.'
                    : ($exception->getMessage() ?: \Symfony\Component\HttpFoundation\Response::$statusTexts[$status] ?? 'Error');
                return response()->json(['error' => $message], $status, $response->headers->all());
            }
            return $response;
        }
        return parent::render($request, $exception);
    }
}
