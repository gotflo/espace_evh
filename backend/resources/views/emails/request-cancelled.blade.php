@extends('emails.layout')

@section('title', 'Demande annulée : '.$memberName)
@section('preheader', $memberName.' a annulé '.$what.'.')
@section('kicker', 'Pour information')
@section('heading', 'Demande annulée')

@section('content')
  <p class="c-t" style="margin:0 0 16px; color:#44524f;">Bonjour{{ $firstName ? ' '.$firstName : '' }},</p>
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">
    <strong>{{ $memberName }}</strong> a annulé {{ $what }}. Vous n’avez plus rien à faire pour cette demande.
  </p>

  @if ($detail)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#f7faf9" style="margin:0 0 18px; background:#f7faf9; border:1px solid #e1e9e7; border-radius:14px;">
      <tr>
        <td style="padding:16px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
          <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold;">Demande annulée</div>
          <div class="c-t" style="margin-top:2px; color:#1d2b29; word-break:break-word;">{{ $detail }}</div>
        </td>
      </tr>
    </table>
  @endif
@endsection
