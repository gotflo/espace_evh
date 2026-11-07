@extends('emails.layout')

@php
  [$mailHeading, $mailPreheader] = match ($outcome) {
      'approved' => ['Changement de tribu validé', $memberName.' rejoint la tribu '.$to.'.'],
      'rejected' => ['Changement de tribu non accepté', $memberName.' reste dans la tribu '.($from ?: 'actuelle').'.'],
      default => ['Changement de tribu demandé', $memberName.' demande à rejoindre la tribu '.$to.'.'],
  };
@endphp

@section('title', $mailHeading.' : '.$memberName)
@section('preheader', $mailPreheader)
@section('kicker', 'Pour information')
@section('heading', $mailHeading)

@section('content')
  <p class="c-t" style="margin:0 0 16px; color:#44524f;">Bonjour{{ $firstName ? ' '.$firstName : '' }},</p>

  @if ($outcome === 'approved')
    <p class="c-t" style="margin:0 0 18px; color:#44524f;">
      Le changement de tribu de <strong>{{ $memberName }}</strong> est validé{{ $by ? ' (dernière validation : '.$by.')' : '' }}.
      {{ $side === 'to' ? 'Il ou elle fait désormais partie de votre tribu : pensez à lui souhaiter la bienvenue.' : 'Il ou elle quitte votre tribu pour la tribu '.$to.'.' }}
    </p>
  @elseif ($outcome === 'rejected')
    <p class="c-t" style="margin:0 0 18px; color:#44524f;">
      La demande de <strong>{{ $memberName }}</strong> pour rejoindre la tribu {{ $to }} n’a pas été acceptée{{ $by ? ' ('.$by.')' : '' }}. Il ou elle reste dans {{ $from ? 'la tribu '.$from : 'sa tribu actuelle' }}.
    </p>
  @else
    <p class="c-t" style="margin:0 0 18px; color:#44524f;">
      <strong>{{ $memberName }}</strong> demande à changer de tribu. {{ $deciders ? 'La décision revient à '.$deciders.' ;' : 'La décision revient aux responsables ;' }} vous serez informé(e) du résultat.
    </p>
  @endif

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#f7faf9" style="margin:0 0 18px; background:#f7faf9; border:1px solid #e1e9e7; border-radius:14px;">
    <tr>
      <td style="padding:16px 18px {{ $reason ? '6px' : '16px' }}; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold;">{{ $outcome === 'requested' ? 'Changement demandé' : 'Changement de tribu' }}</div>
        <div class="c-t" style="margin-top:2px; color:#1d2b29; word-break:break-word;">{{ $from ? 'Tribu '.$from : 'Aucune tribu' }} &rarr; <strong>Tribu {{ $to }}</strong></div>
      </td>
    </tr>
    @if ($reason)
      <tr>
        <td style="padding:10px 18px 14px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
          <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold;">Motif</div>
          <div class="c-q" style="margin-top:4px; padding-left:10px; border-left:3px solid #e2c56b; color:#5a4a14; white-space:pre-line; word-break:break-word;">«&nbsp;{{ $reason }}&nbsp;»</div>
        </td>
      </tr>
    @endif
  </table>

  @if ($url)
    @include('emails.partials.button', ['url' => $url, 'label' => 'Voir sa fiche', 'bg' => '#0d5f57'])
  @endif
@endsection
