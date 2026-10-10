@extends('emails.layout')

@section('title', 'Inscription au service '.$department)
@section('preheader', $memberName.' souhaite rejoindre le service '.$department.'.')
@section('kicker', 'Service')
@section('heading', 'Nouvelle inscription au service '.$department)

@section('content')
  <p class="c-t" style="margin:0 0 16px; color:#44524f;">Bonjour {{ $firstName }},</p>
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">
    <strong>{{ $memberName }}</strong> souhaite rejoindre le service <strong>{{ $department }}</strong>. Son inscription attend votre validation.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#eef6f4" style="margin:0 0 18px; background:#eef6f4; border-radius:14px;">
    <tr>
      <td style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <strong class="c-h" style="color:#0d3f3a;">{{ $memberName }}</strong>@if ($tribe)<span class="c-t" style="color:#44524f;"> · tribu {{ $tribe }}</span>@endif<br>
        <span class="c-t" style="color:#44524f;">Demande envoyée le {{ $requestedOn }}.</span>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Valider ou refuser', 'bg' => '#0d5f57'])
@endsection
