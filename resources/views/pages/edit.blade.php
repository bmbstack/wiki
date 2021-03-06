@extends('base')

@section('head')
    <script src="/libs/tinymce/tinymce.min.js?ver=4.3.7"></script>
@stop

@section('body-class', 'flexbox')

@section('content')

    <div class="flex-fill flex">
        <form action="{{$page->getUrl()}}" data-page-id="{{ $page->id }}" method="POST" class="flex flex-fill">
            <input type="hidden" name="_method" value="PUT">
            @include('pages/form', ['model' => $page])
        </form>
    </div>
    @include('partials/image-manager', ['imageType' => 'gallery', 'uploaded_to' => $page->id])

@stop