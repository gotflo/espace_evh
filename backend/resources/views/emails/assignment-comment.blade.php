@extends('emails.layout')

@section('title', $pastor.' a commenté votre devoir')
@section('preheader', 'Un commentaire vous attend sur votre devoir « '.$title.' ».')
@section('kicker', 'Mon devoir (DS)')
@section('heading', 'Un commentaire sur votre devoir')

@section('content')
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">Bonjour {{ $firstName }},</p>

  <p class="c-t" style="margin:0 0 16px; color:#44524f;"><strong>{{ $pastor }}</strong> a lu votre devoir et vous a laissé un commentaire.</p>

  {{-- Carte claire, texte fonce : lisible en mode clair comme en mode sombre --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#eef6f4"
    style="margin:0 0 20px; background:#eef6f4; border-left:4px solid #0d5f57; border-radius:14px;">
    <tr>
      <td style="padding:16px 20px; font-family:Arial,Helvetica,sans-serif;">
        <div class="c-m" style="color:#4f5f5c; font-size:13px;">Votre devoir</div>
        <div class="c-h" style="margin-top:4px; font-family:Georgia,serif; font-size:20px; line-height:1.3; color:#0d3f3a; word-break:break-word;">{{ $title }}</div>
      </td>
    </tr>
  </table>

  <p class="c-t" style="margin:0 0 4px; color:#44524f;">Pour garder vos échanges confidentiels, le commentaire se lit dans l'application.</p>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Lire le commentaire', 'margin' => '18px 0 4px'])
@endsection
