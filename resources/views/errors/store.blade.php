@extends('layout')

@section('body')
    <main class="platform">
        <section class="card narrow">
            <h1>{{ $status === 403 ? 'Loja indisponível' : 'Loja não encontrada' }}</h1>
            <p>{{ $status === 403 ? 'Esta loja está temporariamente fora do ar.' : 'Confira o endereço digitado.' }}</p>
            <p><a href="{{ config('app.url') }}">Ir para o Beauty Hub</a></p>
        </section>
    </main>
@endsection
