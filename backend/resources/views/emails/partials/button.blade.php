{{-- Bouton a couleur pleine, lisible en mode clair et sombre (Outlook compris) --}}
@php($bg = $bg ?? '#0d5f57')
@php($fg = $fg ?? '#ffffff')
<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="btn" style="margin:{{ $margin ?? '6px 0' }};">
  <tr>
    <td align="center" bgcolor="{{ $bg }}" style="border-radius:999px; background:{{ $bg }}; {{ $border ?? '' }}">
      <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:14px 28px; font-family:Arial,Helvetica,sans-serif; font-size:15px; font-weight:bold; color:{{ $fg }}; text-decoration:none; border-radius:999px;">{{ $label }}</a>
    </td>
  </tr>
</table>
