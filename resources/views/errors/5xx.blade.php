<div>
    <!-- I have not failed. I've just found 10,000 ways that won't work. - Thomas Edison -->
</div>
@php
    $code = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
@endphp

@include('errors.page', ['code' => $code])
