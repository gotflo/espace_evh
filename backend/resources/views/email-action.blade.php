<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex, nofollow">
  <meta name="referrer" content="no-referrer">
  <title>{{ $title }} · My vasesdhonneur</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px; background: #0d5f57;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; color: #17211f; }
    .card { width: 100%; max-width: 400px; background: #fff; border-radius: 22px; padding: 30px 26px; text-align: center; box-shadow: 0 24px 60px rgba(0,0,0,.3); }
    .brand { display: flex; align-items: center; justify-content: center; gap: 10px; margin-bottom: 18px; font-weight: 700; color: #0d3f3a; }
    .brand img { width: 40px; height: 40px; border-radius: 50%; }
    .spinner { width: 30px; height: 30px; margin: 6px auto 14px; border: 3px solid #cfe3df; border-top-color: #0d5f57; border-radius: 50%; animation: s .8s linear infinite; }
    @keyframes s { to { transform: rotate(360deg); } }
    h1 { font-family: Georgia, serif; font-weight: normal; font-size: 23px; margin: 0 0 8px; color: #0d3f3a; }
    p { color: #44524f; line-height: 1.5; margin: 0; }
    button { margin-top: 16px; width: 100%; border: 0; border-radius: 999px; padding: 14px; font: inherit; font-weight: 700; background: #0d5f57; color: #fff; cursor: pointer; }
  </style>
</head>
<body>
  {{-- Rien ne part sans un appui sur le bouton : un antivirus qui ouvre le lien ne decide jamais a la place du pasteur. --}}
  <main class="card">
    <div class="brand"><img src="{{ $assetUrl }}/logo-vh.png" alt=""><span>My vasesdhonneur</span></div>
    <h1>{{ $title }}</h1>
    <p>{{ $message ?? '' }} L'application s'ouvrira ensuite pour vous confirmer le résultat.</p>
    <form method="post" action="{{ $action }}">
      <button type="submit">{{ $confirm }}</button>
    </form>
  </main>
</body>
</html>
