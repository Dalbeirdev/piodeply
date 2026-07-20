{{-- Bridges Livewire full-page components onto the classic marketing
     layout: Livewire hands us $slot, the layout wants a content section. --}}
@extends('marketing.layout')

@section('title', ($title ?? 'PioDeploy'))

@section('content')
    {{ $slot }}
@endsection
