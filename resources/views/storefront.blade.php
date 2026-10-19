@extends('layout')

@section('head')
    <meta name="theme-color" content="{{ $store['theme_color'] }}">
    <script type="module" src="/storefront/app.js"></script>
@endsection

@section('body-attributes')data-store="{{ json_encode($store) }}"@endsection

@section('body')
    <header class="top">
        <a href="/" class="brand" data-link>{{ $store['name'] }}</a>
        <nav id="nav" aria-label="Principal"></nav>
    </header>
    <main id="app" aria-live="polite">
        <p class="muted">Carregando…</p>
    </main>
    <footer class="foot">
        {{ $store['name'] }} · loja de demonstração do <a href="{{ config('app.url') }}">Beauty Hub</a>.
        Cada chamada à API aparece no console do navegador.
    </footer>
@endsection
