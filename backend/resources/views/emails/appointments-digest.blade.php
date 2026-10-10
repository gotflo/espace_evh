@extends('emails.layout')

@section('title', 'Vos rendez-vous du '.$dayLabel)
@section('preheader', count($items).' rendez-vous · '.$range.($update ? ' · liste mise à jour' : ''))
@section('kicker', $update ? 'Liste mise à jour' : 'Rendez-vous')
@section('heading', ($update ? 'Liste mise à jour : vos rendez-vous ' : 'Vos rendez-vous ').$dayTitle)

@section('content')
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">
    @if ($update)
      La liste a changé depuis le premier courriel. Voici les personnes attendues, dans l'ordre de passage.
    @elseif ($reason === 'full')
      Toutes les places sont prises. Voici les personnes attendues, dans l'ordre de passage.
    @else
      Les rendez-vous commencent dans 15 minutes. Voici les personnes attendues, dans l'ordre de passage.
    @endif
  </p>

  {{-- Resume --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#eef6f4" style="margin:0 0 20px; background:#eef6f4; border-radius:14px;">
    <tr>
      <td class="stack" style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif;">
        <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold;">Horaire</div>
        <div class="c-h" style="font-size:17px; color:#0d3f3a; font-weight:bold;">{{ $dayLabel }} · {{ $range }}</div>
      </td>
      <td class="stack" style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif;">
        <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold;">Rendez-vous</div>
        <div class="c-h" style="font-size:17px; color:#0d3f3a; font-weight:bold;">{{ count($items) }} sur {{ $total }} · {{ $minutes }} min chacun</div>
      </td>
    </tr>
  </table>

  {{-- Une carte d'un seul tenant par personne, dans l'ordre de passage --}}
  @foreach ($items as $item)
    @php($urgent = $item['urgency'] === 'urgent')
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="{{ $urgent ? 'c-warm' : 'c-tint' }}"
      bgcolor="{{ $urgent ? '#fff1f0' : '#f7faf9' }}" style="margin:0 0 10px; background:{{ $urgent ? '#fff1f0' : '#f7faf9' }}; border:1px solid {{ $urgent ? '#f2c4c0' : '#e1e9e7' }}; border-radius:14px;">
      <tr>
        <td width="70" valign="top" style="padding:15px 0 15px 16px; font-family:Georgia,serif; font-size:20px; font-weight:bold; color:#0d5f57;" class="c-h">{{ $item['time'] }}</td>
        <td valign="top" style="padding:15px 16px 15px 10px; font-family:Arial,Helvetica,sans-serif;">
          <div class="c-t" style="font-size:16px; font-weight:bold; color:#1d2b29; word-break:break-word;">{{ $item['name'] }}</div>
          <div class="c-m" style="font-size:13px; color:#5f6e6b; margin-top:2px;">
            {{ $item['tribe'] ? 'Tribu '.$item['tribe'] : 'Sans tribu' }}
            @if ($item['phone']) · <a href="tel:{{ $item['phone'] }}" class="c-a" style="color:#0d5f57; text-decoration:none;">{{ $item['phone'] }}</a>@endif
          </div>
          @if ($item['urgency'] !== 'normal')
            <span style="display:inline-block; margin-top:8px; padding:3px 10px; border-radius:999px; font-size:12px; font-weight:bold; background:{{ $urgent ? '#c62828' : '#f6d55c' }}; color:{{ $urgent ? '#ffffff' : '#3b2d00' }};">Priorité {{ mb_strtolower($item['urgency_label']) }}</span>
          @endif
          @if ($item['message'])
            <div class="c-q" style="margin-top:10px; padding-left:10px; border-left:3px solid #e2c56b; font-size:14px; color:#5a4a14; white-space:pre-line; word-break:break-word;">«&nbsp;{{ $item['message'] }}&nbsp;»</div>
          @endif
        </td>
      </tr>
    </table>
  @endforeach

  @if ($free > 0)
    <p class="c-m" style="margin:6px 0 0; font-size:13px; color:#5f6e6b;">{{ $free }} créneau{{ $free > 1 ? 'x' : '' }} libre{{ $free > 1 ? 's' : '' }} : une réservation de dernière minute apparaîtra dans l'application.</p>
  @endif

  @include('emails.partials.button', ['url' => $adminUrl, 'label' => 'Voir dans l\'application', 'margin' => '22px 0 4px'])
@endsection

@section('footer_note')
  Envoyé une fois par jour de rendez-vous. Les messages des membres sont confidentiels.<br>
@endsection
