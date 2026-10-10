@extends('emails.layout')

@section('title', 'Votre espace vous attend')
@section('preheader', 'Votre dernière visite remonte à '.$days.' jours.')
@section('kicker', 'My vasesdhonneur')
@section('heading', 'Vous nous manquez'.($firstName ? ', '.$firstName : '').' !')

@section('content')
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">Vous ne vous êtes pas {{ $connected }} à My vasesdhonneur depuis <strong>{{ $days }} jours</strong>.</p>

  @if (! empty($items))
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#eef6f4" style="margin:0 0 20px; background:#eef6f4; border-radius:14px;">
      <tr>
        <td style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
          <strong class="c-h" style="color:#0d3f3a;">Depuis votre dernière visite</strong>
          @foreach ($items as $item)
            <div class="c-t" style="color:#44524f; margin-top:6px;">• {{ $item }}</div>
          @endforeach
        </td>
      </tr>
    </table>
  @endif

  @include('emails.partials.button', ['url' => $url, 'label' => 'Ouvrir mon espace'])
@endsection
