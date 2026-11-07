@extends('emails.layout')

@section('title', 'Changement de tribu : '.$memberName)
@section('preheader', $memberName.' demande à rejoindre la tribu '.$to.'. Sa demande attend votre validation.')
@section('kicker', 'Décision à prendre')
@section('heading', 'Changement de tribu à valider')

@section('content')
  <p class="c-t" style="margin:0 0 16px; color:#44524f;">Bonjour{{ $firstName ? ' '.$firstName : '' }},</p>
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">
    <strong>{{ $memberName }}</strong> demande à changer de tribu. Sa demande attend votre validation.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#f7faf9" style="margin:0 0 18px; background:#f7faf9; border:1px solid #e1e9e7; border-radius:14px;">
    <tr>
      <td style="padding:16px 18px 6px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold;">Membre</div>
        <div class="c-t" style="font-size:17px; font-weight:bold; color:#1d2b29; word-break:break-word;">{{ $memberName }}</div>
        <div class="c-m" style="color:#5f6e6b;">Demande envoyée le {{ $requestedOn }}</div>
      </td>
    </tr>
    <tr>
      <td style="padding:10px 18px 6px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold;">Changement demandé</div>
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
    @else
      <tr><td style="padding:0 0 8px;"></td></tr>
    @endif
  </table>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Valider ou refuser', 'bg' => '#0d5f57'])

  <p class="c-m" style="margin:16px 0 0; font-size:13px; color:#5f6e6b;">
    Le changement est appliqué quand les responsables des deux tribus l'ont validé. Vous le retrouvez dans l'application, onglet Validations.
  </p>
@endsection
