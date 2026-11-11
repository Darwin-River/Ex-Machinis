@extends('layouts.app')
@section('pageTitle', 'Notification')
@section('content')
    <x-page-header title="Notification" />
    <section class="section">
        @include('_flash_message')
    </section>
@endsection
