@extends('emails.layout')

@section('title', 'Votre compte est validé')
@section('preheader', 'Votre espace My vasesdhonneur est ouvert.')
@section('kicker', 'Bienvenue')
@section('heading', 'Bienvenue'.($firstName ? ', '.$firstName : '').' !')

@section('content')
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">Le pasteur a validé votre compte : votre espace <strong>My vasesdhonneur</strong> est ouvert.</p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#eef6f4" style="margin:0 0 20px; background:#eef6f4; border-radius:14px;">
    <tr>
      <td style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <strong class="c-h" style="color:#0d3f3a;">Ce qui vous attend</strong><br>
        <span class="c-t" style="color:#44524f;">Le verset du jour, votre devoir, votre fiche du mois et les annonces de l'église.</span>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Ouvrir mon espace'])

  @if (! empty($videoUrl))
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#eef6f4" style="margin:22px 0 0; background:#eef6f4; border-radius:14px;">
    <tr>
      <td style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <strong class="c-h" style="color:#0d3f3a;">Le guide de l'application en vidéo</strong><br>
        <span class="c-t" style="color:#44524f;">Regardez la présentation de l'application pour bien commencer.</span>
        @include('emails.partials.button', ['url' => $videoUrl, 'label' => 'Regarder la vidéo', 'bg' => '#ffffff', 'fg' => '#0d5f57', 'border' => 'border:2px solid #0d5f57;', 'margin' => '12px 0 2px'])
      </td>
    </tr>
  </table>
  @endif
@endsection
