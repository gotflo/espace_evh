@extends('emails.layout')

@section('title', ($stage === 'last' ? 'Rappel : ' : '').'rapport '.$deMonth.' à transmettre')
@section('preheader', ucfirst($scopeLabel).' · un questionnaire guidé, quelques minutes suffisent.')
@section('kicker', 'Rapport mensuel')
@section('heading', $stage === 'last' ? 'Votre rapport '.$deMonth.' n\'est pas encore envoyé' : 'Votre rapport '.$deMonth.' est attendu')

@section('content')
  <p class="c-t" style="margin:0 0 16px; color:#44524f;">Bonjour {{ $firstName }},</p>
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">
    @if ($stage === 'last')
      Le rapport <strong>{{ $deMonth }}</strong> {{ $scopeOf }} <strong>{{ $scopeLabel }}</strong> n'a pas encore été transmis. Il peut être envoyé jusqu'au <strong>{{ $closesOn }}</strong>.
    @else
      Le rapport <strong>{{ $deMonth }}</strong> {{ $scopeOf }} <strong>{{ $scopeLabel }}</strong> est attendu par les pasteurs. Un questionnaire guidé : quelques minutes suffisent.
    @endif
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="{{ $stage === 'last' ? 'c-warm' : 'c-tint' }}" bgcolor="{{ $stage === 'last' ? '#fff6dc' : '#eef6f4' }}"
    style="margin:0 0 18px; background:{{ $stage === 'last' ? '#fff6dc' : '#eef6f4' }}; border-radius:14px;">
    <tr>
      <td style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <strong class="c-h" style="color:#0d3f3a;">{{ ucfirst($scopeLabel) }}</strong><span class="c-t" style="color:#44524f;"> · rapport {{ $deMonth }}</span><br>
        <span class="c-t" style="color:#44524f;">Dernier jour pour l'envoyer : {{ $closesOn }}. Une fois envoyé, plus aucun rappel.</span>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Remplir le rapport', 'bg' => $stage === 'last' ? '#a8621a' : '#0d5f57'])
@endsection
