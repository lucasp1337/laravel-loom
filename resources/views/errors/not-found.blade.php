@extends('loom::layouts.bare')

@section('title', 'Class not in index')

@section('content')
    <div class="loom-state__icon">?</div>
    <h1>Class not in index</h1>
    <p><code>{{ $fqcn }}</code> is not in the current snapshot. It may have been renamed or removed since the last scan.</p>
    <a class="loom-btn loom-btn--lg" href="{{ route('loom.dashboard') }}">Back to dashboard</a>
@endsection
