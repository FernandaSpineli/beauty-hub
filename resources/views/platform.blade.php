@extends('layout')

@section('head')
    <script type="module" src="/storefront/platform.js"></script>
@endsection

@section('body')
    <header class="top">
        <span class="brand">Beauty Hub</span>
    </header>
    <main class="platform">
        <section class="hero">
            <h1>Sua loja de cosméticos, com estoque por lote e validade.</h1>
            <p class="lede">Cada loja tem seu endereço, catálogo, clientes e pagamentos via Stripe Connect, isolados no próprio banco de dados.</p>
        </section>

        @if ($stores->isNotEmpty())
            <section>
                <h2>Lojas</h2>
                <ul class="store-list">
                    @foreach ($stores as $store)
                        <li><a class="store-chip" data-color="{{ $store->theme_color }}" href="http://{{ $store->slug }}.{{ config('tenancy.central_domain') }}{{ parse_url(config('app.url'), PHP_URL_PORT) ? ':'.parse_url(config('app.url'), PHP_URL_PORT) : '' }}/">{{ $store->name }}</a></li>
                    @endforeach
                </ul>
            </section>
        @endif

        <section class="card narrow">
            <h2>Crie sua loja</h2>
            <form id="signup" class="form" novalidate>
                <label>Nome da loja <input name="name" required maxlength="120"></label>
                <label>Endereço
                    <span class="slug-field"><input name="slug" required pattern="[a-z0-9][a-z0-9-]{1,38}[a-z0-9]" placeholder="minhaloja"><span>.{{ config('tenancy.central_domain') }}</span></span>
                </label>
                <label>Seu nome <input name="owner_name" required maxlength="120"></label>
                <label>Seu e-mail <input name="owner_email" type="email" required></label>
                <label>Senha <input name="password" type="password" required minlength="8"></label>
                <label>Confirme a senha <input name="password_confirmation" type="password" required minlength="8"></label>
                <button type="submit" class="btn">Criar loja</button>
                <p id="signup-result" role="status"></p>
            </form>
        </section>
    </main>
@endsection
