@extends('emails.layout')

@php
  [$mailTitle, $mailPreheader, $mailHeading] = match ($outcome) {
      'approved' => ['Bienvenue dans la tribu '.$to, 'Votre changement de tribu est validé.', 'Bienvenue dans la tribu '.$to.' !'],
      'rejected' => ['Votre demande de changement de tribu', 'Votre demande pour rejoindre la tribu '.$to.' n’a pas été acceptée.', 'Votre demande n’a pas été acceptée'],
      default => ['Changement de tribu : une étape validée', 'Il reste la validation des responsables de la tribu '.$waiting.'.', 'Une étape de votre demande est validée'],
  };
@endphp

@section('title', $mailTitle)
@section('preheader', $mailPreheader)
@section('kicker', 'Tribu')
@section('heading', $mailHeading)

@section('content')
  <p class="c-t" style="margin:0 0 16px; color:#44524f;">Bonjour{{ $firstName ? ' '.$firstName : '' }},</p>

  @if ($outcome === 'approved')
    <p class="c-t" style="margin:0 0 18px; color:#44524f;">
      Votre changement de tribu est validé{{ $by ? ' (dernière validation : '.$by.')' : '' }}. Vous faites désormais partie de la tribu <strong>{{ $to }}</strong>.
    </p>
  @elseif ($outcome === 'rejected')
    <p class="c-t" style="margin:0 0 18px; color:#44524f;">
      Votre demande pour rejoindre la tribu <strong>{{ $to }}</strong> n’a pas été acceptée{{ $by ? ' par '.$by : '' }}. Vous restez dans {{ $from ? 'la tribu '.$from : 'votre tribu actuelle' }}.
    </p>
  @else
    <p class="c-t" style="margin:0 0 18px; color:#44524f;">
      {{ $by ?: 'Un responsable' }} a validé votre demande{{ $validated ? ' pour la tribu '.$validated : '' }}. Il reste la validation des responsables de la tribu <strong>{{ $waiting }}</strong> : vous serez prévenu(e) dès leur réponse.
    </p>
  @endif

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#eef6f4" style="margin:0 0 18px; background:#eef6f4; border-radius:14px;">
    <tr>
      <td style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold;">Votre demande</div>
        <div class="c-t" style="margin-top:2px; color:#1d2b29; word-break:break-word;">{{ $from ? 'Tribu '.$from : 'Aucune tribu' }} &rarr; <strong>Tribu {{ $to }}</strong></div>
        @if ($comment)
          <div class="c-q" style="margin-top:10px; padding-left:10px; border-left:3px solid #e2c56b; color:#5a4a14; white-space:pre-line; word-break:break-word;">«&nbsp;{{ $comment }}&nbsp;»</div>
        @endif
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Voir ma demande'])
@endsection
