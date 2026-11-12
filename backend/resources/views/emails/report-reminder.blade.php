@extends('emails.layout')

@php($many = ! empty($items) && count($items) > 1)
@section('title', ($stage === 'last' ? 'Rappel : ' : '').($many ? count($items).' rapports ' : 'rapport ').$deMonth.' à transmettre')
@section('preheader', ($many ? $scopeLabel : ucfirst($scopeLabel)).' · un questionnaire guidé, quelques minutes suffisent.')
@section('kicker', 'Rapport mensuel')
@section('heading', $many
    ? ($stage === 'last' ? 'Vos rapports '.$deMonth.' ne sont pas encore tous envoyés' : 'Vos rapports '.$deMonth.' sont attendus')
    : ($stage === 'last' ? 'Votre rapport '.$deMonth.' n\'est pas encore envoyé' : 'Votre rapport '.$deMonth.' est attendu'))

@section('content')
  <p class="c-t" style="margin:0 0 16px; color:#44524f;">Bonjour {{ $firstName }},</p>
  @if ($many)
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">
    @if ($stage === 'last')
      Ces rapports <strong>{{ $deMonth }}</strong> n'ont pas encore été transmis. Ils peuvent être envoyés jusqu'au <strong>{{ $closesOn }}</strong>.
    @else
      Les pasteurs attendent vos rapports <strong>{{ $deMonth }}</strong>, un pour chacun de vos groupes. Un questionnaire guidé : quelques minutes suffisent pour chacun.
    @endif
  </p>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="{{ $stage === 'last' ? 'c-warm' : 'c-tint' }}" bgcolor="{{ $stage === 'last' ? '#fff6dc' : '#eef6f4' }}"
    style="margin:0 0 18px; background:{{ $stage === 'last' ? '#fff6dc' : '#eef6f4' }}; border-radius:14px;">
    @foreach ($items as $item)
    <tr>
      <td style="padding:10px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <strong class="c-h" style="color:#0d3f3a;">{{ $item['label'] }}</strong>
        <span class="c-t" style="color:#44524f;"> · <a href="{{ $item['url'] }}" target="_blank" style="color:#0d5f57; font-weight:bold;">remplir</a></span>
      </td>
    </tr>
    @endforeach
    <tr>
      <td style="padding:4px 18px 14px; font-family:Arial,Helvetica,sans-serif; font-size:13px;">
        <span class="c-t" style="color:#44524f;">Dernier jour pour les envoyer : {{ $closesOn }}. Chaque rapport envoyé n'est plus rappelé.</span>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Voir mes rapports', 'bg' => $stage === 'last' ? '#a8621a' : '#0d5f57'])
  @else
  <p class="c-t" style="margin:0 0 18px; color:#44524f;">
    @if ($stage === 'last')
      Le rapport <strong>{{ $deMonth }}</strong> {{ $scopeOf }} <strong>{{ $scopeLabel }}</strong> n'a pas encore été transmis. Il peut être envoyé jusqu'au <strong>{{ $closesOn }}</strong>.
    @else
      Le rapport <strong>{{ $deMonth }}</strong> {{ $scopeOf }} <strong>{{ $scopeLabel }}</strong> est attendu par les pasteurs. Un questionnaire guidé : quelques minutes suffisent.
    @endif
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" class="{{ $stage === 'last' ? 'c-warm' : 'c-tint' }}" bgcolor="{{ $stage === 'last' ? '#fff6dc' : '#eef6f4' }}"
    style="margin:0 0 18px; background:{{ $stage === 'last' ? '#fff6dc' : '#eef6f4' }}; border-radius:14px;">
    <tr>
      <td style="padding:14px 18px; font-family:Arial,Helvetica,sans-serif; font-size:14px;">
        <strong class="c-h" style="color:#0d3f3a;">{{ ucfirst($scopeLabel) }}</strong><span class="c-t" style="color:#44524f;"> · rapport {{ $deMonth }}</span><br>
        <span class="c-t" style="color:#44524f;">Dernier jour pour l'envoyer : {{ $closesOn }}. Une fois envoyé, plus aucun rappel.</span>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $url, 'label' => 'Remplir le rapport', 'bg' => $stage === 'last' ? '#a8621a' : '#0d5f57'])
  @endif
@endsection
