<div>
    <!-- Well begun is half done. - Aristotle -->
</div>
@php
    $code = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 400;
@endphp

@include('errors.page', ['code' => $code])
