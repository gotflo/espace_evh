<!doctype html>
<html lang="fr" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="x-apple-disable-message-reformatting">
  <meta name="color-scheme" content="light dark">
  <meta name="supported-color-schemes" content="light dark">
  <title>@yield('title')</title>
  {{--
    Couleurs pleines seulement (pas de degrade) : en mode sombre, Gmail fonce les fonds et eclaircit les textes
    ensemble ; un degrade, lui, reste fonce et rendait le texte illisible. Les cartes sont claires, texte fonce.
    Apple Mail et Outlook appliquent les couleurs sombres ci-dessous.
  --}}
  <style>
    a { color: #0d5f57; }
    @media (max-width: 620px) {
      .wrap { width: 100% !important; }
      .pad { padding-left: 20px !important; padding-right: 20px !important; }
      .h1 { font-size: 24px !important; }
      .stack { display: block !important; width: 100% !important; }
      .stack-pad { padding: 5px 0 !important; }
      .btn { width: 100% !important; }
      .btn a { display: block !important; }
      .kicker { display: none !important; }
    }
    @media (prefers-color-scheme: dark) {
      .c-page { background: #101514 !important; }
      .c-card { background: #1a2120 !important; }
      .c-foot { background: #151b1a !important; border-color: #2a3432 !important; }
      .c-tint { background: #233330 !important; border-color: #33403d !important; }
      .c-warm { background: #3a3220 !important; }
      .c-line { border-color: #33403d !important; }
      .c-h { color: #cdeee7 !important; }
      .c-t { color: #e8eeec !important; }
      .c-m { color: #aebbb8 !important; }
      .c-q { color: #f3e2b0 !important; }
      .c-a { color: #8fdccd !important; }
    }
    [data-ogsc] .c-h { color: #cdeee7 !important; }
    [data-ogsc] .c-t { color: #e8eeec !important; }
    [data-ogsc] .c-m { color: #aebbb8 !important; }
    [data-ogsc] .c-q { color: #f3e2b0 !important; }
    [data-ogsc] .c-a { color: #8fdccd !important; }
  </style>
</head>
<body class="c-page" style="margin:0; padding:0; background:#eef2f1; -webkit-text-size-adjust:100%;">
  {{-- Apercu affiche par la messagerie a cote du sujet --}}
  <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:#eef2f1;">@yield('preheader')&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;&#8203;&nbsp;</div>
  <table role="presentation" class="c-page" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#eef2f1" style="background:#eef2f1;">
    <tr>
      <td align="center" style="padding:24px 12px;">
        <table role="presentation" class="wrap" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px; max-width:600px;">
          {{-- En-tete : vert plein, texte blanc --}}
          <tr>
            <td bgcolor="#0d5f57" style="background:#0d5f57; border-radius:18px 18px 0 0; padding:20px 28px;" class="pad">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td width="48" valign="middle"><img src="{{ $assetUrl }}/logo-vh.png" width="44" height="44" alt="" style="display:block; border-radius:50%; background:#ffffff;"></td>
                  <td valign="middle" style="padding-left:12px; font-family:Arial,Helvetica,sans-serif;">
                    <div style="color:#ffffff; font-size:17px; font-weight:bold; line-height:1.2;">My vasesdhonneur</div>
                    <div style="color:#f6d55c; font-size:11px; letter-spacing:2px; text-transform:uppercase; font-weight:bold;">Chicoutimi</div>
                  </td>
                  <td align="right" valign="middle" class="kicker" style="font-family:Arial,Helvetica,sans-serif; color:#d7ebe7; font-size:12px;">@yield('kicker')</td>
                </tr>
              </table>
            </td>
          </tr>
          @if (config('mail.test_banner'))
          {{-- Courriel d'essai : bien visible, aucune action reelle attendue --}}
          <tr>
            <td bgcolor="#fff4cc" style="background:#fff4cc; border-left:6px solid #d9a400; padding:14px 28px; font-family:Arial,Helvetica,sans-serif; color:#5c4300; font-size:14px; line-height:1.45;">
              <strong style="font-size:15px; letter-spacing:1px;">TEST — COURRIEL D'ESSAI</strong><br>
              Ce message est un essai de la plateforme My vasesdhonneur, envoyé pour vérifier le fonctionnement des inscriptions. La personne ci-dessous est fictive : aucune action réelle n'est nécessaire.
            </td>
          </tr>
          @endif
          {{-- Corps --}}
          <tr>
            <td class="c-card pad" bgcolor="#ffffff" style="background:#ffffff; padding:30px 28px 26px; font-family:Arial,Helvetica,sans-serif; color:#1d2b29; font-size:15px; line-height:1.55;">
              <h1 class="h1 c-h" style="margin:0 0 12px; font-family:Georgia,'Times New Roman',serif; font-size:27px; line-height:1.25; color:#0d3f3a; font-weight:normal;">@yield('heading')</h1>
              @yield('content')
            </td>
          </tr>
          {{-- Pied : courriel automatique, pas de reponse --}}
          <tr>
            <td class="c-foot pad" bgcolor="#f7f9f8" style="background:#f7f9f8; border-top:1px solid #e3e9e7; border-radius:0 0 18px 18px; padding:18px 28px 22px; font-family:Arial,Helvetica,sans-serif; color:#5f6e6b; font-size:12px; line-height:1.6;">
              <span class="c-m">
                @yield('footer_note')
                Courriel automatique envoyé par My vasesdhonneur : merci de ne pas y répondre.
                @hasSection('outside_app')
                  <br>Église Vases d'Honneur Chicoutimi
                @else
                Une question ? <a href="{{ $appUrl }}/contact" class="c-a" style="color:#0d5f57; font-weight:bold;">Écrivez-nous dans l'application</a>.<br>
                Église Vases d'Honneur Chicoutimi · <a href="{{ $prefsUrl }}" class="c-a" style="color:#5f6e6b;">Gérer mes avis</a>
                @endif
              </span>
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
