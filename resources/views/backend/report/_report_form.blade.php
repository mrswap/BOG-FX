{!! Form::open(['route' => $routeName, 'method' => 'post', 'id' => $linkId . '-form']) !!}
@foreach($params as $key => $value)
    <input type="hidden" name="{{ $key }}" value="{{ $value }}" />
@endforeach
<a id="{{ $linkId }}" href="javascript:void(0)">{{ $text }}</a>
{!! Form::close() !!}
