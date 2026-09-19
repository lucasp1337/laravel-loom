@extends('loom::layouts.bare')

@section('title', '403 · Access denied')

@section('content')
    <div class="loom-state__icon loom-state__icon--danger">
        <svg width="20" height="20" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M4 4a4 4 0 0 1 8 0v2h.25c.966 0 1.75.784 1.75 1.75v5.5A1.75 1.75 0 0 1 12.25 15h-8.5A1.75 1.75 0 0 1 2 13.25v-5.5C2 6.784 2.784 6 3.75 6H4Zm8.25 3.5h-8.5a.25.25 0 0 0-.25.25v5.5c0 .138.112.25.25.25h8.5a.25.25 0 0 0 .25-.25v-5.5a.25.25 0 0 0-.25-.25ZM10.5 6V4a2.5 2.5 0 1 0-5 0v2Z"/></svg>
    </div>
    <h1>403 · Access denied</h1>
    <p>Loom is gated by the <code>viewLoom</code> gate, which currently returns false for your account. Outside the local environment, Loom only answers to users the gate allows.</p>
    <pre class="loom-code">use Illuminate\Support\Facades\Gate;

Gate::define('viewLoom', function ($user = null) {
    return in_array($user?->email, ['you@example.com']);
});</pre>
    <small>Define it in <code>AppServiceProvider::boot()</code>.</small>
@endsection
