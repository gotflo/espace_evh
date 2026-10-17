@extends('emails.layout')

@section('title', 'Nouveau membre : '.$memberName)
@section('preheader', $memberName.' a rejoint '.($tribe ? 'la tribu '.$tribe : 'votre tribu').' sur My vasesdhonneur.')
@section('kicker', 'Nouveau membre')
@section('heading', 'Un nouveau membre a rejoint votre tribu')

@section('content')
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">
    Bonjour{{ $patriarchName ? ' '.$patriarchName : '' }}, le compte de <strong>{{ $memberName }}</strong> vient d'être validé{{ $validatedBy ? ' par '.$validatedBy : '' }}{{ $validatedOn ? ' le '.$validatedOn : '' }}. Voici sa fiche.
  </p>

  {{-- Profil complet : une carte d'un seul tenant --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#f7faf9" style="margin:0 0 20px; background:#f7faf9; border:1px solid #e1e9e7; border-radius:16px;">
    <tr>
      <td style="padding:18px 18px 8px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          <tr>
            <td width="64" valign="middle">
              @if ($photoUrl)
                <img src="{{ $photoUrl }}" width="56" height="56" alt="" style="display:block; width:56px; height:56px; border-radius:50%; object-fit:cover; background:#dfeae7;">
              @else
                <div style="width:56px; height:56px; line-height:56px; border-radius:50%; background:#0d5f57; color:#ffffff; text-align:center; font-family:Arial,Helvetica,sans-serif; font-size:20px; font-weight:bold;">{{ $initials ?: '?' }}</div>
              @endif
            </td>
            <td valign="middle" style="padding-left:12px; font-family:Arial,Helvetica,sans-serif;">
              <div class="c-t" style="font-size:18px; font-weight:bold; color:#1d2b29; line-height:1.3; word-break:break-word;">{{ $memberName }}</div>
              <div class="c-m" style="font-size:13px; color:#5f6e6b; margin-top:2px;">{{ $tribe ? 'Tribu '.$tribe.' · ' : '' }}Compte validé</div>
            </td>
          </tr>
        </table>
      </td>
    </tr>
    <tr>
      <td style="padding:6px 18px 14px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          @foreach ($details as $row)
            <tr>
              <td class="c-line" style="padding:9px 0; border-top:1px solid #e1e9e7; font-family:Arial,Helvetica,sans-serif;">
                <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:0.8px; font-weight:bold;">{{ $row['label'] }}</div>
                <div class="c-t" style="font-size:15px; color:#1d2b29; margin-top:2px; word-break:break-word;">{{ $row['value'] }}</div>
              </td>
            </tr>
          @endforeach
        </table>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Voir sa fiche', 'bg' => '#0d5f57'])

  <p class="c-m" style="margin:16px 0 0; font-size:13px; color:#5f6e6b;">
    Pensez à lui souhaiter la bienvenue.
  </p>
@endsection
