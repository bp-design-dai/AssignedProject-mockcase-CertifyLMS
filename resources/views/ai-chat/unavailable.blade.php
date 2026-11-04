@extends('layouts.app')
@section('title', 'AI 相談')
@section('content')
    <h1 class="text-2xl font-display font-bold">AI 相談</h1>
    <div class="mt-6"><x-alert type="warning">{{ $message }}</x-alert></div>
@endsection
