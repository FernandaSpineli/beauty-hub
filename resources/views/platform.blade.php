@extends('layout')

@section('head')
    <script type="module" src="/storefront/platform.js"></script>
@endsection

@php
    $port = parse_url(config('app.url'), PHP_URL_PORT);
    $suffix = config('tenancy.central_domain').($port ? ':'.$port : '');
@endphp

@section('body')
    <header class="top">
        <span class="brand">Beauty Hub</span>
    </header>
    <main class="platform">
        <div>
            <section class="hero">
                <h1>Sua loja de cosméticos, com a validade de cada lote sob controle.</h1>
                <p class="lede">Cada loja tem endereço, catálogo, clientes e pagamentos próprios, via Stripe Connect. O estoque sai sempre do lote que vence primeiro.</p>
            </section>

            @if ($stores->isNotEmpty())
                <section class="stores">
                    <h2>Lojas abertas</h2>
                    <ul class="store-list">
                        @foreach ($stores as $store)
                            <li><a class="store-chip" data-color="{{ $store->theme_color }}" href="http://{{ $store->slug }}.{{ $suffix }}/">{{ $store->name }}<small>{{ $store->slug }}.{{ $suffix }}</small></a></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>

        <section class="card signup">
            <h2>Crie sua loja</h2>
            <form id="signup" class="form" novalidate>
                <label>Nome da loja <input name="name" required maxlength="120" autocomplete="organization"></label>
                <label>Endereço
                    <span class="slug-field"><input name="slug" required pattern="[a-z0-9][a-z0-9-]{1,38}[a-z0-9]" placeholder="minhaloja" autocapitalize="none" spellcheck="false"><span>.{{ config('tenancy.central_domain') }}</span></span>
                </label>
                <label>Seu nome <input name="owner_name" required maxlength="120" autocomplete="name"></label>
                <label>Seu e-mail <input name="owner_email" type="email" required autocomplete="email"></label>
                <label>Senha <input name="password" type="password" required minlength="8" autocomplete="new-password"></label>
                <label>Confirme a senha <input name="password_confirmation" type="password" required minlength="8" autocomplete="new-password"></label>
                <button type="submit" class="btn">Criar loja</button>
                <p id="signup-result" role="status"></p>
            </form>
        </section>
    </main>
@endsection
