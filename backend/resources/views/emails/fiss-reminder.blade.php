@extends('emails.layout')

@section('title', $stage === 'last' ? 'Rappel : votre fiche '.$deMonth : 'Votre fiche de santé spirituelle '.$deMonth)
@section('preheader', $stage === 'last' ? 'Plus que '.$daysLeft.' jour'.($daysLeft > 1 ? 's' : '').' pour la remplir.' : 'Quelques minutes suffisent pour la remplir.')
@section('kicker', 'Ma fiche (FISS)')
@section('heading', $stage === 'last' ? 'Votre fiche '.$deMonth.' vous attend' : 'C\'est le moment de remplir votre fiche')

@section('content')
  <p class="c-t" style="margin:0 0 16px; color:#44524f;">Bonjour {{ $firstName }},</p>

  @if ($stage === 'last')
    <p class="c-t" style="margin:0 0 18px; color:#44524f;">Votre fiche de santé spirituelle (FISS) <strong>{{ $deMonth }}</strong> n'est pas encore remplie. Il reste
      @if ($daysLeft <= 0) aujourd'hui seulement @else <strong>{{ $daysLeft }} jour{{ $daysLeft > 1 ? 's' : '' }}</strong>@endif : prenez quelques minutes pour la remplir.</p>
  @else
    <p class="c-t" style="margin:0 0 18px; color:#44524f;">Votre fiche de santé spirituelle (FISS) <strong>{{ $deMonth }}</strong> n'est pas encore remplie. Quelques minutes suffisent : elle aide vos responsables à mieux vous accompagner.</p>
  @endif

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="{{ $stage === 'last' ? 'c-warm' : 'c-tint' }}" bgcolor="{{ $stage === 'last' ? '#fff6dc' : '#eef6f4' }}"
    style="margin:0 0 18px; background:{{ $stage === 'last' ? '#fff6dc' : '#eef6f4' }}; border-radius:14px;">
    <tr>
      <td style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <strong class="c-h" style="color:#0d3f3a;">À remplir avant la fin {{ $deMonth }}</strong><br>
        <span class="c-t" style="color:#44524f;">Une fois envoyée, la fiche est verrouillée et vous ne recevez plus de rappel.</span>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Remplir ma fiche', 'bg' => $stage === 'last' ? '#a8621a' : '#0d5f57'])
@endsection
