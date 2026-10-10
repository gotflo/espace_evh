@extends('emails.layout')

@section('title', 'Votre demande d\'inscription')
@section('preheader', 'Votre demande d\'inscription n\'a pas été acceptée.')
@section('kicker', 'Inscription')
@section('outside_app', '1')
@section('heading', 'Votre demande d\'inscription')

@section('content')
  <p class="c-t" style="margin:0 0 16px; color:#44524f;">Bonjour{{ $firstName ? ' '.$firstName : '' }},</p>
  <p class="c-t" style="margin:0 0 16px; color:#44524f;">Votre demande pour rejoindre <strong>My vasesdhonneur</strong> n'a pas été acceptée.</p>
  <p class="c-t" style="margin:0; color:#44524f;">S'il s'agit d'une erreur, adressez-vous à un responsable de l'église.</p>
@endsection
