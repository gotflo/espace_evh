@extends('emails.layout')

@section('title', 'Nouveau devoir : '.$title)
@section('preheader', ($speaker ? $speaker.' · ' : '').'Écoutez, puis écrivez votre résumé et vos résolutions.')
@section('kicker', 'Mon devoir (DS)')
@section('heading', 'Un nouveau devoir vous attend')

@section('content')
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">Bonjour {{ $firstName }},</p>

  {{-- Carte claire, texte fonce : lisible en mode clair comme en mode sombre --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#eef6f4"
    style="margin:0 0 20px; background:#eef6f4; border-left:4px solid #0d5f57; border-radius:14px;">
    <tr>
      <td style="padding:18px 20px; font-family:Arial,Helvetica,sans-serif;">
        <span style="display:inline-block; padding:3px 10px; border-radius:999px; background:#0d5f57; color:#ffffff; font-size:12px; font-weight:bold;">{{ $typeLabel }}</span>
        <div class="c-h" style="margin-top:10px; font-family:Georgia,serif; font-size:22px; line-height:1.3; color:#0d3f3a; word-break:break-word;">{{ $title }}</div>
        @if ($speaker)
          <div class="c-m" style="margin-top:4px; color:#4f5f5c; font-size:14px;">{{ $speaker }}</div>
        @endif
      </td>
    </tr>
  </table>

  @if ($instructions)
    <p class="c-t" style="margin:0 0 16px; color:#44524f; white-space:pre-line; word-break:break-word;">{{ $instructions }}</p>
  @endif

  <p class="c-t" style="margin:0 0 4px; color:#44524f;">Prenez le temps de {{ $typeVerb }} en entier, puis écrivez votre <strong>résumé</strong> et vos <strong>actions et résolutions</strong>.</p>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Ouvrir mon devoir', 'margin' => '18px 0 4px'])
@endsection
