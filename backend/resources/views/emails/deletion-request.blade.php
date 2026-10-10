@extends('emails.layout')

@section('title', 'Demande de suppression : '.$memberName)
@section('preheader', 'Demandée par '.$requestedBy.' · '.\Illuminate\Support\Str::limit($reason, 80))
@section('kicker', 'Décision à prendre')
@section('heading', 'Supprimer le compte de '.$memberName.' ?')

@section('content')
  <p class="c-t" style="margin:0 0 18px; color:#44524f;"><strong>{{ $requestedBy }}</strong> demande la suppression de ce compte. Un appui sur un bouton suffit : la décision s'applique aussitôt, puis l'application s'ouvre pour vous la confirmer.</p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="c-tint" bgcolor="#f7faf9" style="margin:0 0 18px; background:#f7faf9; border:1px solid #e1e9e7; border-radius:14px;">
    <tr>
      <td style="padding:16px 18px 6px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold;">Membre</div>
        <div class="c-t" style="font-size:17px; font-weight:bold; color:#1d2b29; word-break:break-word;">{{ $memberName }}</div>
        <div class="c-m" style="color:#5f6e6b;">{{ $tribe ? 'Tribu '.$tribe : 'Sans tribu' }}@if ($phone) · {{ $phone }}@endif</div>
      </td>
    </tr>
    <tr>
      <td style="padding:10px 18px 14px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold;">Motif</div>
        <div class="c-q" style="margin-top:4px; padding-left:10px; border-left:3px solid #e2c56b; color:#5a4a14; white-space:pre-line; word-break:break-word;">«&nbsp;{{ $reason }}&nbsp;»</div>
      </td>
    </tr>
    @if (! empty($impact))
      <tr>
        <td style="padding:0 18px 16px; font-family:Arial,Helvetica,sans-serif; font-size:13px;">
          <div class="c-m" style="font-size:12px; color:#5f6e6b; text-transform:uppercase; letter-spacing:1px; font-weight:bold; margin-bottom:4px;">Ce qui sera effacé</div>
          @foreach (array_slice($impact, 0, 8) as $line)
            <div class="c-t" style="color:#44524f;">• {{ $line['count'] }} · {{ $line['label'] }}</div>
          @endforeach
        </td>
      </tr>
    @endif
  </table>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td class="stack stack-pad" style="padding:4px 6px 4px 0;" width="50%">
        @include('emails.partials.button', ['url' => $approveUrl, 'label' => 'Accepter la suppression', 'bg' => '#c62828'])
      </td>
      <td class="stack stack-pad" style="padding:4px 0 4px 6px;" width="50%">
        @include('emails.partials.button', ['url' => $rejectUrl, 'label' => 'Rejeter la demande', 'bg' => '#0d5f57'])
      </td>
    </tr>
  </table>

  <p class="c-m" style="margin:16px 0 0; font-size:13px; color:#5f6e6b;">
    Accepter efface définitivement le compte ; son numéro ne pourra plus servir à une nouvelle inscription.
    Rejeter conserve le compte et prévient {{ $requestedBy }}. Liens valables {{ $days }} jours, réservés à vous.
  </p>
@endsection
